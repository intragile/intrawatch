<?php

namespace App\Http\Requests;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation of incident writes. Creating needs the title, impact, first status and first message; updating
 * accepts any subset of the incident's own fields. Text is stored verbatim and rendered safely on output.
 */
class IncidentRequest extends FormRequest
{
    private const CHUNK = 500;

    public function authorize(): bool
    {
        return true;
    }

    private function stored(): ?Incident
    {
        $incident = $this->route('incident');

        return $incident instanceof Incident ? $incident : null;
    }

    /**
     * Whether the request creates an incident; the update request overrides this.
     */
    protected function creating(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->creating() ? 'required' : 'sometimes';

        $rules = [
            'title' => [$presence, 'string', 'max:190'],
            'impact' => [$presence, Rule::enum(IncidentImpact::class)],
            'started_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now', 'after_or_equal:1970-01-02'],
            'monitor_ids' => ['sometimes', 'array', 'max:1000'],
            'monitor_ids.*' => ['integer', 'distinct'],
            'status_page_ids' => ['sometimes', 'array', 'max:100'],
            'status_page_ids.*' => ['integer', 'distinct'],
        ];

        if ($this->creating()) {
            $rules['status'] = ['required', Rule::enum(IncidentStatus::class)];
            $rules['message'] = ['required', 'string', 'max:10000'];
        }

        return $rules;
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $this->checkOwnership($validator, 'monitor_ids', 'monitors');
            $this->checkOwnership($validator, 'status_page_ids', 'status_pages');

            $stored = $this->stored();
            $started = $this->startedAt();
            if ($stored?->resolved_at !== null && $started !== null && $started->gt($stored->resolved_at)) {
                $validator->errors()->add('started_at', 'The start must not be after the resolution.');
            }
        });
    }

    private function checkOwnership(Validator $validator, string $field, string $table): void
    {
        $ids = $this->ids($field);
        if ($ids === null || $ids === []) {
            return;
        }
        $workspaceId = app(CurrentWorkspace::class)->get()->id;

        $found = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $found += DB::table($table)->where('workspace_id', $workspaceId)->whereIn('id', $chunk)->count();
        }
        if ($found !== count($ids)) {
            $validator->errors()->add($field, 'Every id must belong to this workspace.');
        }
    }

    /**
     * The submitted start, or null when the request leaves it alone (a null value on create means "now").
     */
    public function startedAt(): ?CarbonImmutable
    {
        $value = $this->input('started_at');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /**
     * The incident columns present in the request.
     *
     * @return array<string, mixed>
     */
    public function incidentAttributes(): array
    {
        $attributes = [];
        foreach (['title', 'impact'] as $field) {
            if ($this->exists($field)) {
                $attributes[$field] = $this->input($field);
            }
        }
        $started = $this->startedAt();
        if ($started !== null) {
            $attributes['started_at'] = $started;
        }

        return $attributes;
    }

    /**
     * @return list<int>|null Null when the request leaves the monitors alone.
     */
    public function monitorIds(): ?array
    {
        return $this->ids('monitor_ids');
    }

    /**
     * @return list<int>|null
     */
    public function statusPageIds(): ?array
    {
        return $this->ids('status_page_ids');
    }

    /**
     * @return list<int>|null
     */
    private function ids(string $field): ?array
    {
        if (! $this->exists($field)) {
            return null;
        }
        $ids = $this->input($field);

        return is_array($ids) ? array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids))) : [];
    }
}
