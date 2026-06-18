# Analýza požadavku: priority + serialGroup

## Zadání

Záznamy v jedné frontě (např. `transcribe`) sdílejí stejnou `serialGroup` a mají se
odbavovat striktně jeden po druhém (sériově), i přes více serverů (2 servery, na každém
jeden konzument). Záznamy ale mají různé priority a je potřeba, aby **priorita měla
přednost**: nejdřív se mají odbavit všechny s prioritou 1, teprve pak s prioritou 2.

Cílová sémantika (potvrzeno v diskuzi):

- **serialGroup = vzájemné vyloučení** (sériovost) - z jedné skupiny běží vždy max. jeden
  job současně, napříč všemi konzumenty/servery. Neurčuje pořadí.
- **priorita = pořadí** - z čekajících jobů jde na řadu nejdřív vyšší priorita (nižší číslo),
  při shodě priority FIFO podle ID.

Pro frontu `a1 b1 c2 d1 e1 f2 g1` (vše jedna skupina, čísla = priorita) je cílové pořadí
zpracování `a1 b1 d1 e1 g1 c2 f2`.

## Současný stav a příčina problému

**Priorita = oddělené fronty, řazené vzestupně.** V RabbitMQ se priorita modeluje jako
samostatná fronta `<queue>_<priority>` (`src/Broker/PhpAmqpLib/Producer.php:21-23`).
Konzument je obchází ve vzestupném pořadí priorit (`src/Broker/PhpAmqpLib/Consumer.php:24-37`),
takže nižší číslo = vyšší přednost a priorita 1 se odbavuje před prioritou 2.

**serialGroup = striktní řazení podle ID.** Při zpracování jobu se `serialGroup` volá
`checkUnfinishedJobs()` (`src/BackgroundQueue.php:714-733`) →
`getPreviousUnfinishedJobId()` → `findOldestUnfinishedJobIdsByGroup()`
(`src/BackgroundQueue.php:594-620`). Ta hledá jakýkoli nedokončený job ve stejné skupině
s **`id < :id`** (řádek 607-608). Pokud existuje, aktuální job jde do `STATE_WAITING`.
Tedy: job čeká na *kterýkoli* job s nižším ID ve skupině, **bez ohledu na prioritu**.

### Důsledek (pozorovaný problém)

Tyto dvě pravidla si odporují. Skupina `transcribe`, joby vznikají v pořadí:

- ID 1, priorita 2 (do fronty `transcribe_2`)
- ID 2, priorita 1 (do fronty `transcribe_1`)

1. Konzument preferuje `transcribe_1` → vezme ID 2 (prio 1). `checkUnfinishedJobs` najde
   starší ID 1 (`id < 2`) → **ID 2 jde do WAITING**, ačkoli má vyšší prioritu.
2. ID 1 (prio 2) se z `transcribe_2` nedostane ke slovu, dokud konzument upřednostňuje
   prioritu 1, kam stále přitékají joby, které okamžitě skončí ve WAITING.
3. `_processWaitingJobs` WAITING joby vrací zpět do jejich prioritní fronty → smyčka se
   opakuje.

Výsledkem je, že priorita je fakticky ignorována / invertována: starší job s horší
prioritou (nižší ID) blokuje novější joby s vyšší prioritou.

### Existující race condition - síťový výpadek konzumenta (nezávislá na prioritách)

I bez priorit existuje race, která platí již dnes: RabbitMQ doručí totéž ID jobu dvěma
konzumentům, pokud konzumentu A vypadne TCP spojení s RabbitMQ (síťový hiccup, restart
klienta) zatímco job zpracovává. RabbitMQ označí zprávu za nedoručenou a doručí ji
konzumentu B - přitom konzument A stále běží a job také zpracovává.

Ochrana existuje: `isReadyForProcess()` (`src/BackgroundQueue.php:243`) odstaví konzumenta
B, pokud A stihl zapsat `STATE_PROCESSING` do DB. Ale mezi `getEntity()` (řádek 237) a
`save()` (řádek 284) je check-then-act okénko - pokud B získá job v tomto okamžiku, oba
projdou a tentýž job běží dvakrát.

