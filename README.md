# BackgroundQueue

Komponenta umožňuje zpracovávat úkoly na pozadí pomocí cronu nebo AMQP brokera (např. RabbitMQ). Vhodné pro dlouhotrvající requesty, komunikaci s API nebo odesílání webhooků či e-mailů.

Komponenta využívá vlastní doctrine entity manager pro ukládání záznamů do fronty. Tím pádem fungování komponenty není ovlivněno aplikačním entity managerem a naopak.

## 1. Instalace a konfigurace

### 1.1 Instalace

```
composer require adt/background-queue
```

### 1.2 Registrace a konfigurace

BackgroundQueue přebírá pole následujících parametrů:

```php
$connection = [
	'serverVersion' => '8.0',
	'driver' => 'pdo_mysql',
	'host' => $_ENV['DB_HOST'],
	'port' => $_ENV['DB_PORT'],
	'user' => $_ENV['DB_USER'],
	'password' => $_ENV['DB_PASSWORD'],
	'dbname' => $_ENV['DB_DBNAME'],
];

$backgroundQueue = new \ADT\BackgroundQueue\BackgroundQueue([
	'callbacks' => [
		'processEmail' => [$mailer, 'process'],
		'processEmail2' => [ // možnost specifikace jiné fronty pro tento callback
			'callback' => [$mailer, 'process'],
			'queue' => 'general',
		],
	]
	'notifyOnNumberOfAttempts' => 5, // počet pokusů o zpracování záznamu před zalogováním
	'tempDir' => $tempDir, // cesta pro uložení zámku proti vícenásobnému spuštění commandu
	'connection' => $connection, // Doctrine\Dbal\Connection
	'queue' => $_ENV['PROJECT_NAME'], // název fronty, do které se ukládají a ze které se vybírají záznamy
	'bulkSize' => 1, // velikost dávky při vkládání více záznamů najednou
	'tableName' => 'background_job', // nepovinné, název tabulky, do které se budou ukládat jednotlivé joby
	'logger' => $logger, // nepovinné, musí implementovat psr/log LoggerInterface
	'onBeforeProcess' => function(array $parameters) {...}, // nepovinné
	'onError' => function(Throwable $e, array $parameters) {...},  // nepovinné
	'onAfterProcess' => function(array $parameters) {...}, // nepovinné
	'onProcessingGetMetadata' => function(array $parameters): ?array {...}, // nepovinné
	'parametersFormat' => \ADT\BackgroundQueue\Entity\BackgroundJob::PARAMETERS_FORMAT_SERIALIZE, // nepovinné, určuje v jakém formátu budou do DB ukládána data v `background_job.parameters` (@see \ADT\BackgroundQueue\Entity\BackgroundJob::setParameters)
]);
```

Potřebné databázové schéma se vytvoři při prvním použití fronty automaticky a také se automaticky aktualizuje, je-li třeba.

### 1.3 Broker (optional)

You can use this package with any message broker. Your producer or consumer just need to implement `ADT\BackgroundQueue\Broker\Producer` or `ADT\BackgroundQueue\Broker\Consumer`. 

Or you can use `php-amqplib/php-amqplib`, for which this library has an ready to use implementation.

#### 1.3.1 php-amqplib installation

Because using of `php-amqplib/php-amqplib` is optional, it doesn't check your installed version against the version with which this package was tested. That's why it's recommended to add to your composer:

```json
{
  "conflict": {
    "php-amqplib/php-amqplib": "<3.0.0 || >=4.0.0"
  }
}
```

This version of `php-amqplib/php-amqplib` also need `ext-sockets`. You can add it to your Dockerfile like this:

```Dockerfile
docker-php-ext-install sockets
```

and then run:

```
composer require php-amqplib/php-amqplib
```

This makes sure you avoid BC break when upgrading `php-amqplib/php-amqplib` in the future.


#### 1.3.1 php-amqplib configuration

```php
$connectionParams = [
    'host' => $_ENV['RABBITMQ_HOST'],
    'user' => $_ENV['RABBITMQ_USER'],
    'password' => $_ENV['RABBITMQ_PASSWORD']
];
$queueParams = [
    'arguments' => ['x-queue-type' => ['S', 'quorum']]
];

$manager = new \ADT\BackgroundQueue\Broker\PhpAmqpLib\Manager($connectionParams, $queueParams);
$producer = new \ADT\BackgroundQueue\Broker\PhpAmqpLib\Producer();
$consumer = new \ADT\BackgroundQueue\Broker\PhpAmqpLib\Consumer();

$backgroundQueue = new \ADT\BackgroundQueue\BackgroundQueue([
	...
	'producer' => $producer,
	'waitingJobExpiration' => 1000, // nepovinné, délka v ms, po které se job pokusí znovu provést, když čeká na dokončení předchozího
]);
```


## 2 Použití

### 2.1 Přidání záznamu do fronty a jeho zpracování

