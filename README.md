# BackgroundQueue

Komponenta umožňuje zpracovávat úkoly na pozadí pomocí cronu nebo AMQP (např. RabbitMQ). Vhodné pro dlouhotrvající requesty, komunikaci s API nebo odesílání webhooků či e-mailů.

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
	entityClass: App\Model\Entity\BackgroundJob # doctrine entita představující záznam
	doctrineDbalConnection: @doctrine.dbal.connection
	doctrineOrmConfiguration: @doctrine.orm.configuration
	notifyOnNumberOfAttempts: 5 # počet neúspěšných pokusů zpracování záznamu pro zalogování
	callbacks: # při 
		sendEmail: [@App\Model\Mailer, sendEmail]
		...
	defaultQueue: cz # nepovinné, název výchozí fronty, do které se ukládají záznamy
```

## 1.3 Vytvoření entity a migrace
```
namespace App\Model\Entity;

use ADT\BackgroundQueue\Entity\EntityInterface;
use ADT\BackgroundQueue\Entity\EntityTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class BackgroundJob implements EntityInterface
{
	use EntityTrait;
}
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

    public function actionDefault() 
    {
        $callbackname = 'sendEmail;
        $parameters = [
            'to' => 'hello@appsdevteam.com,
            'subject' => 'Background queue test'
            'text' => 'Anything you want.'
        ];
        $queue = 'cz';

        $this->backgroundQueue->publish($callbackName, $parameters, $queue);
    }
}
```

Záznam se uloží ve stavu `STATE_READY`.

### 2.2 Zprácování záznamu

```
namespace App\Model;

class Mailer
{
	public function send(\App\Model\Entity\BackgroundJob $entity) 
	{
	    ...
	}
}
```

Pokud callback vrátí false, záznam se uloží ve stavu `TEMPORARILY_FAILED`. Pokud callback vyhodí výjimku, záznam se uloží ve stavu `PERMANENTLY_FAILED` a je potřeba jej zpracovat ručně. Ve všech ostatních případech se záznam uloží jako úspěšně dokončený ve stavu `STATE_FINISHED`.

### 2.3 Commandy

`background-queue:process` zpracuje všechny nezpracované záznamy

`background-queue:process-temporarily-failed` zpracuje záznam s ID 1 nehledě na jeho stav

`background-queue:clear` smaže všechny úspěšně zpracované záznamy

`background-queue:clear 14` smaže všechny úspěšně zpracované záznamy starší 14 dní
