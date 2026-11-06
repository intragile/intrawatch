<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation of monitor writes. The type definition supplies the rules for `config` and
 * `secrets`; this layer completes the input first (defaults, stored values, workspace timezone) so the
 * definitions can stay pure.
 */
abstract class MonitorRequest extends FormRequest
{
    private const COLUMNS = [
        'name', 'description', 'active', 'interval_seconds', 'timeout_seconds', 'retries',
        'retry_interval_seconds', 'degraded_threshold_ms', 'inverted', 'parent_id', 'config', 'secrets',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The monitor being updated, or null when creating.
     */
    abstract protected function stored(): ?Monitor;

    abstract protected function monitorType(): ?MonitorType;

    /**
     * @return array<string, mixed>
     */
    abstract protected function ownRules(MonitorType $type): array;

    protected function prepareForValidation(): void
    {
        $type = $this->monitorType();
        $stored = $this->stored();
        $merge = ['_creating' => $stored === null];

        if ($type !== null) {
            $submitted = $this->input('config', []);
            if (is_array($submitted)) {
                $base = $type->definition()->defaults();
                if ($stored !== null) {
                    $base = array_replace($base, $stored->config);
                }
                $config = array_replace($base, $submitted);
                if (array_key_exists('timezone', $config) && $config['timezone'] === null) {
                    $config['timezone'] = app(CurrentWorkspace::class)->get()->timezone;
                }
                $merge['config'] = $config;
            }

            $submittedSecrets = $this->input('secrets', []);
            if (is_array($submittedSecrets)) {
                $secrets = $stored->secrets ?? [];
                foreach ($submittedSecrets as $key => $value) {
                    if ($value === null) {
                        unset($secrets[$key]);
                    } else {
                        $secrets[$key] = $value;
                    }
                }
                $merge['secrets'] = $secrets;
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->monitorType();
        if ($type === null) {
            return $this->ownRules(MonitorType::Http) + ['type' => ['required', Rule::enum(MonitorType::class)]];
        }

        $definition = $type->definition();
        $rules = $this->ownRules($type) + [
            'name' => [$this->required(), 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'active' => ['boolean'],
            'interval_seconds' => ['integer', 'between:'.$definition->minIntervalSeconds().','.config()->integer('monitoring.limits.interval.max')],
            'timeout_seconds' => ['integer', 'between:'.config()->integer('monitoring.limits.timeout.min').','.config()->integer('monitoring.limits.timeout.max')],
            'retries' => ['integer', 'between:'.config()->integer('monitoring.limits.retries.min').','.config()->integer('monitoring.limits.retries.max')],
            'retry_interval_seconds' => ['integer', 'between:'.config()->integer('monitoring.limits.retry_interval_min').','.config()->integer('monitoring.limits.interval.max')],
            'degraded_threshold_ms' => ['nullable', 'integer', 'min:1'],
            'inverted' => ['boolean'],
            'parent_id' => ['nullable', 'integer'],
            'tags' => ['array', 'max:50'],
            'tags.*.id' => ['required', 'integer', 'distinct', Rule::exists('tags', 'id')->where('workspace_id', app(CurrentWorkspace::class)->get()->id)],
            'tags.*.value' => ['nullable', 'string', 'max:120'],
            'config' => ['array'],
            'secrets' => ['array'],
        ];

        foreach ($definition->configRules() as $key => $rule) {
            $rules['config.'.$key] = $rule;
        }
        foreach ($definition->secretRules() as $key => $rule) {
            $rules['secrets.'.$key] = $rule;
        }

        return $rules;
    }

    protected function required(): string
    {
        return 'required';
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = $this->monitorType();
            if ($type === null) {
                return;
            }
            $definition = $type->definition();

            $typed = array_filter(
                $v->errors()->keys(),
                static fn (string $key): bool => str_starts_with($key, 'config') || str_starts_with($key, 'secrets'),
            );
            if ($typed === []) {
                /** @var array<string, mixed> $config */
                $config = is_array($v->getData()['config'] ?? null) ? $v->getData()['config'] : [];
                $definition->afterValidation($config, $v);
            }

            $this->crossChecks($v, $definition->supportsInverted());
        });
    }

    private function crossChecks(Validator $v, bool $supportsInverted): void
    {
        $stored = $this->stored();
        $interval = $this->input('interval_seconds', $stored->interval_seconds ?? $this->monitorType()?->definition()->defaultIntervalSeconds());
        $timeout = $this->input('timeout_seconds', $stored->timeout_seconds ?? config()->integer('monitoring.limits.timeout.default'));

        if (is_numeric($interval) && is_numeric($timeout)
            && ! $v->errors()->hasAny(['interval_seconds', 'timeout_seconds'])
            && (float) $timeout > config()->float('monitoring.limits.timeout.max_interval_ratio') * (float) $interval) {
            $v->errors()->add(
                $this->has('timeout_seconds') ? 'timeout_seconds' : 'interval_seconds',
                'The timeout may be at most '.config()->float('monitoring.limits.timeout.max_interval_ratio').' of the interval.',
            );
        }

        if (! $supportsInverted && $this->boolean('inverted')) {
            $v->errors()->add('inverted', 'This monitor type does not support inverted results.');
        }
    }

    /**
     * The validated monitor columns, with `config` and `secrets` fully merged.
     *
     * @return array<string, mixed>
     */
    protected function columns(): array
    {
        $validated = $this->validated();
        $columns = [];
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $validated)) {
                $columns[$column] = $validated[$column];
            }
        }
        if (($columns['secrets'] ?? []) === []) {
            $columns['secrets'] = null;
        }

        return $columns;
    }

    /**
     * Tag assignments as `[tag id => ['value' => ?string]]`, or null when the request leaves tags alone.
     *
     * @return array<int, array{value: string|null}>|null
     */
    public function tagAssignments(): ?array
    {
        if (! $this->has('tags')) {
            return null;
        }

        $assignments = [];
        foreach (Arr::wrap($this->validated('tags', [])) as $tag) {
            if (is_array($tag) && is_numeric($tag['id'] ?? null)) {
                $assignments[(int) $tag['id']] = ['value' => is_string($tag['value'] ?? null) ? $tag['value'] : null];
            }
        }

        return $assignments;
    }

    public function parentId(): ?int
    {
        $parent = $this->validated('parent_id');

        return is_numeric($parent) ? (int) $parent : null;
    }

    public function hasParentChange(): bool
    {
        return $this->has('parent_id');
    }
}
