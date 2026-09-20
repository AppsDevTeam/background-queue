# CLAUDE.md

Tento soubor poskytuje vodítka pro Claude Code (claude.ai/code) při práci s kódem v tomto repozitáři.

## Co to je

`adt/background-queue` je samostatná PHP knihovna (PHP ^8.2) pro zpracování úloh na pozadí, a to buď přes cron, nebo přes AMQP broker (RabbitMQ). Jádro je nezávislé na frameworku - navzdory zmínce o "Nette" v `composer.json` kód nemá žádnou závislost na Nette DI. Joby ukládá přes **čisté Doctrine DBAL** (nikoli ORM), pomocí vlastního `Connection` nezávislého na entity manageru hostitelské aplikace.

Uživatelská dokumentace (volby konfigurace, sémantika výjimek, příklady použití) je v `README.md` a je psaná česky; přečti si ji pro popis chování na uživatelské úrovni.

## Příkazy

Vývojové prostředí běží v Dockeru (kontejnery `background-queue_php`, `background-queue_mysql`, `background-queue_rabbitmq`).

```bash
make docker-up          # sestaví a spustí kontejnery (docker-compose up)
make init               # composer install + (znovu)vytvoří MySQL databázi + vyčistí temp/
make test               # spustí Integration suite: php vendor/bin/codecept run Integration
```

`make init` spouští `composer install` uvnitř kontejneru `background-queue_php`. `make test` volá Codeception přímo, takže ho spouštěj zevnitř kontejneru (`docker exec background-queue_php make test`) nebo tam, kde je dostupné PHP + databáze.

Spuštění jednoho testu / jedné testovací metody:

```bash
php vendor/bin/codecept run Integration BackgroundQueueTest
php vendor/bin/codecept run Integration BackgroundQueueTest:testPublish
```

Existuje jen jedna testovací suite, `Integration` (konfigurace v `tests/Integration.suite.yml`, namespace `Tests`). Testy jsou skutečné integrační testy proti MySQL; mnoho z nich je řízeno data providery a parametrizováno přes `producer` (broker zapnutý/vypnutý) a kombinace `serialGroup`/`waiting`. `Tests\Support\Helper\Producer` je falešný in-memory broker, který umožňuje otestovat brokerovou cestu bez RabbitMQ.

## Architektura

### `BackgroundQueue` (src/BackgroundQueue.php) - jádro

Jedna velká třída, která vlastní vše: konfiguraci, DBAL connection, publikování i zpracování. Klíčové vstupní body:

- `publish($callbackName, $parameters, $serialGroup, $identifier, $mode, $postponeBy, $priority)` - vytvoří `BackgroundJob`, uloží ho (`save()`) a pak zavolá `publishToBroker()`. Název callbacku musí existovat v nakonfigurované mapě `callbacks`.
- `process()` - cronová cesta. Vybere joby ve zpracovatelných stavech a buď je znovu vloží do brokera (broker mód), nebo je zpracuje inline (`processJob()`).
- `processJob(int $id)` - spustí callback jednoho jobu a aplikuje stavový automat výsledku. Volá ho jak `process()` (cron), tak brokerový `Consumer`.

### Dva režimy běhu - přítomnost `producer` přepíná chování

Téměř každá větev v `process()`/`save()` se odvíjí od toho, zda je nakonfigurován `producer`:

- **Cron mód (bez producera):** `process()` spouští joby inline. Zpracovatelné stavy nezahrnují `STATE_BACK_TO_BROKER`.
- **Broker mód (producer nastaven):** `process()` joby *nespouští*; přepne způsobilé řádky v DB zpět na `STATE_READY` a znovu publikuje jejich ID do brokera. Skutečná práce probíhá v `Consumer::consume()` → `processJob()`. Broker vždy nese pouze **ID jobu** (string); řádek v DB je vždy zdrojem pravdy. Protože ale řádek probouzí jediná zpráva v brokeru, běží na konci `process()` pojistka `republishLostMessages()`: joby v `READY`/`TEMPORARILY_FAILED` bez zápisu déle než `lostMessageTimeout` (default 3600 s) a s prošlým `availableFrom` znovu publikuje (podmíněný UPDATE, duplicitní zprávu zahodí podmíněný claim; opožděný duplikát na FINISHED řádku se proto v `processJob()` tiše přeskakuje).

### Stavový automat jobu (src/Entity/BackgroundJob.php)

