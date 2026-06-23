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
- **Broker mód (producer nastaven):** `process()` joby *nespouští*; přepne způsobilé řádky v DB zpět na `STATE_READY` a znovu publikuje jejich ID do brokera. Skutečná práce probíhá v `Consumer::consume()` → `processJob()`. Broker vždy nese pouze **ID jobu** (string); řádek v DB je vždy zdrojem pravdy.

### Stavový automat jobu (src/Entity/BackgroundJob.php)

Stavy jsou celočíselné konstanty na `BackgroundJob` (`STATE_READY=1`, `STATE_PROCESSING=2`, `STATE_FINISHED=3`, `STATE_TEMPORARILY_FAILED=4`, `STATE_PERMANENTLY_FAILED=5`, `STATE_WAITING=6`, `STATE_REDUNDANT=7`, `STATE_BROKER_FAILED=8`, `STATE_BACK_TO_BROKER=-1`). Dotazy řídí `READY_TO_PROCESS_STATES` a `FINISHED_STATES`.

Výsledek callbacku (`switch` v `processJob()`) je určen typem vyhozené výjimky:
- `PermanentErrorException` / `TypeError` / holá `DieException` → `PERMANENTLY_FAILED`
- `WaitingException` → znovu publikováno (klon) a ponecháno ve waiting; počítadlo pokusů se **nezvyšuje**
- `SkipException` → tiše přeskočeno, stav zůstává
- jakýkoli jiný `Throwable` → `TEMPORARILY_FAILED`, opakováno s exponenciálním backoffem (`getPostponement()`, zdvojnásobování, strop 16 minut)
- bez vyhození výjimky → `FINISHED`

`DieException` s `getPrevious()` se přepošle podle té předchozí výjimky **a** nastaví `shouldDie` - konzumer se ukončí před další iterací (`dieIfNecessary()` kontrolováno ve smyčce `ConsumeCommand`u). Slouží k ukončení konzumera, kterému se zavřel Doctrine EM.

### serialGroup → sériové zpracování a mechanismus WAITING

Joby sdílející `serialGroup` běží striktně v pořadí podle ID. `checkUnfinishedJobs()` hledá starší nedokončený job ve stejné skupině; pokud ho najde, aktuální job se odloží do `STATE_WAITING`. V broker módu interní opakující se job `_processWaitingJobs` (`CallbackNameEnum::PROCESS_WAITING_JOBS`, registrovaný automaticky při nastaveném produceru) periodicky přepíná nejstarší WAITING job v každé skupině zpět na READY a znovu ho publikuje. Tento interní job je v módu `RECURRING` a po každém úspěšném běhu se z DB maže (jeho historie nemá hodnotu).

### ModeEnum (normal / unique / recurring)

- `UNIQUE` - vyžaduje `identifier`; `isRedundant()` označí job jako `REDUNDANT`, pokud existuje starší job se stejným identifikátorem.
- `RECURRING` - vyžaduje `identifier`; při `FINISHED` se job naklonuje a znovu publikuje (`cloneAndPublish()`), ale jen pokud už neexistuje nedokončený job s tímto identifikátorem.

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

`Producer` a `Consumer` jsou rozhraní - přines si libovolný broker. K dispozici je hotová implementace `PhpAmqpLib` (volitelná závislost; viz README pro doporučené omezení `conflict` pinující `php-amqplib` na `^3.0`). Priorita je modelována jako **samostatné fronty** pojmenované `<queue>_<priority>`; `QUEUE_TOP_PRIORITY = 0` je vyhrazena pro řídicí (DIE) zprávy, takže ji konzumeři kontrolují jako první. `publishDie()` + tělo `DIE` je způsob, jak `reload-consumers` elegantně restartuje běžící konzumery. Konzumer spuštěný s **labelem** (`consume -l <label>`) dostane vlastní top-priority frontu `<queue>_0_<label>` (`Manager::getTopPriorityName()` / `includeTopPriority()`); díky tomu lze přes `reload-consumers -l label1,label2` restartovat cíleně právě jeho a DIE zprávu nesní jiný konzumer. Label nesmí obsahovat oddělovač `_` (`QUEUE_NAME_PARTS_DELIMITER`).

Vedle `DIE` existuje druhá řídicí zpráva `SHUTDOWN` (`publishShutdown()`, posílá ji `shutdown-consumers` se stejným cílením přes label). Liší se jen exit kódem po dojetí rozdělaného jobu: `DIE` ukončí konzumera přes `die()` (exit 0, supervisor ho typicky restartuje), `SHUTDOWN` přes `exit(Producer::NICE_SHUTDOWN_EXIT_CODE)` (= 100). Ten kód patří do supervisor `exitcodes` při `autorestart=unexpected`, takže konzumer už znovu nenaběhne - "nice shutdown" pro řízené zastavení (např. před restartem serveru). Detaily a vzor konfigurace supervisoru viz README sekce 6.

### Konzolové příkazy (src/Console/)

Všechny příkazy kromě `ConsumeCommand` rozšiřují lokální abstraktní `Command`, který obaluje běh do `ADT\CommandLock` (`FileSystemStorage` pod `tempDir`), takže nemohou běžet souběžně samy se sebou.

- `background-queue:process` - vstupní bod pro cron (spouštět každou minutu).
- `background-queue:consume [queue] -j <jobs> -p <priorities> -l <label>` - dlouhoběžící brokerový konzumer; `-p` přijímá rozsahy jako `20-40`, `25-`, `-20`; `-l` je volitelný label pro cílený restart.
- `background-queue:clear-finished [days]`, `background-queue:reload-consumers <number> [queue] [-l label1,label2]`, `background-queue:shutdown-consumers <number> [queue] [-l label1,label2]` (řízené zastavení = reload, ale s exit kódem `NICE_SHUTDOWN_EXIT_CODE`, který supervisor nerestartuje), `background-queue:update-schema`.

### Hromadný insert

`startBulk()` / `endBulk()` s `bulkSize` dávkují více volání `publish()` do jediného `INSERT`u s více `VALUES` (`insertMultipleEntities()`), zabaleno do transakce, aby bylo možné získat vložená ID.
