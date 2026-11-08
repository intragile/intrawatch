<?php

namespace App\Http\Resources;

use App\Models\Import;

/**
 * The public JSON shape of an import. The stored file path, the uploader and anything read from the source
 * file other than the import report stay private; the report itself never carries credentials.
 */
final class ImportPresenter
{
    /**
     * @return array{id: string, status: string, source: string, file_name: string|null, file_size: int|null, options: array{history: bool, paused: bool}, report: array{source: array{kind: string, version: string}, dry_run: bool, started_at: string, finished_at: string, duration_ms: int, counts: array<string, array<string, int>>, entries: list<array{entity: string, source_id: int, name: string, outcome: string, target_id: int|null, notes: list<array{code: string, message: string}>, errors?: array<string, list<string>>}>, not_migrated: array{users: int, api_keys: int, docker_hosts: int, remote_browsers: int}, history: array{monitors: int, hour_rows: int, day_rows: int, notes: list<array{code: string, message: string}>}, notes: list<array{code: string, message: string}>}|null, error: string|null, created_at: string|null, started_at: string|null, finished_at: string|null}
     */
    public static function toArray(Import $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status->value,
            'source' => $import->source,
            'file_name' => $import->file_name,
            'file_size' => $import->file_size,
            'options' => self::options($import),
            'report' => self::report($import),
            'error' => $import->error,
            'created_at' => $import->created_at?->toIso8601String(),
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{history: bool, paused: bool}
     */
    private static function options(Import $import): array
    {
        return [
            'history' => (bool) ($import->options['history'] ?? true),
            'paused' => (bool) ($import->options['paused'] ?? false),
        ];
    }

    /**
     * The stored report, as written by ImportReport::toArray().
     *
     * @return array{source: array{kind: string, version: string}, dry_run: bool, started_at: string, finished_at: string, duration_ms: int, counts: array<string, array<string, int>>, entries: list<array{entity: string, source_id: int, name: string, outcome: string, target_id: int|null, notes: list<array{code: string, message: string}>, errors?: array<string, list<string>>}>, not_migrated: array{users: int, api_keys: int, docker_hosts: int, remote_browsers: int}, history: array{monitors: int, hour_rows: int, day_rows: int, notes: list<array{code: string, message: string}>}, notes: list<array{code: string, message: string}>}|null
     */
    private static function report(Import $import): ?array
    {
        /** @var array{source: array{kind: string, version: string}, dry_run: bool, started_at: string, finished_at: string, duration_ms: int, counts: array<string, array<string, int>>, entries: list<array{entity: string, source_id: int, name: string, outcome: string, target_id: int|null, notes: list<array{code: string, message: string}>, errors?: array<string, list<string>>}>, not_migrated: array{users: int, api_keys: int, docker_hosts: int, remote_browsers: int}, history: array{monitors: int, hour_rows: int, day_rows: int, notes: list<array{code: string, message: string}>}, notes: list<array{code: string, message: string}>}|null $report */
        $report = $import->report;

        return $report;
    }
}
