<?php

namespace App\Actions\Tags;

use App\Models\Tag;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FindOrCreateTag
{
    public const DEFAULT_COLOR = '#6b7280';

    /**
     * Returns the workspace tag with this name, creating it when missing.
     *
     * @return array{0: Tag, 1: bool} The tag and whether it was created by this call.
     */
    public function handle(Workspace $workspace, string $name, ?string $color): array
    {
        $existing = $this->find($workspace, $name);
        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $tag = DB::transaction(function () use ($workspace, $name, $color): Tag {
                $tag = $workspace->tags()->create(['name' => $name, 'color' => self::normalizeColor($color)]);
                Audit::record('tag.created', $tag, ['name' => [null, $tag->name], 'color' => [null, $tag->color]], null, $workspace);

                return $tag;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent writer created it first; case-insensitive collations can also collide.
            $tag = $this->find($workspace, $name) ?? throw $e;

            return [$tag, false];
        }

        return [$tag, true];
    }

    /**
     * Normalises to `#rrggbb` (lowercase); anything that is not a 3 or 6 digit hex colour gets the default.
     */
    public static function normalizeColor(?string $color): string
    {
        $color = Str::lower(trim((string) $color));
        if (preg_match('/^#([0-9a-f]{3})$/', $color, $m) === 1) {
            return '#'.preg_replace('/./', '$0$0', $m[1]);
        }

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : self::DEFAULT_COLOR;
    }

    private function find(Workspace $workspace, string $name): ?Tag
    {
        return Tag::query()->forWorkspace($workspace)->where('name', $name)->first();
    }
}
