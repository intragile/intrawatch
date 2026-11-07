<?php

namespace App\Http\Requests;

use App\Enums\MaintenanceStrategy;
use App\Models\Maintenance;
use App\Monitoring\Engine\CronSchedule;
use App\Status\Maintenance\MaintenanceSchedule;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Validation of maintenance writes. A definition that could never produce a window is refused, so what is
 * stored is always something the schedule can act on. On update, fields that are not sent keep their stored
 * value for validation, and the fields of the other strategies are nulled on save.
 */
class MaintenanceRequest extends FormRequest
{
    private const COLUMNS = [
        'title', 'description', 'strategy', 'timezone', 'starts_at', 'ends_at', 'start_time', 'duration_minutes',
        'every_days', 'weekdays', 'month_days', 'cron', 'valid_from', 'valid_until', 'active',
    ];

    /** @var array<string, list<string>> */
    private const STRATEGY_FIELDS = [
        'once' => ['starts_at', 'ends_at'],
        'interval' => ['start_time', 'duration_minutes', 'every_days', 'valid_from', 'valid_until'],
        'weekly' => ['start_time', 'duration_minutes', 'weekdays', 'valid_from', 'valid_until'],
        'monthly' => ['start_time', 'duration_minutes', 'month_days', 'valid_from', 'valid_until'],
        'cron' => ['cron', 'duration_minutes', 'valid_from', 'valid_until'],
    ];

    private const CHUNK = 500;

    /**
     * Dates every supported database stores in a timestamp column: MySQL's ends in January 2038.
     */
    public const TIMESTAMP_RANGE = ['after_or_equal:1970-01-02', 'before_or_equal:2037-12-31 23:59:59'];

    public function authorize(): bool
    {
        return true;
    }

    private function stored(): ?Maintenance
    {
        $maintenance = $this->route('maintenance');

        return $maintenance instanceof Maintenance ? $maintenance : null;
    }

    protected function prepareForValidation(): void
    {
        $stored = $this->stored();
        if ($stored === null) {
            return;
        }

        $merge = [];
        foreach (self::COLUMNS as $column) {
            if (! $this->exists($column)) {
                $value = $stored->getAttribute($column);
                $merge[$column] = match (true) {
                    $value instanceof \BackedEnum => $value->value,
                    $value instanceof \DateTimeInterface => $value->format('Y-m-d\TH:i:s\Z'),
                    default => $value,
                };
            }
        }
        $this->merge($merge);
    }

