<?php

namespace ADT\BackgroundQueue\Entity;

use Doctrine\ORM\Mapping as ORM;

trait EntityTrait
{
	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue
	 * @var integer
	 * @internal
	 */
	protected $id;

	/**
	 * Název callbacku, index z nastavení "callbacks" z neonu
	 *
	 * @var string
	 *
	 * @ORM\Column(name="callbackName", type="string", length=255, nullable=true)
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


	public function __construct()
	{
		$this->created = new \DateTime();
		$this->numberOfAttempts = 0;
	}

	public function __clone()
	{
		$this->id = null;
	}

	/**
	 * @return integer
	 */
	final public function getId()
	{
		return $this->id;
	}

	/**
	 * @throws \Exception
	 */
	final public function setId()
	{
		throw new \Exception('Entity id is read-only.');
	}
	
	/**
	 * Vrátí TRUE, pokud je zpráva připravená pro zpracování
	 *
	 * @return bool
	 */
	public function isReadyForProcess()
	{
		return in_array($this->state, EntityInterface::READY_TO_PROCESS_STATES);
	}

	/**
	 * @return string
	 */
	public function getCallbackName()
	{
		return $this->callbackName;
	}

	/**
	 * @param string $callbackName
	 */
	public function setCallbackName($callbackName)
	{
		$this->callbackName = $callbackName;
		return $this;
	}

	/**
	 * @return string|callable
	 */
	public function getDescription()
	{
		return $this->description;
	}

	/**
	 * @param string|callable $description
	 */
	public function setDescription($description)
	{
		$this->description = $description;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getParameters()
	{
		return $this->parameters;
	}

	/**
	 * @param array $parameters
	 */
	public function setParameters(array $parameters)
	{
		$this->parameters = $parameters;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getState()
	{
		return $this->state;
	}

	/**
	 * @param int $state
	 */
	public function setState($state)
	{
		$this->state = $state;
		return $this;
	}

	/**
	 * @return \DateTime
	 */
	public function getCreated()
	{
		return $this->created;
	}

	/**
	 * @return \DateTime
	 */
	public function getLastAttempt()
	{
		return $this->lastAttempt;
	}

	/**
	 * @param \DateTime $lastAttempt
	 */
	public function setLastAttempt(\DateTime $lastAttempt)
	{
		$this->lastAttempt = $lastAttempt;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getNumberOfAttempts()
	{
		return $this->numberOfAttempts;
	}

	/**
	 * @param int $numberOfAttempts
	 */
	public function setNumberOfAttempts($numberOfAttempts)
	{
		$this->numberOfAttempts = $numberOfAttempts;
		return $this;
	}

	public function increaseNumberOfAttempts()
	{
		$this->numberOfAttempts++;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getErrorMessage()
	{
		return $this->errorMessage;
	}

	/**
	 * @param string $errorMessage
	 */
	public function setErrorMessage($errorMessage)
	{
		$this->errorMessage = $errorMessage;
		return $this;
	}
}
