<?php

namespace ADT\BackgroundQueue\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * QueueEntity
 * 
 * @ORM\Table(name="rabbit_queue")
 * @ORM\Entity
 *
 * @method int getId()
 * @method string getCallbackName()
 * @method array getParameters()
 * @method int getState()
 *
 * @method void setId(int $id)
 * @method void setParameters(array $parameters)
 * @method void setCallbackName($callbackName)
 * @method void setState(int $state)
 */
class QueueEntity {

	use \Kdyby\Doctrine\MagicAccessors\MagicAccessors;
	use \Kdyby\Doctrine\Entities\Attributes\Identifier;

	const STATE_READY = 1; // připraveno
	const STATE_PROCESSING = 2; // zpracovává se
	const STATE_DONE = 3; // dokončeno
	const STATE_ERROR_REPEATABLE = 4; // opakovatelná chyba (např. nedostupné API)
	const STATE_ERROR_FATAL = 5; // kritická chyba (např. chyba v implementaci)

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

}