    /**
     * Whether the request creates a maintenance; the update request overrides this.
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
        $strategy = $this->strategyValue();
        // On update, prepareForValidation() fills every column the request leaves out, so `sometimes` only
        // marks the field as optional to send.
        $presence = $this->creating() ? ['required'] : ['sometimes', 'required'];

        return [
            'title' => [...$presence, 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'strategy' => [...$presence, Rule::enum(MaintenanceStrategy::class)],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'starts_at' => [$strategy === 'once' ? 'required' : 'nullable', 'date', ...self::TIMESTAMP_RANGE],
            'ends_at' => ['nullable', 'date', ...self::TIMESTAMP_RANGE],
            'start_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:1,10080'],
            'every_days' => [$strategy === 'interval' ? 'required' : 'nullable', 'integer', 'between:1,3650'],
            'weekdays' => [$strategy === 'weekly' ? 'required' : 'nullable', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'month_days' => [$strategy === 'monthly' ? 'required' : 'nullable', 'array', 'min:1', 'max:32'],
            'month_days.*' => ['integer', 'distinct', fn (string $attribute, mixed $value, \Closure $fail) => ($value === -1 || (is_int($value) && $value >= 1 && $value <= 31)) ? null : $fail('Each month day must be between 1 and 31, or -1 for the last day.')],
            'cron' => [$strategy === 'cron' ? 'required' : 'nullable', 'string', 'max:100'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'active' => ['sometimes', 'boolean'],
            'monitor_ids' => [$this->creating() ? 'required' : 'sometimes', 'array', 'min:1', 'max:1000'],
            'monitor_ids.*' => ['integer', 'distinct'],
            'status_page_ids' => ['sometimes', 'array', 'max:100'],
            'status_page_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $this->checkSchedule($validator);
            $this->checkOwnership($validator, 'monitor_ids', 'monitors');
            $this->checkOwnership($validator, 'status_page_ids', 'status_pages');
        });
    }

    private function checkSchedule(Validator $validator): void
    {
        $strategy = MaintenanceStrategy::tryFrom($this->strategyValue() ?? '');
        if ($strategy === null) {
            return;
        }
        $attributes = $this->maintenanceAttributes();
        $workspaceZone = app(CurrentWorkspace::class)->get()->timezone;

        try {
            // Throws on a timezone or start time the schedule cannot use.
            MaintenanceSchedule::fromArray($attributes + ['created_at' => now()], $workspaceZone);
        } catch (InvalidArgumentException $e) {
            $validator->errors()->add('strategy', $e->getMessage());

            return;
        }

        if ($strategy === MaintenanceStrategy::Once) {
            $start = $attributes['starts_at'];
            $end = $attributes['ends_at'];
            if (is_string($start) && is_string($end) && CarbonImmutable::parse($end)->lte(CarbonImmutable::parse($start))) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }

            return;
        }

        if ($attributes['duration_minutes'] === null) {
            $validator->errors()->add('duration_minutes', 'A recurring maintenance needs a duration of 1 to 10080 minutes.');
        }
        if ($strategy === MaintenanceStrategy::Cron) {
            $zone = is_string($attributes['timezone'] ?? null) ? $attributes['timezone'] : $workspaceZone;
            if (CronSchedule::parse(is_string($attributes['cron']) ? $attributes['cron'] : '', $zone) === null) {
                $validator->errors()->add('cron', 'The cron expression is not valid.');
            }
        } elseif ($attributes['start_time'] === null) {
            $validator->errors()->add('start_time', 'A recurring maintenance needs a start time (HH:MM).');
        }
    }

    /**
     * Every submitted id must belong to the route's workspace.
     */
    private function checkOwnership(Validator $validator, string $field, string $table): void
    {
        $ids = $this->input($field);
        if (! is_array($ids) || $ids === []) {
            return;
        }
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));
        $workspaceId = app(CurrentWorkspace::class)->get()->id;

        $found = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $found += DB::table($table)->where('workspace_id', $workspaceId)->whereIn('id', $chunk)->count();
        }
        if ($found !== count($ids)) {
            $validator->errors()->add($field, 'Every id must belong to this workspace.');
        }
    }

    private function strategyValue(): ?string
    {
        $strategy = $this->input('strategy');

        return is_string($strategy) ? $strategy : null;
    }

    /**
     * The maintenance columns for the stored row: the fields of other strategies are null.
     *
     * @return array<string, mixed>
     */
    public function maintenanceAttributes(): array
    {
        $strategy = $this->strategyValue() ?? '';
        $used = self::STRATEGY_FIELDS[$strategy] ?? [];
        $attributes = [
            'title' => $this->input('title'),
            'description' => $this->input('description'),
            'strategy' => $strategy,
            'timezone' => $this->input('timezone'),
            'active' => $this->boolean('active', true),
        ];
        foreach (['starts_at', 'ends_at', 'start_time', 'duration_minutes', 'every_days', 'weekdays', 'month_days', 'cron', 'valid_from', 'valid_until'] as $field) {
            $value = in_array($field, $used, true) ? $this->input($field) : null;
            if (in_array($field, ['weekdays', 'month_days'], true) && is_array($value)) {
                $value = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $value));
                sort($value);
            }
            $attributes[$field] = $value === '' ? null : $value;
        }

        return $attributes;
    }

    /**
     * @return list<int>|null Null when the request leaves the targets alone.
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

        if (! is_array($ids)) {
            return [];
        }
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));
        sort($ids);

        return $ids;
    }
}