Opravuje to podmíněný claim: `UPDATE ... WHERE id = :id AND state IN (ready stavy)` +
kontrola `affectedRows == 1` (viz Cesta A, bod 4 níže). Tato oprava je nezávislá na
prioritách a smysluplná i sama o sobě.

## Klíčové zjištění k řešení

Problém nelze vyřešit pouhou záměnou řazení `id` → `(priorita, id)`.

### Co dnes drží sériovost "zadarmo"

Uvnitř `serialGroup` se řadí výhradně podle ID: job počká, pokud ve skupině existuje
jakýkoli nedokončený job s nižším ID (`id < :id` v `findOldestUnfinishedJobIdsByGroup()`,
`src/BackgroundQueue.php:607-608`).

Protože autoincrement ID jen roste, platí jednoduchá rovnice:

> **běžící (PROCESSING) job = ten, co začal nejdřív = má nejnižší ID ve skupině
> = je předchůdcem úplně všech ostatních.**

Takže každý nově příchozí job má vždy vyšší ID než ten běžící, uvidí ho přes `id < :id`
jako svého předchůdce a počká ve `STATE_WAITING`. Vzájemné vyloučení tu vůbec není
explicitně naprogramované - vzniká jako **vedlejší efekt řazení podle ID**. Ordering
(pořadí) a exclusion (max. jeden běžící) jsou dnes jedna a táž podmínka.

### Proč to padne při řazení podle priority

Jakmile o pořadí začne rozhodovat `(priorita, ID)`, ta rovnice přestane platit:

- Běží job s **horší prioritou** (vyšší číslo), který má ale **nízké ID** (přišel dřív).
- Pak přijde nový job s **lepší prioritou** (nižší číslo), nutně s **vyšším ID** (přišel
  později).

Nový job má jít před ten běžící (lepší priorita). Podmínka
`(priority < :priority OR (priority = :priority AND id < :id))` ho neblokuje - běžící
job má horší prioritu, takže z pohledu pořadí není předchůdce. Nový job tedy nepočká a
rozjede se souběžně s běžícím → **dva joby téže skupiny běží naráz → porušení
sériovosti**.

**Závěr:** Z jedné podmínky se musí stát dvě oddělené věci:

1. **Ordering** - "kdo jde na řadu" → `(priorita, ID)`.
2. **Mutual exclusion** - "nikdy dva naráz" → musí se vynutit zvlášť, protože už ji
   ordering "nenese s sebou".

To řeší obě navržené cesty: Cesta A přidává explicitní klauzuli na `state = PROCESSING`
(bod 2 níže) plus zámek na skupinu, Cesta B dělá exclusion strukturálně tím, že do
brokera pustí vždy jen jednu hlavu skupiny.

---

## Návrh řešení: změna řazení + zámek

### Princip

Uvnitř `serialGroup` přestaneme řadit podle ID a začneme řadit podle `(priorita, ID)`.
Sériovost zajistíme explicitní kontrolou běžícího jobu + zámkem, aby se to nerozbilo
při souběhu konzumentů.

### Návrh implementace

**1) Výběr předchůdce dle `(priorita, ID)`**

V `findOldestUnfinishedJobIdsByGroup()` (`src/BackgroundQueue.php:594-620`) nahradit
podmínku `id < :id` (řádek 607-608) za lexikografické porovnání a řadit/agregovat podle
`(priority, id)` místo jen `id`:

```sql
(priority < :priority OR (priority = :priority AND id < :id))
```

Job pak čeká, jen pokud ve skupině existuje nedokončený job, který má jít před ním
(vyšší priorita, nebo stejná priorita a nižší ID).

**2) Klauzule na PROCESSING (vzájemné vyloučení)**

Do téhož dotazu přidat: job počká **vždy**, když je ve skupině jakýkoli job ve stavu
`PROCESSING`, bez ohledu na prioritu:

```sql
... AND id <> :id AND (
     state = PROCESSING
  OR (priority < :priority OR (priority = :priority AND id < :id))
)
```

Bez této klauzule by později vložený job s vyšší prioritou nepoznal běžící
nižší-prioritní job jako překážku a naběhl by souběžně. Tato část je **povinná** -
bez ní oprava zanáší regresi sériovosti, která dnes neexistuje.

