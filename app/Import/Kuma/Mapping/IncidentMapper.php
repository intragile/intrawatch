<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

use App\Import\Kuma\Source\Cast;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Translates a Kuma `incident` row. A pinned, active incident is the one Kuma still shows on its page: it
 * stays open. Kuma incidents have no timeline, so the content becomes the first update.
 */
final class IncidentMapper
{
    private const STAMP = 'Y-m-d\TH:i:s\Z';

    /**
     * @param  array<string, mixed>  $row  A Kuma `incident` row; its dates are UTC.
     */
    public function map(array $row, CarbonImmutable $now): Mapped
    {
        $notes = [];
        $title = trim(Cast::string($row['title'] ?? null));
        $content = trim(Cast::string($row['content'] ?? null));
        if ($content === '') {
            $content = $title;
            $notes[] = Note::of('incident_without_content', 'The incident has no content; its title was used as the first update.');
        }

        $open = Cast::int($row['pin'] ?? 0) === 1 && Cast::int($row['active'] ?? 0) === 1;
        $created = $this->date($row['created_date'] ?? null);
        $started = $created === null || $created->gt($now) ? $now : $created;

        $attributes = [
            'title' => $title,
            'impact' => match (Cast::string($row['style'] ?? null)) {
                'danger' => 'major',
                'warning' => 'minor',
                default => 'none',
            },
            'status' => $open ? 'investigating' : 'resolved',
            'message' => $content,
            'started_at' => $started->format(self::STAMP),
        ];

        if (! $open) {
            $updated = $this->date($row['last_updated_date'] ?? null) ?? $started;
            $resolved = $updated->lt($started) ? $started : ($updated->gt($now) ? $now : $updated);
            $attributes['resolved_at'] = $resolved->format(self::STAMP);
        }

        return new Mapped($attributes, $notes);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $text = trim(Cast::string($value));
        if ($text === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($text, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
