<?php

namespace App\Http\Requests;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Monitoring\Types\TypeDefinition;
use App\Monitoring\Types\TypeRegistry;
use App\OpenApi\DocumentedRequestBody;
use App\OpenApi\KeyedObject;
use App\Support\CurrentWorkspace;
use App\Support\SecretKeys;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation of monitor writes. The type definition supplies the rules for `config` and
 * `secrets`; this layer completes the input first (defaults, stored values, workspace timezone) so the
 * definitions can stay pure.
 *
 * On update, a secret is kept unless sent (null removes it). An update that changes a destination key of the
 * type (where the check connects, so where the secrets go) must send every stored secret again or remove it
 * with null; otherwise it is refused with a `secrets` error.
 */
abstract class MonitorRequest extends FormRequest implements DocumentedRequestBody
{
    private const COLUMNS = [
        'name', 'description', 'active', 'interval_seconds', 'timeout_seconds', 'retries',
        'retry_interval_seconds', 'degraded_threshold_ms', 'inverted', 'parent_id', 'config', 'secrets',
    ];

    /**
     * The secret names in the request body, including those sent as null.
     *
     * @var list<string>
     */
    private array $submittedSecretKeys = [];

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
        $raw = $this->input('secrets');
        $this->submittedSecretKeys = is_array($raw) ? array_map(strval(...), array_keys($raw)) : [];

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
        $rules = $this->ownRules($type) + $this->fieldRules($definition->minIntervalSeconds());

        foreach ($definition->configRules() as $key => $rule) {
            $rules['config.'.$key] = $rule;
        }
        foreach ($definition->secretRules() as $key => $rule) {
            $rules['secrets.'.$key] = $rule;
        }