Příklad kolize bez klauzule na PROCESSING (nevyžaduje souběh - stane se vždy):

| Čas | Konzument A | Konzument B | DB stav |
|-----|-------------|-------------|---------|
| T1 | zpracovává ID 1 (prio 2), callback běží | - | ID 1 = PROCESSING |
| T2 | - | `checkUnfinishedJobs(ID 2, prio 1)`: hledá job kde `(priority < 1 OR (priority=1 AND id < 2))` → ID 1 má prioritu 2, nesplňuje ani jednu větev → **žádný předchůdce** | ID 1 = PROCESSING |
| T3 | - | zapisuje PROCESSING pro ID 2, spouští callback | ID 1 = PROCESSING, **ID 2 = PROCESSING** |

Oba joby skupiny `transcribe` běží naráz. S klauzulí `OR state = PROCESSING` B v T2 ID 1
najde a správně jde do WAITING:

| Čas | Konzument A | Konzument B | DB stav |
|-----|-------------|-------------|---------|
| T1 | zpracovává ID 1 (prio 2), callback běží | - | ID 1 = PROCESSING |
| T2 | - | `checkUnfinishedJobs(ID 2, prio 1)`: `state = PROCESSING` → **ID 1 je blocker → jde do WAITING** | ID 1 = PROCESSING |
| T3 | dokončí, zapisuje FINISHED | - | ID 1 = FINISHED, ID 2 = WAITING |
| T4 | - | `_processWaitingJobs` přepne ID 2 na READY, publikuje do brokera | ID 2 = READY |
| T5 | - | konzument převezme ID 2, spouští callback | ID 2 = PROCESSING |

**3) Zámek na skupinu kolem kritické sekce**

`processJob()` (`src/BackgroundQueue.php:232-284`) je dnes čistý *check-then-act*:
nejdřív `checkUnfinishedJobs()` (řádek 254), pak až o kus dál `setState(PROCESSING)`
+ `save()` (272-284), mezi tím není zámek. Dva konzumenti tak mohou projít kontrolou
současně.

Řešení: kolem **[checkUnfinishedJobs + zápis PROCESSING]** dát pojmenovaný zámek na
skupinu - MySQL `GET_LOCK('bgq:'+serialGroup)` na začátku, `RELEASE_LOCK` po zápisu.
Drží se jen pár mikrosekund (ne po dobu zpracování callbacku). `processJob` má vlastní
`createConnection()` (řádek 235), takže connection-scoped zámek sedí. Serializuje jen
konzumenty téže skupiny, různé skupiny se neperou.

**Abstrakce pro MySQL i PostgreSQL**

Advisory locky jsou v obou DB různé, ale logika je stejná. Řešení: dvě privátní metody
na `BackgroundQueue`, které detekují platformu přes Doctrine DBAL a zavolají správný SQL.

Detekce platformy:

```php
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

$platform = $this->connection->getDatabasePlatform();
```

MySQL (`GET_LOCK` / `RELEASE_LOCK`):

```php
private function acquireGroupLock(string $serialGroup): void
{
    $acquired = $this->connection->fetchOne(
        "SELECT GET_LOCK(?, 10)",
        ['bgq:' . $serialGroup]
    );
    if (!$acquired) {
        throw new \RuntimeException('Could not acquire serial group lock for: ' . $serialGroup);
    }
}

private function releaseGroupLock(string $serialGroup): void
{
    $this->connection->executeStatement(
        "SELECT RELEASE_LOCK(?)",
        ['bgq:' . $serialGroup]
    );
}
```

PostgreSQL (`pg_advisory_lock` / `pg_advisory_unlock`):

```php
private function acquireGroupLock(string $serialGroup): void
{
    // pg_advisory_lock vyžaduje integer klíče - použijeme fixní namespace + crc32 skupiny.
    // Timeout přes lock_timeout session proměnnou (platí jen pro toto volání).
    $this->connection->executeStatement("SET LOCAL lock_timeout = '10s'");
    $this->connection->executeStatement(
        "SELECT pg_advisory_lock(?, ?)",
        [self::PG_LOCK_NAMESPACE, crc32($serialGroup)]
    );
}

private function releaseGroupLock(string $serialGroup): void
{
    $this->connection->executeStatement(
        "SELECT pg_advisory_unlock(?, ?)",
        [self::PG_LOCK_NAMESPACE, crc32($serialGroup)]
    );
}
```