Stavy jsou celočíselné konstanty na `BackgroundJob` (`STATE_READY=1`, `STATE_PROCESSING=2`, `STATE_FINISHED=3`, `STATE_TEMPORARILY_FAILED=4`, `STATE_PERMANENTLY_FAILED=5`, `STATE_WAITING=6`, `STATE_REDUNDANT=7`, `STATE_BROKER_FAILED=8`, `STATE_BACK_TO_BROKER=-1`). Dotazy řídí `READY_TO_PROCESS_STATES` a `FINISHED_STATES`.

Výsledek callbacku (`switch` v `processJob()`) je určen typem vyhozené výjimky:
- `PermanentErrorException` / jakýkoli PHP `Error` (chyba v kódu - TypeError, dělení nulou, volání nad null, ...) / holá `DieException` → `PERMANENTLY_FAILED`
- `WaitingException` → původní job se uzavře jako `FINISHED` a publikuje se jeho klon s odkladem `waitingJobExpiration` (`cloneAndPublish()`); počítadlo pokusů se tedy **nezvyšuje** - klon startuje od nuly. Nezaměňovat se stavem `WAITING` (ten patří čekání na předchůdce v serialGroup)
- `SkipException` → job se uzavře jako `FINISHED` (s `error_message` výjimky), neopakuje se; u `RECURRING` jobu se přesto naplánuje další běh (klonuje ho FINISHED větev)
- jakýkoli jiný `Throwable` → `TEMPORARILY_FAILED`, opakováno s exponenciálním backoffem (`getPostponement()`, zdvojnásobování, strop 16 minut)
- bez vyhození výjimky → `FINISHED`

`DieException` s `getPrevious()` se přepošle podle té předchozí výjimky **a** nastaví `shouldDie` - konzumer se ukončí před další iterací (`dieIfNecessary()` kontrolováno ve smyčce `ConsumeCommand`u). Slouží k ukončení konzumera, kterému se zavřel Doctrine EM.

### serialGroup → sériové zpracování a mechanismus WAITING

Joby sdílející `serialGroup` běží striktně v pořadí podle (priorita, ID). `checkUnfinishedJobs()` hledá ve skupině předchůdce (`getPreviousUnfinishedJobId()`); pokud ho najde, aktuální job se odloží do `STATE_WAITING`.

Ven z WAITING vedou dvě vrstvy:

- **`promoteWaitingSuccessor()` - hlavní cesta.** Na konci `processJob()` (a v REDUNDANT větvi): dojel-li job se `serialGroup` do stavu, ze kterého už není překážkou (`FINISHED`, `REDUNDANT`, `PERMANENTLY_FAILED`), jeho vlastní konzument rovnou pustí do hry hlavu WAITING téže skupiny. Běží **pod zámkem skupiny** - bez něj vzniká tichý deadlock, kdy nástupce zapíše svůj WAITING až poté, co ho předchůdce marně hledal.
- **`promoteWaitingJobs()` - záchranná síť.** Volá se na konci `process()` v broker módu, pustí hlavu každé skupiny s nějakým WAITING jobem. Jeden job na skupinu na běh; kryje případy, kdy probuzení neproběhlo (zabitý konzument, ztracená zpráva). Zámek nebere.

Samotná promotion (`promoteWaitingJob()`) je podmíněná: `UPDATE ... WHERE id = ? AND state = WAITING`, jen dotčené sloupce, publikace do brokera jen když UPDATE zabral. WAITING je totiž v `READY_TO_PROCESS_STATES`, takže si takový job může konzument claimnout i přímo z brokera. Stejně podmíněný je i opačný přechod - odklad do WAITING v `checkUnfinishedJobs()` (`WHERE state IN READY_TO_PROCESS_STATES`); navíc se entita po získání zámku skupiny znovu načítá z DB, aby konzument se zastaralou kopií (redelivery) nepřepsal PROCESSING běžícího jobu.

Dřív to obstarával interní opakující se job `_processWaitingJobs`; ten byl zrušen (byl jediný bod selhání - po `PERMANENTLY_FAILED` už se nikdy nenahradil a všechny skupiny zamrzly). Zbylé řádky v ostrých databázích se mažou ručně, knihovna po nich neuklízí. Podrobně v `docs/priority-serialgroup.md`, kapitola „Přepracování probouzení WAITING jobů".

Pozn. k pojmu „nedokončeno": `getPreviousUnfinishedJobId()` bere jako blokující `READY_TO_PROCESS_STATES + PROCESSING`, `getUnfinishedJobIdentifiers()` (RECURRING/UNIQUE) používá `BackgroundJob::TERMINAL_STATES` = `FINISHED_STATES` + `PERMANENTLY_FAILED`. `FINISHED_STATES` samo znamená „vyřízeno v pořádku" a řídí jen `finished_at`.

### ModeEnum (normal / unique / recurring)

