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

### 1.2 Registrace metod pro zpracování a ostatní nastavení
```
# app/config/config.neon
backgroundQueue:
	queueEntityClass: ADT\BackgroundQueue\Entity\QueueEntity # Výchozí entita
	noopMessage: noop	# Název příkazu pro zaslání noop příkazu consumerovi (kombinuje se s `supervisor.numprocs` a [adt/after-deploy](https://github.com/AppsDevTeam/AfterDeploy/#installation--usage)
	supervisor:
		numprocs: 1	# Počet consumerů
		startsecs: 1	# [sec] - Jedno zpracování je případně uměle protaženo sleepem, aby si *supervisord* nemyslel, že se proces ukončil moc rychle
	lazy: TRUE	# Lazy callbacky (viz další kapitolu)
	clearOlderThan: '31 days' # kolik dní staré záznamy budou mazány
	notifyOnNumberOfAttempts: 5 # počet pokusů zpracování fronty pro zaslání mailu
	callbacks:
		test: @App\Facades::process
		...
```

### 1.3 Nastavení callbacků lazy

Defaultně je lazy zapnuto. Hodí se, pokud nechcete služby předávané do callbacků inicializovat ihned při startu aplikace.

```
# app/config/config.neon
backgroundQueue:
	lazy: on	# default: on
	callbacks:
		test: @App\Facades::process
		...
```

Nebo jen pro některé callbacky:

```
# app/config/config.neon
backgroundQueue:
	lazy:
		test: on
		...
	callbacks:
		test: @App\Facades::process
		...
```

### 1.4 Entita

### 1.4.1 Použití výchozí entity
```
# app/config/config.neon
doctrine:
    metadata:
        ADT\BackgroundQueue\Entity: %vendorDir%/adt/background-queue/src/Entity
```

### 1.4.2 Použití vlastní entity
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

### 1.5 Nastavení RabbitMq

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

### 1.6 Deaktivace zpracovani přes RabbitMq
Pokud je nainstalovaný balíček Kdyby/RabbitMq, záznam se automaticky vloží do RabbitMQ fronty.
Toto chování je možné vypnout:

```
# app/config/config.neon
backgroundQueue:
	useRabbitMq: false
```

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

###2.1 Použití bez RabbitMQ

```
namespace App\Presenters;

class DefaultPresenter extends \Nette\Application\UI\Presenter {

    /** @var \ADT\BackgroundQueue\Service @autowire */
    protected $queueService;

    public function actionDefault() {
        $entity = new \App\Model\Entity\QueueEntity;
        $entity->setClosure(function (QueueEntity $entity) {
            ...
        });

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

### command pro smazání provedených front z db
`php www/index.php backgroundQueue:clear` 
nebo jen pro vybrané callbacky [-v  pro vypsani SUCCESS po skončení scriptu]
`php www/index.php backgroundQueue:clear -v backgroundMail sms` 

### command který zavolá callback pro všechny záznamy z DB s nastaveným stavem STATE_WAITING_FOR_MANUAL_QUEUING
`php www/index.php adt:backgroundQueue:processWaitingForManualQueuing` 

### command který zavolá callback pro všechny záznamy z DB s nastaveným stavem STATE_ERROR_TEMPORARY
`php www/index.php adt:backgroundQueue:processTemporaryErrors` 

### command pro všechny nezprac. zaznamy z DB a zpracuje je (bez rabbita a consumeru)
`php www/index.php adt:backgroundQueue:process`

 Command příjmá jeden parametr - ID záznamu. 
 Spuštění je omezené na záznamy ve stavu 1, 4, 5 a 6 (READY, ERROR_TEMPORARY, ERROR_FATAL a WAITING_FOR_MANUAL_QUEUING)