Konstanta `PG_LOCK_NAMESPACE` (např. `0x42475100` = ASCII `BGQ\0`) odděluje klíče
background-queue od ostatních advisory locků v aplikaci. `crc32($serialGroup)` vrací
int32 - pro jména skupin v rámci jedné aplikace je pravděpodobnost kolize zanedbatelná;
při potřebě vyšší jistoty lze použít `abs(crc32(...))` nebo dedikovanou číselnou řadu.

`processJob` pak volá `acquireGroupLock` / `releaseGroupLock` beze znalosti DB platformy
a switch logika žije pouze v těchto dvou metodách.

Příklad kolize bez zámku (A má horší prioritu, B přijde dřív než A zapsal PROCESSING):

| Čas | Konzument A | Konzument B | DB stav |
|-----|-------------|-------------|---------|
| T1 | `checkUnfinishedJobs(ID 1, prio 2)`: žádný předchůdce → **projde** | - | ID 1 = READY |
| T2 | *(ještě nepsal PROCESSING)* | `checkUnfinishedJobs(ID 2, prio 1)`: ID 1 má prio 2 (nesplňuje `priority < 1`), prio 2 ≠ 1 (nesplňuje ordering), stav = READY (ne PROCESSING) → **žádný předchůdce → projde** | ID 1 = READY |
| T3 | zapisuje PROCESSING pro ID 1 | *(již po checku)* | ID 1 = PROCESSING |
| T4 | - | zapisuje PROCESSING pro ID 2 | ID 1 = PROCESSING, **ID 2 = PROCESSING** |

Se zámkem B v T2 čeká, než A dokončí celou sekvenci [check → zápis PROCESSING]. Až pak
provede svůj check - a tehdy ID 1 už je PROCESSING → B správně jde do WAITING:

| Čas | Konzument A | Konzument B | DB stav |
|-----|-------------|-------------|---------|
| T1 | `GET_LOCK('bgq:transcribe')` → získá | - | ID 1 = READY |
| T2 | `checkUnfinishedJobs(ID 1, prio 2)` → projde | `GET_LOCK('bgq:transcribe')` → **čeká** | ID 1 = READY |
| T3 | zapisuje PROCESSING pro ID 1 | *(stále čeká)* | ID 1 = PROCESSING |
| T4 | `RELEASE_LOCK` | - | ID 1 = PROCESSING |
| T5 | - | získá zámek | ID 1 = PROCESSING |
| T6 | - | `checkUnfinishedJobs(ID 2, prio 1)`: `state = PROCESSING` → **ID 1 je blocker → jde do WAITING** | ID 1 = PROCESSING |
| T7 | - | `RELEASE_LOCK` | ID 1 = PROCESSING, ID 2 = WAITING |

**4) Podmíněný claim (levná pojistka)**

Zámek (bod 3) chrání kritickou sekci `[checkUnfinishedJobs → zápis PROCESSING]` uvnitř
jednoho `processJob()` volání. Ale existuje scénář, který ho obejde: **tentýž job ID
doručený dvěma konzumentům** (RabbitMQ redelivery při výpadku spojení konzumenta).

Dnes `save()` (`src/BackgroundQueue.php:643`) dělá:

```sql
UPDATE background_job SET state = PROCESSING, ... WHERE id = :id
```

Bez podmínky na stav. Takže pokud konzument A drží job a zpracovává ho, ale jeho TCP
spojení s RabbitMQ vypadne - RabbitMQ doručí totéž ID konzumentu B. B projde
`isReadyForProcess()` (job je stále PROCESSING → vrátí `false`) a zastaví se. **Ale
pouze pokud A stihl zapsat PROCESSING.** Pokud B dostane zprávu dřív, než A zapsal:

| Čas | Konzument A | Konzument B | DB stav |
|-----|-------------|-------------|---------|
| T1 | `isReadyForProcess()` → READY → OK | - | ID 1 = READY |
| T2 | *(ještě nepsal PROCESSING)* | dostane redelivery ID 1, `isReadyForProcess()` → READY → OK | ID 1 = READY |
| T3 | `UPDATE SET state=PROCESSING WHERE id=1` → zapíše | - | ID 1 = PROCESSING |
| T4 | spouští callback | `UPDATE SET state=PROCESSING WHERE id=1` → **také zapíše** (bez podmínky na stav!) | ID 1 = PROCESSING |
| T5 | callback běží | callback také běží | **oba zpracovávají ID 1** |

Oprava: doplnit podmínku na stav a kontrolovat `affectedRows`:

```sql
UPDATE background_job SET state = PROCESSING, ... WHERE id = :id AND state IN (1, 4, -1)
-- STATE_READY=1, STATE_TEMPORARILY_FAILED=4, STATE_BACK_TO_BROKER=-1
```

```php
$affected = $this->connection->update(
    $this->config['tableName'],
    $entity->getDatabaseValues(),
    ['id' => $entity->getId(), 'state' => $readyStates]  // WHERE id = ? AND state IN (?)
);
if ($affected === 0) {
    return; // job mezitím sebral nebo změnil stav jiný konzument
}
```

Pokud `affectedRows === 0`, job mezitím přešel do jiného stavu (jiný konzument ho
zpracovává nebo dokončil) → aktuální konzument tiše vrátí `return`. Chrání i mimo
`serialGroup` - platí pro jakýkoli job doručený vícekrát.

Jde o **laciné pojistné patro navíc**: UPDATE je atomický na úrovni DB, takže i při
dokonalém souběhu T3 a T4 může podmíněný UPDATE uspět jen jednomu z konzumentů.

### Náročnost / indexy

**Souhrn:** Problém s náročností při velkém počtu nedokončených jobů existuje už dnes -
nevzniká novým řešením. Nová podmínka (`priority`, `PROCESSING`) na tom nic nemění, protože
jde o filtry vyhodnocené na řádcích, které už beztak vybral index `state`.

Přidané podmínky (`priority`, `PROCESSING`) jsou residual filtry na řádcích, které už
vybral index `state` (`src/BackgroundQueue.php:457-459` zakládá indexy jen na `id`, `identifier`,
`state`). **Žádný nový index nepotřebují, plán dotazu se nemění.** Náklad je daný počtem
nedokončených jobů, ne velikostí tabulky. (Pokud by se nedokončené joby hromadily do
velkých čísel, šlo by zvážit kompozitní index `(serial_group, state, priority, id)`, ale
to je nezávislé na této opravě.)

### Vlastnosti

- **Plus:** malý, lokální zásah - jádro je úprava jednoho dotazu.
- **Plus:** přenositelnost MySQL/PostgreSQL řeší abstrakce `acquireGroupLock` /
  `releaseGroupLock` s detekcí platformy přes Doctrine DBAL (viz výše).
- **Minus:** závislost na DB advisory locku - přidává další synchronizační primitiv.
- Vzájemné vyloučení zůstává vynucováno "líně" při konzumaci.

## Joby bez serialGroup

Joby bez `serialGroup` zůstávají beze změny: `checkUnfinishedJobs()`
(`src/BackgroundQueue.php:716-718`) pro ně hned dělá `return true`, takže nikdy nejdou do
WAITING ani nehledají předchůdce. Body 1-3 (řazení, klauzule na `PROCESSING`, zámek na
skupinu) se jich tedy netýkají - zámek se navíc bere jen je-li `serialGroup` nastavena.
Jediný dotyk je bod 4 (podmíněný claim v `save()`), který platí pro všechny joby jako
atomické převzetí ke zpracování; publikování zůstává netknuté.

## Návrh testů

Všechny testy patří do jediné existující suite `Integration`
(`tests/Integration/BackgroundQueueTest.php`). Drží se zavedených patternů: data providery
parametrizované přes `producer`/`waitingQueue`, reflexe na privátní metody
(`new \ReflectionClass(BackgroundQueue::class)`, jako u `testCheckUnfinishedJobs`,
`tests/Integration/BackgroundQueueTest.php:237-257`) a helper `fetchAllJobs()` pro čtení
stavu jobů z DB.

### Předpoklady - úprava helperů

Bez následujících úprav testy níže nelze napsat:

1. **`getBackgroundQueue()`** (`tests/Integration/BackgroundQueueTest.php:278`) - přidat
   parametr `priorities` (default `[1]` jako dnes) a propsat ho do konfigurace, ať lze
   publikovat na různé priority.
2. **`Mailer` helper** (`tests/Support/Helper/Mailer.php`) - přidat callback, který si
   parametr (značku jobu) připíše do sdíleného statického pole, např.:

   ```php
   public static array $processOrder = [];

   public function processRecording(string $mark): void
   {
       self::$processOrder[] = $mark;
   }
   ```

   Pole se v `_before()` / `clear()` musí vynulovat, aby testy byly nezávislé.
3. **Přímý přístup k DB connection** v testu (jako `clear()`,
   `tests/Integration/BackgroundQueueTest.php:317`) pro ruční nastavení stavu/priority
   řádku - tím se deterministicky nasimulují stavy, které by jinak vznikly jen souběhem
   (běžící `PROCESSING` job, job sebraný jiným konzumentem).
4. **`Producer` helper** (`tests/Support/Helper/Producer.php`) - pouze pro Test 6.
   `consume()` (řádek 35) i `getMessageCount()` (řádek 49) dnes natvrdo pracují s frontou
   `'general'` a neumí prioritní fronty `<queue>_<priority>` (`general_1`, `general_2`).
   Pro broker-mode test je nutné je rozšířit tak, aby konzumovaly z prioritních front ve
   správném pořadí (nižší číslo dřív), jak to dělá reálný `Consumer`
   (`src/Broker/PhpAmqpLib/Consumer.php:24-37`).

Každý test je psán tak, aby **před opravou padal (červená) a po opravě prošel (zelená)**,
kromě testu bez `serialGroup` a testu zámkového primitiva, které ověřují, že se nic
nerozbilo / že nová abstrakce funguje.

### Test 1 - joby bez serialGroup (regrese, kapitola „Joby bez serialGroup")

Ověřuje, že se opravy bodů 1-3 jobů bez `serialGroup` vůbec nedotknou.

- Publikovat několik jobů **bez** `serialGroup`, s proloženými prioritami (např. prio 2,
  prio 1, prio 2).
- Reflexí zavolat `checkUnfinishedJobs()` (`src/BackgroundQueue.php:714`) na každý z nich.
- **Aser:** vždy vrací `true`, žádný job neskončí ve `STATE_WAITING` (řádek 716-718 dělá
  `return true` hned na vstupu).
- Doplnit běh `process()` (cron mód, bez produceru) a ověřit, že se všechny zpracují
  (`STATE_FINISHED`) - pořadí mezi nimi se neřeší, sériovost se neuplatňuje.

### Test 2 - řazení podle (priorita, ID) uvnitř skupiny (bod 1)

Jádro opravy a hlavní reprodukce. Lze pojmout dvěma vrstvami, doporučuji obě:

**2a) Jednotka nad `getPreviousUnfinishedJobId()`** (`src/BackgroundQueue.php:582`,
přes reflexi). Do jedné `serialGroup` vložit joby a ručně jim nastavit priority, pak
ověřit, koho dotaz označí za předchůdce:

- job A: ID 1, prio 2; job B: ID 2, prio 1 (vyšší priorita, přišel později).
- `getPreviousUnfinishedJobId(B)` → **`null`** (B nemá jít po nikom: nikdo nemá vyšší
  prioritu ani stejnou prioritu s nižším ID).
- `getPreviousUnfinishedJobId(A)` → **ID 2** (B má jít před A).
- Před opravou je výsledek opačný (řadí se jen podle `id < :id`, řádek 607-608), takže
  test červeně reprodukuje bug.

**2b) End-to-end pořadí přes `process()` v cron módu (bez produceru).** Cron mód je pro
tento test čistě deterministický: `process()` (`src/BackgroundQueue.php:184`) projde
zpracovatelné joby, hlava skupiny se zpracuje a zbytek skupiny jde do `STATE_WAITING`;
opakovaným voláním `process()` (WAITING je v cron módu zpracovatelný stav) se postupně
odbaví celá skupina.

- Konfigurace `priorities` `[1, 2]`, callback `processRecording`, jako značku použít
  identifikátor jobu (`a`, `b`, ...).