```php
namespace App\Presenters;

use ADT\BackgroundQueue\BackgroundQueue;

class Mailer
{
    private BackgroundQueue $backgroundQueue

    public function __construct(BackgroundQueue $backgroundQueue)
    {
        $this->backgroundQueue = $backgroundQueue;
    }

	public function send(Invoice $invoice) 
	{
		$callbackName = 'processEmail';
		$parameters = [
			'to' => 'hello@appsdevteam.com',
			'subject' => 'Background queue test'
			'text' => 'Anything you want.'
		];
		$serialGroup = 'invoice-' . $invoice->getId();
		$identifier = 'sendEmail-' . $invoice->getId();
		$isUnique = true; // always set to true if a callback on an entity should be performed only once, regardless of how it can happen that it is added to your queue twice
		$availableAt = new \DateTimeImmutable('+1 hour'); // earliest time when the record should be processed

		$this->backgroundQueue->publish($callbackName, $parameters, $serialGroup, $identifier, $isUnique, $availableAt);
	}
	
	public function process(string $to, string $subject, string $text) 
	{
	    // own implementation
	}
}
```

Záznam se uloží ve stavu `READY`.

Parametr `$parameters` může přijímat jakýkoliv běžný typ (pole, objekt, string, ...) či jejich kombinace (pole objektů), a to dokonce i binární data.

Parametr `$serialGroup` je nepovinný - jeho zadáním zajistítě, že všechny joby se stejným serialGroup budou provedeny sériově.

Parametr `$identifier` je nepovinný - pomocí něj si můžete označit joby vlastním identifikátorem a následně pomocí metody `getUnfinishedJobIdentifiers(array $identifiers = [])` zjistit, které z nich ještě nebyly provedeny.

Pokud callback vyhodí `ADT\BackgroundQueue\Exception\PermanentErrorException`, záznam se uloží ve stavu `PERMANENTLY_FAILED` a je potřeba jej zpracovat ručně.

Pokud callback vyhodí `ADT\BackgroundQueue\Exception\WaitingException`, záznam se uloží ve stavu `WAITING` a zkusí se zpracovat při přištím spuštění `background-queue:process` commandu (viz níže). Počítadlo pokusů se nezvyšuje.

Pokud callback vyhodí `ADT\BackgroundQueue\Exception\DieException`, zpracuje se vše dál podle exception, která je v `->getPrevious()` (a pokud žádná není, tak jako při `ADT\BackgroundQueue\Exception\PermanentErrorException`). Poté je (už před další iterací) konzumer ukončen.
Toho lze využít například v `onError`, pokud v aplikaci dojde k uzavření Doctrine Entity manageru a další iterace konzumera by opět skončili chybou.

```php
public function onError(\Throwable $exception) {

	// Příklad 1: Bude to neopakovatelná chyba a konzumer se před další iterací ukončí.
	if (!$this->entityManager->isOpen()) {
		throw new \ADT\BackgroundQueue\Exception\DieException('EM is closed.');
	}

	// Příklad 2: Bude se zpracovávat dle toho, co je v $exception, tedy pokud je $exception instanceof TemporaryErrorException, tak to bude opakovatelná chyba, ale konzumer se také před další iterací ukončí. Používá se například pri deadlocku.
	if (!$this->entityManager->isOpen()) {
		throw new \ADT\BackgroundQueue\DieException('EM is closed. Reason: ' . $exception->getMessage(), $exception->getCode(), $exception);
	}
}
```

Pokud callback vyhodí jakýkoliv jiný error nebo exception implementující `Throwable`, záznam se uloží ve stavu `TEMPORARILY_FAILED` a zkusí se zpracovat při přištím spuštění `background-queue:process` commandu (viz níže). Po `notifyOnNumberOfAttempts` je zaslána notifikace. Prodleva mezi každým dalším opakováním je prodloužena o dvojnásobek času, maximálně však na dobu 16 minut.

Ve všech ostatních případech se záznam uloží jako úspěšně dokončený ve stavu `STATE_FINISHED`.

### 2.2 Commandy

`background-queue:process` Bez využití brokera zpracuje všechny záznamy ve stavu `READY`, `TEMPORARILY_FAILED`, `WAITING` a `BROKER_FAILED`. V případě využití brokera pak záznamy ve stavu `STATE_BACK_TO_BROKER`, `TEMPORARILY_FAILED` a `WAITING`. Command je ideální spouštět cronem každou minutu. V případě použití brokeru je záznam ve stavu `STATE_BACK_TO_BROKER`, `TEMPORARILY_FAILED` a `WAITING` zařazen znovu do brokera a stav je změněn na `READY`. Stav `STATE_BACK_TO_BROKER` je typicky nastaven ručně v databázi těm záznamům, které chceme nechat znovu zpracovat.

`background-queue:clear-finished` Smaže všechny úspěšně zpracované záznamy.

`background-queue:clear-finished 14` Smaže všechny úspěšně zpracované záznamy starší 14 dní.

