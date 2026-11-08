<?php

namespace App\Http\Requests;

use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Validation of a full layout replacement: at most 50 sections and 250 items in total, each monitor of the
 * workspace at most once on the page.
 */
class StatusPageLayoutRequest extends FormRequest
{
    public const MAX_SECTIONS = 50;

    public const MAX_ITEMS = 250;

    private const CHUNK = 500;

    private bool $checkOwnership = true;

    /**
     * Skips the check that every monitor exists in the workspace, for callers that validate a layout whose
     * monitors are not created yet.
     */
    public function withoutOwnershipChecks(): static
    {
        $this->checkOwnership = false;

        return $this;
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sections' => ['present', 'array', 'max:'.self::MAX_SECTIONS],
            'sections.*' => ['array'],
            'sections.*.name' => ['required', 'string', 'max:190'],
            'sections.*.description' => ['nullable', 'string', 'max:500'],
            'sections.*.items' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'sections.*.items.*' => ['array'],
            'sections.*.items.*.monitor_id' => ['required', 'integer', 'distinct'],
            'sections.*.items.*.display_name' => ['nullable', 'string', 'max:190'],
            'sections.*.items.*.description' => ['nullable', 'string', 'max:500'],
            'sections.*.items.*.expand_children' => ['sometimes', 'boolean'],
            'sections.*.items.*.show_uptime' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $ids = $this->monitorIds();
            if (count($ids) > self::MAX_ITEMS) {
                $validator->errors()->add('sections', 'A page holds at most '.self::MAX_ITEMS.' items in total.');

                return;
            }
            if (! $this->checkOwnership) {
                return;
            }
            $workspaceId = app(CurrentWorkspace::class)->get()->id;
            $found = 0;
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $found += DB::table('monitors')->where('workspace_id', $workspaceId)->whereIn('id', $chunk)->count();
            }
            if ($found !== count($ids)) {
                $validator->errors()->add('sections', 'Every monitor must belong to this workspace.');
            }
        });
    }

    /**
     * The validated layout with defaults applied.
     *
     * @return list<array{name: string, description: string|null, items: list<array<string, mixed>>}>
     */
    public function layout(): array
    {
        $sections = [];
        foreach ($this->sectionInputs() as $section) {
            $items = [];
            $rawItems = $section['items'] ?? [];
            foreach (is_array($rawItems) ? $rawItems : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $monitorId = $item['monitor_id'] ?? 0;
                $items[] = [
                    'monitor_id' => is_numeric($monitorId) ? (int) $monitorId : 0,
                    'display_name' => is_string($item['display_name'] ?? null) ? $item['display_name'] : null,
                    'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
                    'expand_children' => (bool) ($item['expand_children'] ?? false),
                    'show_uptime' => (bool) ($item['show_uptime'] ?? true),
                ];
            }
            $name = $section['name'] ?? '';
            $sections[] = [
                'name' => is_string($name) ? $name : '',
                'description' => is_string($section['description'] ?? null) ? $section['description'] : null,
                'items' => $items,
            ];
        }

        return $sections;
    }

    /**
     * @return list<int>
     */
    private function monitorIds(): array
    {
        $ids = [];
        foreach ($this->layout() as $section) {
            foreach ($section['items'] as $item) {
                $id = $item['monitor_id'];
                $ids[] = is_int($id) ? $id : 0;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sectionInputs(): array
    {
        $sections = $this->input('sections');

        $result = [];
        foreach (is_array($sections) ? $sections : [] as $section) {
            if (is_array($section)) {
                $result[] = array_combine(array_map('strval', array_keys($section)), $section);
            }
        }

        return $result;
    }
}