- Do jedné `serialGroup` publikovat kanonický příklad ze zadání:
  `a1 b1 c2 d1 e1 f2 g1` (písmeno = pořadí vložení, číslo = priorita).
- Volat `process()` v cyklu (např. dokud existují nedokončené joby, s pojistným stropem
  iterací).
- **Aser:** `Mailer::$processOrder === ['a','b','d','e','g','c','f']` (priorita ASC, při
  shodě ID ASC).
- **Aser sériovosti:** v žádném okamžiku neběží dva joby skupiny naráz - v cron módu to
  plyne z toho, že `process()` zpracovává joby inline jeden po druhém; lze ověřit nepřímo
  tím, že do `STATE_PROCESSING` se nikdy nedostanou dva řádky téže skupiny současně
  (kontrola uvnitř `processRecording` přes `fetchAllJobs`).

### Test 3 - klauzule na PROCESSING = vzájemné vyloučení (bod 2)

Ověřuje scénář z tabulky v bodě 2 (běžící nižší-prioritní job musí zablokovat novější
job s vyšší prioritou). Deterministicky, bez souběhu, ruční manipulací stavu v DB:

- Do jedné `serialGroup` publikovat job A (ID 1, prio 2) a job B (ID 2, prio 1).
- Ručně (raw connection) nastavit A na `STATE_PROCESSING` (simulace běžícího callbacku).
- Reflexí zavolat `checkUnfinishedJobs(B)` (`src/BackgroundQueue.php:714`).
- **Aser:** vrací `false` a B přejde do `STATE_WAITING`, **ačkoli A není ordering-předchůdce**
  (A má horší prioritu). Blok vzniká jen díky klauzuli `OR state = PROCESSING`.
- Bez této klauzule (a po pouhé opravě bodu 1) by `checkUnfinishedJobs(B)` vrátil `true`
  a vznikla by regrese sériovosti, kterou tento test chytí.
- Doplňkový případ: A ve `STATE_FINISHED` → `checkUnfinishedJobs(B)` vrací `true`
  (dokončený job není překážka).

### Test 4 - zámek na skupinu (bod 3)

Plný race check-then-act (dva konzumenti projdou kontrolou současně) se v jednovláknovém
PHPUnit procesu **deterministicky nereprodukuje** - tady to v testech přiznáváme a
ověřujeme jen to, že zámkové primitivum funguje. Reálné vzájemné vyloučení za souběhu pak
fakticky stojí na tomto primitivu plus na podmíněném claimu (Test 5).

Test samotného primitiva (`acquireGroupLock` / `releaseGroupLock`, přes reflexi), nezávisle
na platformě (poběží proti MySQL z CI):

- Vytvořit **dvě samostatné** DBAL connection ze stejného DSN (jako `processJob` přes
  `createConnection()`, `src/BackgroundQueue.php:235`).
- Na connection č. 1 `acquireGroupLock('skupina')` → uspěje.
- Na connection č. 2 `acquireGroupLock('skupina')` s krátkým timeoutem → **neuspěje /
  zablokuje se** (u MySQL `GET_LOCK(..., timeout)` vrátí 0 → metoda vyhodí `RuntimeException`).
- Na connection č. 1 `releaseGroupLock('skupina')`.
- Na connection č. 2 `acquireGroupLock('skupina')` → nyní **uspěje**.
- Druhý případ: dvě **různé** skupiny se navzájem neblokují (obě connection získají zámek
  každá pro svou skupinu) - ověří, že serializujeme jen v rámci jedné skupiny.

### Test 5 - podmíněný claim v save() (bod 4)

Ověřuje atomické převzetí jobu (RabbitMQ redelivery doručí totéž ID dvakrát). Race mezi
dvěma `UPDATE` se nasimuluje deterministicky tím, že DB řádek přepneme „pod rukama" do
ne-ready stavu, zatímco v paměti držíme starou (READY) entitu:

- Publikovat job bez `serialGroup` s callbackem `processRecording`.
- Načíst entitu (`fetchAllJobs`) - v paměti je `STATE_READY`.
- Raw connection přepnout řádek v DB na `STATE_FINISHED` (případně `STATE_PROCESSING`) -
  simulace „job mezitím sebral jiný konzument".
