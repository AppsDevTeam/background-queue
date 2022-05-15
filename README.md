# BackgroundQueue

Komponenta umožňuje zpracovávat úkoly na pozadí pomocí cronu nebo AMQP brokera (např. RabbitMQ). Vhodné pro dlouhotrvající requesty, komunikaci s API nebo odesílání webhooků či e-mailů.

Komponenta využívá vlastní doctrine entity manager pro ukládání záznamů do fronty. Tím pádem fungování komponenty není ovlivněno aplikačním entity managerem a naopak.

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
	queue: general # nepovinné, název fronty, do které se ukládají a ze které se vybírají záznamy
	amqpPublishCallback: [@rabbitMq, 'publish'] # nepovinné, callback, který publishne zprávu do brokera
```

## 1.3 Registrace entity
```
# pokud používáte PHP 8 atributy
nettrine.orm.attributes:
	mapping:
		ADT\BackgroundQueue\Entity: %appDir%/../vendor/adt/background-queue/src/Entity

# pokud používáte anotace
nettrine.orm.annotations:
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
        $callbackName = 'sendEmail';
        $parameters = [
            'to' => 'hello@appsdevteam.com,
            'subject' => 'Background queue test'
            'text' => 'Anything you want.'
        ];
        $serialGroup = 'invoice-' . $invoice->getId();
        $identifier = 'sendEmail-' . $invoice->getId();

        $this->backgroundQueue->publish($callbackName, $parameters, $serialGroup, $identifier);
    }
}

```

Záznam se uloží ve stavu `READY`.

Parametr `$serialGroup` je nepovinný - jeho zadáním zajistítě, že všechny joby se stejným serialGroup budou provedeny sériově.

Parametr `$identifier` je nepovinný - pomocí něj si můžete označit joby vlastním identifikátorem a následně pomocí metody `getUnfinishedJobIdentifiers(array $identifiers = [])` zjistit, které z nich ještě nebyly provedeny.

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

`background-queue:process` Zpracuje všechny záznamy ve stavu `READY` a `TEMPORARILY_FAILED`, v případě využití AMQP brokera pouze záznamy ve stavu `TEMPORARILY_FAILED`. Command je ideální spouštět cronem každou minutu.

`background-queue:clear` Smaže všechny úspěšně zpracované záznamy.

`background-queue:clear 14` Smaže všechny úspěšně zpracované záznamy starší 14 dní.

Všechny commandy jsou chráněny proti vícenásobnému spuštění.