# BackgroundQueue

Komponenta umožňuje zpracovávat úkoly na pozadí pomocí cronu nebo AMQP brokera (např. RabbitMQ). Vhodné pro dlouhotrvající requesty, komunikaci s API nebo odesílání webhooků či e-mailů.

Komponenta využívá vlastní doctrine entity manager pro ukládání záznamů do fronty. Tím pádem fungování komponenty není ovlivněno aplikačním entity managerem a naopak.

## 1. Instalace a konfigurace

### 1.1 Instalace
```
composer require adt/background-queue
```

### 1.2 Registrace a konfigurace
```
extensions:
	backgroundQueue: ADT\BackgroundQueue\DI\BackgroundQueueExtension

backgroundQueue:
	callbacks:
		sendEmail: [@App\Model\Mailer, sendEmail]
		...
	notifyOnNumberOfAttempts: 5 # počet pokusů o zpracování záznamu před zalogováním
	tempDir: %tempDir% # cesta pro uložení zámku proti vícenásobnému spuštění commandu
	queue: general # nepovinné, název fronty, do které se ukládají a ze které se vybírají záznamy
	connection: %database% # parametry predavane do Doctrine\Dbal\Connection
	amqpPublishCallback: [@rabbitMq, 'publish'] # nepovinné, callback, který publishne zprávu do brokera
	amqpWaitingQueueName: 'waiting' # nepovinné, název queue, kam ukládat záznamy, které ještě nelze zpracovat

# priklad namapovani entity s Nettrine
nettrine.orm.attributes:
	mapping:
		ADT\BackgroundQueue\Entity: %appDir%/../vendor/adt/background-queue/src/Entity

# priklad namapovani entity s Kdyby
doctrine:
	metadata:
		ADT\BackgroundQueue\Entity: %appDir%/../vendor/adt/background-queue/src/Entity

```

Extension musí být registrována až po Doctrine extension.

Následně je potřeba standardním způsobem vygenerovat doctrine migraci, např.:

```
bin/console migrations:diff
```

## 2 Použití

### 2.1 Přidání záznamu do fronty
```
namespace App\Presenters;

class DefaultPresenter extends \Nette\Application\UI\Presenter 
{
    /** @var \ADT\BackgroundQueue\BackgroundQueue @autowire */
    protected $backgroundQueue;

    public function actionEmailInvoice(Invoice $invoice) 
    {
        $callbackName = 'sendEmail';
        $parameters = [
            'to' => 'hello@appsdevteam.com,
            'subject' => 'Background queue test'
            'text' => 'Anything you want.'
        ];
        $serialGroup = 'invoice-' . $invoice->getId();
        $identifier = 'sendEmail-' . $invoice->getId();
	$isUnique = true; // always set to true if a callback on an entity should be performed only once, regardless of how it can happen that it is added to your queue twice

        $this->backgroundQueue->publish($callbackName, $parameters, $serialGroup, $identifier, $isUnique);
    }
}

```

Záznam se uloží ve stavu `READY`.

Parametr `$parameters` může přijímat jakýkoliv běžný typ (pole, objekt, string, ...) či jejich kombinace (pole objektů), a to dokonce i binární data.

Parametr `$serialGroup` je nepovinný - jeho zadáním zajistítě, že všechny joby se stejným serialGroup budou provedeny sériově.

Parametr `$identifier` je nepovinný - pomocí něj si můžete označit joby vlastním identifikátorem a následně pomocí metody `getUnfinishedJobIdentifiers(array $identifiers = [])` zjistit, které z nich ještě nebyly provedeny.

### 2.2 Zprácování záznamu

```
namespace App\Model;

class Mailer
{
	public function send(string $to, string $subject, string $text) 
	{
	    ...
	}
}
```

Pokud callback vyhodí `ADT\BackgroundQueue\Exception\PermanentErrorException`, záznam se uloží ve stavu `PERMANENTLY_FAILED` a je potřeba jej zpracovat ručně.

Pokud callback vyhodí `ADT\BackgroundQueue\Exception\WaitingException`, záznam se uloží ve stavu `WAITING` a zkusí se zpracovat při přištím spuštění `background-queue:process` commandu (viz níže). Počítadlo pokusů se nezvyšuje.

Pokud callback vyhodí jakýkoliv jiný error nebo exception implementující `Throwable`, záznam se uloží ve stavu `TEMPORARILY_FAILED` a zkusí se zpracovat při přištím spuštění `background-queue:process` commandu (viz níže). Po `notifyOnNumberOfAttempts` je zaslána notifikace. Prodleva mezi každým dalším opakováním je prodloužena o dvojnásobek času, maximálně však na dobu 16 minut.

Ve všech ostatních případech se záznam uloží jako úspěšně dokončený ve stavu `STATE_FINISHED`.

### 2.3 Commandy

`background-queue:process` Zpracuje všechny záznamy ve stavu `READY`, `TEMPORARILY_FAILED`, `WAITING` a `AMQP_FAILED`, v případě využití AMQP brokera pouze záznamy ve stavu `TEMPORARILY_FAILED` a `WAITING`. Command je ideální spouštět cronem každou minutu. V případě použití AMQP brokeru je záznam ve stavu `TEMPORARILY_FAILED` a `WAITING` zařazen znovu do AMQP brokera.

`background-queue:clear-finished` Smaže všechny úspěšně zpracované záznamy.

`background-queue:clear-finished 14` Smaže všechny úspěšně zpracované záznamy starší 14 dní.

Všechny commandy jsou chráněny proti vícenásobnému spuštění.

### 2.4 Napojení na AMQP brokera

Pro odbavování pomocí RabbitMQ můžete využít knihovnu https://github.com/AppsDevTeam/background-queue-rabbitmq a nebo se touto knihovnou inspirovat pro vytvoření vlastního AMQP bridge.

### 2.5 Callbacky

Využivát můžete také 2 callbacky onBeforeProcess a onAfterProcess, v nichž například můžete provést přepinání databází.