- Zavolat `processJob(id)` (`src/BackgroundQueue.php:232`).
- **Aser:** callback se **nespustil** (`Mailer::$processOrder` je prázdné) a stav v DB se
  z `STATE_FINISHED` nezměnil - podmíněný `UPDATE ... WHERE id = :id AND state IN (ready)`
  vrátil `affectedRows === 0` a `processJob` tiše skončil.
- Negativní kontrola (job je READY): běžný job projde, `affectedRows === 1`, callback se
  spustí, stav je `STATE_FINISHED` - ověří, že claim nezablokoval normální cestu.
- Tento test platí i mimo `serialGroup` (claim je společný pro všechny joby, viz kapitola
  „Joby bez serialGroup").

### Test 6 - komplexní broker-mode end-to-end (celá smyčka)

Jediný „velký" test. Ostatní testy jsou izolované a deterministické, ale pozorovaný problém
se projevuje **v broker módu** (kapitola „Důsledek", `docs/priority-serialgroup.md:41-46`),
ne v cron módu. Tenhle test jako jediný projede celou produkční smyčku
`process()` → publikace do prioritní fronty → konzumace → `checkUnfinishedJobs` → WAITING →
`_processWaitingJobs` → zpět na READY → znovupublikace. Tím zároveň pokryje interakci s
`processWaitingJobs()` (`src/BackgroundQueue.php:768`), která sdílí
`findOldestUnfinishedJobIdsByGroup()` (řádek 594) - žádný z testů 1-5 ji neprověří.

- Konfigurace: `producer` zapnutý, `priorities` `[1, 2]`, callback `processRecording`,
  jako značku použít identifikátor jobu.
- Do jedné `serialGroup` publikovat kanonický příklad `a1 b1 c2 d1 e1 f2 g1`.
- V cyklu (s pojistným stropem iterací) opakovat:
  1. `process()` - v broker módu přepne způsobilé řádky na READY a publikuje jejich ID do
     prioritních front; zároveň zaregistruje interní `_processWaitingJobs`
     (`src/BackgroundQueue.php:192-194`).
  2. konzumovat dostupné zprávy přes rozšířený `Producer` helper (prioritní fronty v pořadí
     priorit) a na každé ID zavolat `processJob()` - to je cesta, kterou v provozu jede
     `Consumer::consume()`.
  3. nechat proběhnout `_processWaitingJobs` (zkonzumovat a zpracovat i jeho job), aby se
     nejstarší WAITING hlava skupiny vrátila na READY.
  - cyklus končí, až nejsou žádné nedokončené joby.
- **Aser pořadí:** `Mailer::$processOrder === ['a','b','d','e','g','c','f']` (priorita ASC,
  při shodě ID ASC) - stejný cíl jako 2b, ale přes reálnou broker cestu.
- **Aser sériovosti:** v žádném okamžiku nejsou dva joby téže skupiny ve `STATE_PROCESSING`
  současně (kontrola uvnitř `processRecording` přes `fetchAllJobs`).
- Před opravou červeně reprodukuje invertovanou prioritu z kapitoly „Důsledek", po opravě
  zezelená.

Pozn.: test vyžaduje běžící RabbitMQ (běží v CI kontejneru `background-queue_rabbitmq`) a
je z celé sady nejdražší a nejcitlivější na časování; proto zůstává jediný svého druhu -
konkrétní větve levněji a stabilněji pokrývají jednotkové testy 2a/3/5.

### Shrnutí pokrytí

| Test | Pokrývá | Charakter |
|------|---------|-----------|
| 1 | kapitola „Joby bez serialGroup" | regrese, zelený před i po |
| 2a/2b | bod 1 (řazení dle priority, ID) | reprodukce, červený → zelený |
| 3 | bod 2 (klauzule PROCESSING) | reprodukce regrese sériovosti |
| 4 | bod 3 (zámek na skupinu) | jen primitivum (race nereprodukovatelný single-process) |
| 5 | bod 4 (podmíněný claim) | reprodukce redelivery race |
| 6 | celá broker smyčka + `_processWaitingJobs` | end-to-end, vyžaduje RabbitMQ, nejdražší |
