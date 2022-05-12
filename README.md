# BackgroundQueue

Komponenta umožňuje zpracovávat úkoly na pozadí pomocí cronu nebo AMQP brokera (např. RabbitMQ). Vhodné pro dlouhotrvající requesty, komunikaci s API nebo odesílání webhooků či e-mailů.

Komponenta využívá doctrine entity manager pro ukládání záznamů do fronty.

## 1. Instalace a konfigurace

### 1.1 Instalace
```
composer require adt/background-queue
```

### 1.2 Registrace a konfigurace extension
```
extensions:
	backgroundQueue: ADT\BackgroundQueue\DI\BackgroundQueueExtension

backgroundQueue:
	doctrineDbalConnection: @doctrine.dbal.connection
	doctrineOrmConfiguration: @doctrine.orm.configuration
	callbacks:
		sendEmail: [@App\Model\Mailer, sendEmail]
		...
	notifyOnNumberOfAttempts: 5 # počet pokusů o zpracování záznamu před zalogováním
	tempDir: %tempDir% # cesta pro uložení zámku proti vícenásobnému spuštění commandu
	queue: general # nepovinné, název výchozí fronty, do které se ukládají záznamy
	amqpPublishCallback: [@rabbitMq, 'publish'] # nepovinné, callback, který publishne zprávu do brokera
```

## 1.3 Registrace entity
```
nettrine.orm.attributes:
	mapping:
		ADT\BackgroundQueue\Entity: %appDir%/../vendor/adt/background-queue/src/Entity
```

Následně je potřeba vygenerovat migraci.

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
        $callbackname = 'sendEmail';
        $parameters = [
            'to' => 'hello@appsdevteam.com,
            'subject' => 'Background queue test'
            'text' => 'Anything you want.'
        ];
        $serialGroup = 'invoice-' . $invoice->getId();

        $this->backgroundQueue->publish($callbackName, $parameters, $serialGroup);
    }
}

```

Záznam se uloží ve stavu `READY`.

Parametr `$serialGroup` je nepovinný - jeho zadáním zajistítě, že všechny joby se stejným serialGroup budou provedeny sériově.

### 2.2 Zprácování záznamu

```
namespace App\Model;

class Mailer
{
	public function send(array $parameters) 
	{
	    ...
	}
}
```

Pokud callback vrátí false, záznam se uloží ve stavu `TEMPORARILY_FAILED`. Pokud callback vyhodí výjimku, záznam se uloží ve stavu `PERMANENTLY_FAILED` a je potřeba jej zpracovat ručně. Ve všech ostatních případech se záznam uloží jako úspěšně dokončený ve stavu `STATE_FINISHED`.

### 2.3 Commandy

`background-queue:process` zpracuje všechny záznamy ve stavu `READY` a `TEMPORARILY_FAILED`, v případě využití AMQP brokera pouze záznamy ve stavu `TEMPORARILY_FAILED`.

`background-queue:clear` smaže všechny úspěšně zpracované záznamy

`background-queue:clear 14` smaže všechny úspěšně zpracované záznamy starší 14 dní

Všechny commandy jsou chráněny proti vícenásobnému spuštění.