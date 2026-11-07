<?php

declare(strict_types=1);

namespace App\Import\Kuma\Mapping;

final class TagMapper
{
    public const MAX_NAME = 60;

    /**
     * @param  array<string, mixed>  $row  A Kuma `tag` row.
     */
    public function map(array $row): Mapped|Unmappable
    {
        $name = is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '';
        if ($name === '') {
            return new Unmappable(
                Note::of('tag_name_empty', 'The tag has no name.'),
                ['name' => ['The tag has no name.']],
            );
        }

        $notes = [];
        if (mb_strlen($name) > self::MAX_NAME) {
            $name = mb_substr($name, 0, self::MAX_NAME);
            $notes[] = Note::of('tag_name_truncated', 'The tag name was longer than '.self::MAX_NAME.' characters and was shortened.');
        }

        $color = $row['color'] ?? null;

        return new Mapped(['name' => $name, 'color' => is_string($color) ? $color : null], $notes);
    }
}
