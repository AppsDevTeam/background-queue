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
	'connection' => $connection, // pole parametru predavane do Doctrine\Dbal\Connection nebo DSN
	'queue' => $_ENV['PROJECT_NAME'], // název fronty, do které se ukládají a ze které se vybírají záznamy
	'tableName' => 'background_job', // nepovinné, název tabulky, do které se budou ukládat jednotlivé joby
	'logger' => $logger, // nepovinné, musí implementovat psr/log LoggerInterface
	'onBeforeProcess' => function(array $parameters) {...}, // nepovinné
	'onError' => function(Throwable $e, array $parameters) {...},  // nepovinné
	'onAfterProcess' => function(array $parameters) {...}, // nepovinné
	'onProcessingGetMetadata' => function(array $parameters): ?array {...}, // nepovinné
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

This make sures you avoid BC break when upgrading `php-amqplib/php-amqplib` in the future.


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

Pokud callback vyhodí jakýkoliv jiný error nebo exception implementující `Throwable`, záznam se uloží ve stavu `TEMPORARILY_FAILED` a zkusí se zpracovat při přištím spuštění `background-queue:process` commandu (viz níže). Po `notifyOnNumberOfAttempts` je zaslána notifikace. Prodleva mezi každým dalším opakováním je prodloužena o dvojnásobek času, maximálně však na dobu 16 minut.

Ve všech ostatních případech se záznam uloží jako úspěšně dokončený ve stavu `STATE_FINISHED`.

### 2.2 Commandy

`background-queue:process` Zpracuje všechny záznamy ve stavu `READY`, `TEMPORARILY_FAILED`, `WAITING` a `BROKER_FAILED`, v případě využití brokera pouze záznamy ve stavu `TEMPORARILY_FAILED` a `WAITING`. Command je ideální spouštět cronem každou minutu. V případě použití brokeru je záznam ve stavu `TEMPORARILY_FAILED` a `WAITING` zařazen znovu do brokera a stav je změněn na `READY`.

`background-queue:clear-finished` Smaže všechny úspěšně zpracované záznamy.

`background-queue:clear-finished 14` Smaže všechny úspěšně zpracované záznamy starší 14 dní.

`background-queue:reload-consumers QUEUE NUMBER` Reloadne NUMBER consumerů pro danou QUEUE.

`background-queue:update-schema` Aktualizuje databázové schéma, pokud je potřeba.

Všechny commandy jsou chráněny proti vícenásobnému spuštění.

### 2.3 Callbacky

Využivát můžete také 2 callbacky `onBeforeProcess` a `onAfterProcess`, v nichž například můžete provést přepinání databází.

### 3 Monitoring

Při spuštění consumera se do tabulky `background_job` do sloupce `pid` uloží aktuální PID procesu. Nejedná se o PID z pohledu systému, ale o PID uvnitř docker kontejneru.

### 4 Integrace do frameworků

- Nette - https://github.com/AppsDevTeam/background-queue-nette
- Symfony - https://github.com/AppsDevTeam/background-queue-symfony
