<?php

namespace ADT\BackgroundQueue\Entity\Enums;

enum CallbackNameEnum: string
{
	/**
	 * @deprecated Interní job, kterým se dřív WAITING joby obsluhovaly pollem. Nahradilo ho probuzení
	 * nástupce hned po dokončení předchůdce (BackgroundQueue::promoteWaitingSuccessor) se záchrannou sítí
	 * v process(). Knihovna už tenhle callback nikde neregistruje; zbylé řádky v ostrých databázích je
	 * potřeba smazat ručně (viz README, kapitola „Upgrade"). Konstanta tu zbyla jen jako pojmenování toho,
	 * co se má smazat.
	 */
	case PROCESS_WAITING_JOBS = '_processWaitingJobs';
}