`background-queue:reload-consumers QUEUE NUMBER` Reloadne NUMBER consumerů pro danou QUEUE.

`background-queue:update-schema` Aktualizuje databázové schéma, pokud je potřeba.

Všechny commandy jsou chráněny proti vícenásobnému spuštění.

### 2.3 Callbacky

Využivát můžete také 2 callbacky `onBeforeProcess` a `onAfterProcess`, v nichž například můžete provést přepinání databází.

### 3 Monitoring

Při spuštění consumera se do tabulky `background_job` do sloupce `pid` uloží aktuální PID procesu. Nejedná se o PID z pohledu systému, ale o PID uvnitř docker kontejneru.

Při dokončení callbacku se do tabulky `background_job` do sloupce `memory` uloží informace o využité paměti před a po dokončení.
Pokud v commandu `background-queue:consume` využíváme parametr `jobs`, od verze PHP 8.2 se před každým jednotlivým zpracováním resetuje "memory peak" (metoda `memory_reset_peak_usage()`).

```
'notRealActual' => memory_get_usage(),
'realActual' => memory_get_usage(true),
'notRealPeak' => memory_get_peak_usage(),
'realPeak' => memory_get_peak_usage(true),
```

### 4 Vkládání po dávkách

Pokud vkládáme větší množství záznamů, může BackgroundQueue vkládat záznamy do DB efektivněji po dávkách pomocí `INSERT INTO table () VALUES (...), (...), ...`.
Velikost dávky se nastavuje parametrem `bulkSize`.
Začátek a konec dávkového vkládání záznamů uvedeme metodami `starBulk` a `endBulk`.
Bez započetí dávkového vkládání metodou `startBulk` bude vždy dávka velikosti 1, nehledě na parametr `bulkSize`.

```php
$this->backgroundQueueService->startBulk();
foreach ($data as $oneJobData) {
    $this->backgroundQueue->publish(...);
}
$this->backgroundQueueService->endBulk();
```

### 5 Prioritizace záznamů

Vkládaným záznamům máme možnost určit jejich prioritu. Později vložený záznam s větší prioritou má přednost při zpracování před dříve vloženým.

V nastavení určím, jaké priority budou využívány. Parametr je nepovinný s výchozí hodnotou `[1]`.

```
$backgroundQueue = new \ADT\BackgroundQueue\BackgroundQueue([
	...
	'priorities' => [10, 15, 20, 25, 30, 35, 40, 45, 50],
	...
]);
```

Přímo u jednotlivých callbacků pro zpracování záznamů lze určit jejich priority. Pokud callback nemá určenou prioritu, použije se nejvyšší dostupná priorita.

Pro příklad mějme následující typy úloh:

* Přepočet ACL
* Stahování dat z API třetích sran
* Rozesílání emailů (např. registrační emaily)

Běžně stahujeme data z API, což může být dlouhotrvající úloha. Pokud je však potřeba přepočítat ACL, nechceme, aby to bylo blokováno stahováním dat z API. A ještě přednostněji chceme odbavit občasné emaily při registraci.

```
$backgroundQueue = new \ADT\BackgroundQueue\BackgroundQueue([
	...
	'priorities' => [10, 15, 20, 25, 30, 35, 40, 45, 50],
	'callbacks' => [
		'email' => [$mailer, 'process'], // záznamy budou mít prioritu 10
		'aclRecalculation' => [
			'callback' => [$aclService, 'process'],
			'priority' => 20,
		],
		'dataImporting' => [
			'callback' => [$apiService, 'process'],
			'priority' => 30,
		],
	],
	...
]);
```

Příkazu `background-queue:consume` máme možnost nastavit pomocí parametru `-p`, jaký rozsah priorit má zpracovávat (např. `-p 10 `, `-p 20-40 `, `-p 25- `, `-p"-20"`, ...).
Můžeme tedy jednoho konzumera vyhradit například na rozesílání registračním emailů (`background-queue:consume -p 10`) a ostatní pro všechny úlohy (`background-queue:consume`).
Tím zajistíme, že rychlé odeslání registračního emailu nebude čekat na dlouho trvající úlohy, protože je odbaví první konzumer.
Ale pokud by se vyskytlo více požadavků na zasílání emailů, po nějaké době je začnou odbavovat všichni konzumeři.

Dále máme možnost prioritu nastavenou pro callback přetížit při vkládání záznamu v metodě `publish`. Například víme, že se jedná o rozesílání newsletterů.
Tedy se jedná o zasílání emailů, ale s nízkou prioritou zpracování.

```
$priority = null; // aplikuje se priorita 10 z nastavení pro callback
if ($isNewsletter) {
	$priority = 25;
}
$this->backgroundQueue->publish('email', $parameters, $serialGroup, $identifier, $isUnique, $availableAt, $priority);
```


### 6 Integrace do frameworků

- Nette - https://github.com/AppsDevTeam/background-queue-nette
- Symfony - https://github.com/AppsDevTeam/background-queue-symfony
