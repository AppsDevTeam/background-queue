<?php

namespace ADT\BackgroundQueue\Entity\Enums;

enum CallbackNameEnum: string
{
	case PROCESS_WAITING_JOBS = '_processWaitingJobs';
}
