<?php

namespace ADT\BackgroundQueue\Entity\Enums;

enum ModeEnum: string
{
	case NORMAL = 'normal';
	case UNIQUE = 'unique';
	case RECURRING = 'recurring';
}
