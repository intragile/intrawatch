<?php

namespace App\Enums;

enum MaintenanceStrategy: string
{
    case Once = 'once';
    case Interval = 'interval';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Cron = 'cron';
}
