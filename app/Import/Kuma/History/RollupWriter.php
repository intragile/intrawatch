<?php

declare(strict_types=1);

namespace App\Import\Kuma\History;

use Illuminate\Support\Facades\DB;

/**
 * Buffers rollup rows and upserts them in chunks, far below the placeholder limits of SQLite, MySQL and
 * PostgreSQL. Rows with the same key within the buffer collapse into the last one, which PostgreSQL requires of
 * a single upsert statement. Without writing (dry-run) the rows are only dropped.
 */
final class RollupWriter
{
    public const CHUNK = 500;

    /** @var array<string, array<string, int|string|null>> */
    private array $rows = [];

    public function __construct(private readonly bool $write) {}

    /**
     * @param  array<string, int|string|null>  $row
     */
    public function add(array $row): void
    {
        $this->rows[$row['monitor_id'].'|'.$row['bucket'].'|'.$row['bucket_start']] = $row;
        if (count($this->rows) >= self::CHUNK) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->write && $this->rows !== []) {
            DB::table('check_rollups')->upsert(array_values($this->rows), ['monitor_id', 'bucket', 'bucket_start'], RollupRow::VALUES);
        }
        $this->rows = [];
    }
}
