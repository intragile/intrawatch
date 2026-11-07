<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Analyzing = 'analyzing';
    case Analyzed = 'analyzed';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
