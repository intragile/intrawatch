<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Pagination\CursorPage;
use App\Models\Tag;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class TagController extends Controller
{
    private const DEFAULT_COLOR = '#6b7280';

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        return CursorPage::from($workspace->tags(), $request, function (Model $model): array {
            return $this->payload($model instanceof Tag ? $model : throw new \LogicException('Expected a tag.'));
        });
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:60', $this->unique($workspace)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $name = $request->string('name')->toString();
        $color = $request->has('color') ? Str::lower($request->string('color')->toString()) : self::DEFAULT_COLOR;

        $tag = DB::transaction(function () use ($workspace, $name, $color): Tag {
            $tag = $workspace->tags()->create(['name' => $name, 'color' => $color]);
            Audit::record('tag.created', $tag, ['name' => [null, $tag->name], 'color' => [null, $tag->color]]);

            return $tag;
        });

        return response()->json(['data' => $this->payload($tag)], 201);
    }

    public function update(Request $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:60', $this->unique($workspace)->ignore($tag->id)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $data = [];
        if ($request->has('name')) {
            $data['name'] = $request->string('name')->toString();
        }
        if ($request->has('color')) {
            $data['color'] = Str::lower($request->string('color')->toString());
        }

        DB::transaction(function () use ($tag, $data): void {
            $before = $tag->only(array_keys($data));
            $tag->update($data);

            $diff = [];
            foreach ($before as $field => $old) {
                if ($old !== $tag->getAttribute($field)) {
                    $diff[$field] = [$old, $tag->getAttribute($field)];
                }
            }
            if ($diff !== []) {
                Audit::record('tag.updated', $tag, $diff);
            }
        });

        return response()->json(['data' => $this->payload($tag)]);
    }

    public function destroy(Workspace $workspace, Tag $tag): Response
    {
        DB::transaction(function () use ($tag): void {
            $tag->delete();
            Audit::record('tag.deleted', $tag, ['name' => [$tag->name, null]]);
        });

        return response()->noContent();
    }

    private function unique(Workspace $workspace): Unique
    {
        return Rule::unique('tags', 'name')->where('workspace_id', $workspace->id);
    }

    /**
     * The `(int)` cast is a no-op at runtime; without it Scramble documents the id of tag.update (a
     * route-bound tag) as a string.
     *
     * @return array{id: int, name: string, color: string}
     */
    private function payload(Tag $tag): array
    {
        return ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color];
    }
}
