<?php

declare(strict_types=1);

namespace App\Import\Kuma\Source;

enum SourceKind: string
{
    case Sqlite = 'sqlite';
    case Mysql = 'mysql';
    case Json = 'json';
}
