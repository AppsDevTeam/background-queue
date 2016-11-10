# BackgroundQueue

## 1 Instalace do aplikace
composer:
```
composer require adt/background-queue
```

### 1.1 Registrace extension
```
# app/config/config.neon
extensions:
    rabbitmq: Kdyby\RabbitMq\DI\RabbitMqExtension
    backgroundQueue: ADT\BackgroundQueue\DI\BackgroundQueueExtension
```

### 1.2 Registrace metod pro zpracování
```
# app/config/config.neon
backgroundQueue:
    callbacks:
        test: @App\Facades::process
        ...
```

### 1.3 Entita

### 1.3.1 Použití výchozí entity
```
# app/config/config.neon
doctrine:
    metadata:
        ADT\BackgroundQueue\Entity: %vendorDir%/adt/background-queue/src/Entity
```

### 1.3.2 Použití vlastní entity
```
namespace App\Model\Entity;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class RabbitEntity extends \ADT\BackgroundQueue\Entity\QueueEntity {

    /**
	 * @var \App\Entities\User
	 * @ORM\ManyToOne(targetEntity="User")
	 * @ORM\JoinColumns({
	 *   @ORM\JoinColumn(name="user_id", referencedColumnName="id")
	 * })
	 */
	protected $user;

	/**
	* @var User
	*/
	public function setUser(User $user) {
	    $this->user = $user;
	}
}
```

Registrace vlastní entity:
```
# app/config/config.neon
backgroundQueue:
    queueEntityClass: App\Model\Entity\QueueEntity
```

Při použití výchozí i vlastní entity je potřeba vygenerovat migraci.

### 1.2 Nastavení RabbitMq

```
# app/config/config.neon
includes:
    - rabbitmq.neon
```

```
# app/config/rabbitmq.neon
rabbitmq:
    connection:
        user: guest
        password: guest
    producers:
        generalQueue:
            exchange: {name: 'generalExchange', type: direct}
            queue: {name: 'generalQueue'}
            contentType: text/plain

        # Pokdu se zpráva z obecné fronty vyhodnotí jako opakovatelná chyba (callback vrátí FALSE),
        # přesune se zpráva do fronty "generalQueueError", kde zůstane po dobu nastavenou v "x-message-ttl",
        # po uplynutí této doby se přesune zpět (nastavení "x-dead-letter-exchange")
        generalQueueError:
            exchange: {name: 'generalErrorExchange', type: direct}
            queue: {name: 'generalErrorQueue', arguments: {'x-dead-letter-exchange': ['S', 'generalExchange'], 'x-message-ttl': ['I', 1200000]}} # 20 minut v ms
            contentType: text/plain

    consumers:
        generalQueue:
            exchange: {name: 'generalExchange', type: direct}
            queue: {name: 'generalQueue'}
            callback: @ADT\BackgroundQueue\Queue::process
```

### command pro “inicializaci” front do RabbitMQ
`php www/index.php rabbitmq:setup-fabric`

## 2 Použití

```
namespace App\Presenters;

class DefaultPresenter extends \Nette\Application\UI\Presenter {

    /** @var \ADT\BackgroundQueue\Service @autowire */
    protected $queueService;

    public function actionDefault() {
        $entity = new \App\Model\Entity\QueueEntity;
        $entity->setCallbackName('test');
        $entity->setParameters([
            'messageId' => 123,
        ]);

        $this->queueService->publish($entity);
    }
}
```

### 2.1 Příklad metody pro zpracování

```
namespace App\Facades;

class Rabbit extends Facade {

	/**
	 * @param \ADT\BackgroundQueue\Entity\QueueEntity $entity
	 */
	public function process(\ADT\BackgroundQueue\Entity\QueueEntity $entity) {
	    ...
	}
}
```

Callback (App\Facades\Rabbit::process) vrátí při úspěšném zpracování TRUE (nebo nevrátí nic). Pokud se jedná o opakovatelnou chybu (např. momentálně nedostupné API), musí vrátit FALSE (zpracuje se automaticky později).


## 3 Instalace RabbitMq na server
- Obecné informace: https://filip-prochazka.com/blog/kdyby-rabbitmq-aneb-asynchronni-kdyby-events
- Návod pro homebrew: https://www.rabbitmq.com/install-homebrew.html
- Manage plugin: https://www.rabbitmq.com/management.html

### 3.1 Ruční spuštění consumera:
`php www/index.php rabbitmq:consumer generalQueue`

### 3.2 Spuštění consumera přes *supervisord*
https://filip-prochazka.com/blog/kdyby-rabbitmq-aneb-asynchronni-kdyby-events#toc-nesmrtelny-daemon

```
[program:rabbit]
command=/usr/local/bin/php /Users/honza/Development/knt2.loc/www/index.php rabbitmq:consumer -w -m 500 generalQueue
directory=/Users/honza/Development/knt2.loc
user=www-data
autorestart=true
process_name=%(process_num)02d
numprocs=1
```

