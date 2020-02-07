<?php

namespace ADT\BackgroundQueue\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * QueueEntity
 *
 * @ORM\Table(name="rabbit_queue")
 * @ORM\Entity
 *
 * @method string getCallbackName()
 * @method array getParameters()
 * @method int getState()
 * @method \DateTime getCreated()
 * @method \DateTime getLastAttempt()
 * @method int getNumberOfAttempts()
 * @method string getErrorMessage()
 *
 * @method void setParameters(array $parameters)
 * @method void setCallbackName($callbackName)
 * @method void setState(int $state)
 * @method void setLastAttempt(\DateTime $lastAttempt)
 * @method void setErrorMessage($errorMessage)
 */
class QueueEntity {

	use \Kdyby\Doctrine\MagicAccessors\MagicAccessors;

	const STATE_READY = 1; // připraveno
	const STATE_PROCESSING = 2; // zpracovává se
	const STATE_DONE = 3; // dokončeno
	const STATE_ERROR_TEMPORARY = 4; // opakovatelná chyba (např. nedostupné API)
	const STATE_ERROR_FATAL = 5; // kritická chyba (např. chyba v implementaci)
	const STATE_ERROR_PERMANENT_FIXED = 6; // task s opravenou permanentní chybou, který chceme spustit znovu

	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue
	 * @var integer
	 * @internal
	 */
	protected $id;

	/**
	 * @return integer
	 */
	final public function getId() {
		return $this->id;
	}

	/**
	 * @throws \Nette\NotSupportedException
	 */
	final public function setId() {
		throw new \Nette\NotSupportedException('Entity id is read-only.');
	}

	public function __clone() {
		$this->id = NULL;
	}

	/**
	 * Název callbacku, index z nastavení "callbacks" z neonu
	 *
	 * @var string
	 *
	 * @ORM\Column(name="callbackName", type="string", length=255, nullable=false)
	 */
	protected $callbackName;

	/**
	 * @var array
	 *
	 * @ORM\Column(name="parameters", type="array", nullable=true)
	 */
	protected $parameters;

	/**
	 * Stav - přijato, zpracovává se, dokončeno
	 *
	 * @var integer
	 *
	 * @ORM\Column(name="state", type="integer", length=1, nullable=false)
	 */
	protected $state = self::STATE_READY;

	/**
	 * Datum vytvoření
	 *
	 * @var \DateTime
	 *
	 * @ORM\Column(name="created", type="datetime", nullable=false)
	 */
	protected $created;

	/**
	 * Datum posledního pokusu o zpracování
	 *
  	 * @var \DateTime
   	 *
  	 * @ORM\Column(name="lastAttempt", type="datetime", nullable=true)
   	 */
	protected $lastAttempt;

	/**
	 * Počet opakování (včetně prvního zpracování)
	 *
	 * @var integer
	 *
	 * @ORM\Column(name="numberOfAttempts", type="integer", length=11, nullable=false, options={"default":0})
	 */
	protected $numberOfAttempts;

	/**
	 * Chybová zpráva při stavu STATE_ERROR_FATAL
	 *
	 * @var string
	 *
	 * @ORM\Column(name="errorMessage", type="text", nullable=true)
	 */
	protected $errorMessage;
	
	/**
	 * Optional description
	 *
	 * @var string
	 *
	 * @ORM\Column(name="description", type="string", length=255, nullable=true)
	 */
	protected $description;

	public function __construct() {
		$this->created = new \DateTime;
		$this->numberOfAttempts = 0;
	}

	/**
	 * Vrátí TRUE, pokud je zpráva připravená pro zpracování
	 *
	 * @return bool
	 */
	public function isReadyForProcess() {
		return $this->state === self::STATE_READY || $this->state === self::STATE_ERROR_TEMPORARY;
	}
}