- `UNIQUE` - vyžaduje `identifier`; `isRedundant()` označí job jako `REDUNDANT`, pokud existuje starší job se stejným identifikátorem.
- `RECURRING` - vyžaduje `identifier`; při `FINISHED` se job naklonuje a znovu publikuje (`cloneAndPublish()`), ale jen pokud už neexistuje nedokončený job s tímto identifikátorem. Dokončený běh se po naklonování rovnou maže (historie nemá hodnotu a jen roste), takže na identifikátor zbývá v tabulce jediný řádek; selhané běhy se nemažou. Opožděný duplikát zprávy smazaného řádku proto `processJob()` tiše přeskakuje (`JobNotFoundException`).

### parameters: serialize vs JSON

`BackgroundJob` ukládá parametry do **jednoho ze dvou sloupců**: `parameters_json` (když platí `isJsonable()` - pouze skaláry/pole/`DateTimeInterface`) nebo `parameters` (BLOB, PHP `serialize()`, podporuje libovolné objekty a binární data). `getParameters()` čte ten, který je nastaven; JSON cesta rehydratuje `DateTime` přes `ADT\Utils\Utils::getDateTimeFromArray`.

### Schéma se spravuje samo

`updateSchema()` sestaví definici tabulky v kódu a porovná ji s živým schématem (vytvoření nebo `ALTER`). Volá se automaticky při prvním použití; nejsou žádné migrační soubory. Název tabulky je konfigurovatelný (`tableName`, výchozí `background_job`).

### Práce s connection - dvě odlišná spojení, záměrně

- Během **publishování** queue znovu používá connection aplikace, aby inserty jobů sdílely transakci aplikace.
- Během **processJob** vytvoří `createConnection()` *samostatné* DBAL connection ze stejných parametrů, aby v případě rollbacku aplikační transakce mohl konzumer přesto zapsat chybový stav jobu.
- `databaseConnectionCheckAndReconnect()` / `databasePing()` chrání dlouhoběžící konzumery proti ztrátě spojení s DB.

### Middleware (src/Middleware/) - flush do brokera respektující transakci

`BackgroundQueueMiddleware` je Doctrine DBAL `Middleware`, který si **hostitelská aplikace** instaluje na *své vlastní* connection. Obaluje `commit()` tak, že publikování do brokera (`doPublishToBroker()`) je odloženo, dokud se skutečně nezacommituje nejvíce vnější aplikační transakce (`transactionNestingLevel === 0`). Tím se zabrání publikování ID jobu do RabbitMQ dříve, než je řádek trvale zacommitován. `publishToBroker()` podobně bufferuje, dokud je aktivní transakce.

### Brokerová abstrakce (src/Broker/)

`Producer` a `Consumer` jsou rozhraní - přines si libovolný broker. K dispozici je hotová implementace `PhpAmqpLib` (volitelná závislost; viz README pro doporučené omezení `conflict` pinující `php-amqplib` na `^3.0`). Priorita je modelována jako **samostatné fronty** pojmenované `<queue>_<priority>`; `QUEUE_TOP_PRIORITY = 0` je vyhrazena pro řídicí (DIE) zprávy, takže ji konzumeři kontrolují jako první. `publishDie()` + tělo `DIE` je způsob, jak `reload-consumers` elegantně restartuje běžící konzumery.

### Konzolové příkazy (src/Console/)

Všechny příkazy kromě `ConsumeCommand` rozšiřují lokální abstraktní `Command`, který obaluje běh do `ADT\CommandLock` (`FileSystemStorage` pod `tempDir`), takže nemohou běžet souběžně samy se sebou.

- `background-queue:process` - vstupní bod pro cron (spouštět každou minutu).
- `background-queue:consume [queue] -j <jobs> -p <priorities>` - dlouhoběžící brokerový konzumer; `-p` přijímá rozsahy jako `20-40`, `25-`, `-20`.
- `background-queue:monitor` - denní monitoring (spouštět cronem o půlnoci): `reportStuckJobs()` spočítá joby v `TEMPORARILY_FAILED`, `PERMANENTLY_FAILED` a `PROCESSING` běžící déle než 24 h (tepající hung callback, na který reaper nedosáhne) a je-li co hlásit, pošle report do loggeru (critical při PERMANENTLY_FAILED / dlouhém PROCESSING, jinak warning).
- `background-queue:clear-finished [days]`, `background-queue:reload-consumers <number> [queue]`, `background-queue:update-schema`.

### Hromadný insert

`startBulk()` / `endBulk()` s `bulkSize` dávkují více volání `publish()` do jediného `INSERT`u s více `VALUES` (`insertMultipleEntities()`), zabaleno do transakce, aby bylo možné získat vložená ID.