        return $rules;
    }

    /**
     * The rules of the type-independent fields.
     *
     * @return array<string, mixed>
     */
    private function fieldRules(int $minIntervalSeconds): array
    {
        return [
            'name' => [$this->required(), 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'active' => ['boolean'],
            'interval_seconds' => ['integer', 'between:'.$minIntervalSeconds.','.config()->integer('monitoring.limits.interval.max')],
            'timeout_seconds' => ['integer', 'between:'.config()->integer('monitoring.limits.timeout.min').','.config()->integer('monitoring.limits.timeout.max')],
            'retries' => ['integer', 'between:'.config()->integer('monitoring.limits.retries.min').','.config()->integer('monitoring.limits.retries.max')],
            'retry_interval_seconds' => ['integer', 'between:'.config()->integer('monitoring.limits.retry_interval_min').','.config()->integer('monitoring.limits.interval.max')],
            'degraded_threshold_ms' => ['nullable', 'integer', 'min:1'],
            'inverted' => ['boolean'],
            'parent_id' => ['nullable', 'integer'],
            'tags' => ['array', 'max:50'],
            'tags.*.id' => ['required', 'integer', 'distinct', Rule::exists('tags', 'id')->where($this->inCurrentWorkspace(...))],
            'tags.*.value' => ['nullable', 'string', 'max:120'],
            'config' => ['array'],
            'secrets' => ['array'],
            'notification_channel_ids' => ['array', 'max:50'],
            'notification_channel_ids.*' => ['integer', 'distinct', Rule::exists('notification_channels', 'id')->where($this->inCurrentWorkspace(...))],
            'alerting' => ['array'],
            'alerting.resend_interval_minutes' => ['integer', 'between:0,10080'],
            'alerting.notify_degraded' => ['boolean'],
            'alerting.degraded_alert_after_seconds' => ['integer', 'between:0,86400'],
            'alerting.notify_changes' => ['boolean'],
        ];
    }

    /**
     * Scopes an existence check to the current workspace. Resolved when the rule runs, so the rules can be
     * built without a workspace (the API reference does that).
     *
     * @param  Builder  $query
     */
    private function inCurrentWorkspace($query): void
    {
        $query->where('workspace_id', app(CurrentWorkspace::class)->get()->id);
    }

    /**
     * The type-specific `config` and `secrets` keys are listed by the monitor type catalogue; the reference
     * documents them as free-form maps. The interval floor shown is the lowest of all types.
     *
     * @return array<string, mixed>
     */
    public function documentedRules(): array
    {
        $minInterval = PHP_INT_MAX;
        foreach (TypeRegistry::all() as $definition) {
            $minInterval = min($minInterval, $definition->minIntervalSeconds());
        }

        return ['type' => [$this->required(), Rule::enum(MonitorType::class)]] + array_replace($this->fieldRules($minInterval), [
            'config' => [KeyedObject::ofAny()],
            'secrets' => [KeyedObject::ofSecrets()],
        ]);
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
            $this->checkDestinationChange($definition, $v);

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

    /**
     * Refuses an update that points the stored secrets at a new destination without sending them again.
     */
    private function checkDestinationChange(TypeDefinition $definition, Validator $v): void
    {
        $stored = $this->stored();
        if ($stored === null || ($stored->secrets ?? []) === []) {
            return;
        }
        $after = $v->getData()['config'] ?? null;
        if (! is_array($after)) {
            return;
        }
        $before = array_replace($definition->defaults(), $stored->config);
        if (! SecretKeys::destinationChanged($before, $after, $definition->destinationKeys())) {
            return;
        }
        $missing = SecretKeys::unresent($stored->secrets, $this->submittedSecretKeys);
        if ($missing === []) {
            return;
        }
        $v->errors()->add('secrets', 'Re-enter the stored secrets ('.implode(', ', $missing).') when changing the destination, or remove them with null.');
        foreach ($missing as $key) {
            $v->errors()->add('secrets.'.$key, 'Re-enter this secret when changing the destination, or remove it with null.');
        }
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

        $this->checkPingCadence($v, $timeout);

        if (! $supportsInverted && $this->boolean('inverted')) {
            $v->errors()->add('inverted', 'This monitor type does not support inverted results.');
        }
    }

    /**
     * Ping sends its packets one second apart, so the last one leaves after `count - 1` seconds.
     */
    private function checkPingCadence(Validator $v, mixed $timeout): void
    {
        if ($this->monitorType() !== MonitorType::Ping || ! is_numeric($timeout) || $v->errors()->has('config.count')) {
            return;
        }

        $stored = $this->stored();
        $config = $this->input('config');
        $count = is_array($config) && isset($config['count']) ? $config['count'] : ($stored->config['count'] ?? 1);

        if (is_numeric($count) && (int) $count - 1 >= (float) $timeout && ! $v->errors()->has('timeout_seconds')) {
            $v->errors()->add(
                $this->has('timeout_seconds') ? 'timeout_seconds' : 'config.count',
                'Ping sends packets one second apart, so the timeout must be longer than count minus one seconds.',
            );
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

        return $columns + $this->alertingColumns();
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

    /**
     * Channel ids to bind the monitor to, or null when the request leaves the binding alone.
     *
     * @return list<int>|null
     */
    public function notificationChannelIds(): ?array
    {
        if (! $this->has('notification_channel_ids')) {
            return null;
        }

        $ids = [];
        foreach (Arr::wrap($this->validated('notification_channel_ids', [])) as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * The alerting columns the request sets; absent settings are left out so an update merges partially.
     *
     * @return array<string, bool|int>
     */
    protected function alertingColumns(): array
    {
        $alerting = $this->validated('alerting', []);
        $columns = [];
        if (is_array($alerting)) {
            foreach (Monitor::ALERTING_COLUMNS as $column) {
                if (array_key_exists($column, $alerting) && (is_int($alerting[$column]) || is_bool($alerting[$column]) || is_numeric($alerting[$column]))) {
                    $columns[$column] = in_array($column, ['notify_degraded', 'notify_changes'], true)
                        ? filter_var($alerting[$column], FILTER_VALIDATE_BOOL)
                        : (int) $alerting[$column];
                }
            }
        }

        return $columns;
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
