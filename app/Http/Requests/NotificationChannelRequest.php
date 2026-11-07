<?php

namespace App\Http\Requests;

use App\Alerting\Channels\ChannelDefinition;
use App\Alerting\Messages\TemplateRenderer;
use App\Enums\ChannelType;
use App\Models\NotificationChannel;
use App\OpenApi\DocumentedRequestBody;
use App\OpenApi\KeyedObject;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation of notification channel writes (create, update and the unsaved test send). The channel type
 * supplies the rules for `config` and `secrets`; this layer completes the input first (defaults, stored
 * values) so the definitions can stay pure.
 *
 * On update, `config` is merged over the stored config and a secret is replaced only when sent; sending
 * null removes it. The merged secrets are validated, so an update that leaves a required secret alone
 * passes, while removing one is refused.
 */
class NotificationChannelRequest extends FormRequest implements DocumentedRequestBody
{
    private const TITLE_MAX = 200;

    private const BODY_MAX = 4000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The channel being updated, or null when creating or testing an unsaved one.
     */
    private function stored(): ?NotificationChannel
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel ? $channel : null;
    }

    private function channelType(): ?ChannelType
    {
        $stored = $this->stored();
        if ($stored !== null) {
            return $stored->type;
        }
        $type = $this->input('type');

        return is_string($type) ? ChannelType::tryFrom($type) : null;
    }

    private bool $secretsSubmitted = false;

    /**
     * Whether the request body itself carried secrets, as opposed to the stored ones merged in for validation.
     */
    public function secretsSubmitted(): bool
    {
        return $this->secretsSubmitted;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('secrets');
        $this->secretsSubmitted = is_array($raw) && $raw !== [];

        $type = $this->channelType();
        if ($type === null) {
            return;
        }
        $stored = $this->stored();
        $merge = [];

        $submitted = $this->input('config', []);
        if (is_array($submitted)) {
            $merge['config'] = array_replace($type->definition()->defaults(), $stored->config ?? [], $submitted);
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

        $this->merge($merge);
    }

    /**
     * Whether the request creates a channel (or tests an unsaved one); the update request overrides this.
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
        $type = $this->channelType();

        if ($type === null) {
            return ['type' => ['required', Rule::enum(ChannelType::class)]];
        }

        $rules = [
            'type' => $this->creating()
                ? ['required', Rule::enum(ChannelType::class)]
                : ['sometimes', function (string $attribute, mixed $value, \Closure $fail) use ($type): void {
                    if ($value !== $type->value) {
                        $fail('The type of a notification channel cannot be changed.');
                    }
                }],
        ] + $this->fieldRules();

        $definition = $type->definition();
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
    private function fieldRules(): array
    {
        return [
            'name' => [$this->creating() ? 'required' : 'sometimes', 'string', 'max:120'],
            'is_default' => ['boolean'],
            'active' => ['boolean'],
            'apply_to_existing' => ['boolean'],
            'templates' => ['nullable', 'array'],
            'templates.title' => ['nullable', 'string', 'max:'.self::TITLE_MAX, $this->knownVariables(...)],
            'templates.body' => ['nullable', 'string', 'max:'.self::BODY_MAX, $this->knownVariables(...)],
            'config' => ['array'],
            'secrets' => ['array'],
        ];
    }

    /**
     * The type-specific `config` and `secrets` keys are listed by the channel type catalogue; the reference
     * documents them as free-form maps.
     *
     * @return array<string, mixed>
     */
    public function documentedRules(): array
    {
        return ['type' => [$this->creating() ? 'required' : 'sometimes', Rule::enum(ChannelType::class)]] + array_replace($this->fieldRules(), [
            'config' => [KeyedObject::ofAny()],
            'secrets' => [KeyedObject::ofSecrets()],
        ]);
    }

    private function knownVariables(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        $unknown = TemplateRenderer::validate($value);
        if ($unknown !== []) {
            $fail('The template uses unknown variables: '.implode(', ', $unknown).'.');
        }
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = $this->channelType();
            if ($type === null) {
                return;
            }
            $definition = $type->definition();

            $typed = array_filter(
                $v->errors()->keys(),
                static fn (string $key): bool => str_starts_with($key, 'config') || str_starts_with($key, 'secrets'),
            );
            if ($typed !== []) {
                return;
            }

            $data = $v->getData();
            $config = $this->stringKeyed($data['config'] ?? null);
            $secrets = $this->stringKeyed($data['secrets'] ?? null);

            foreach ($definition->requiredSecrets() as $key) {
                $value = $secrets[$key] ?? null;
                if ($value === null || $value === '') {
                    $v->errors()->add('secrets.'.$key, "The secrets.{$key} field is required.");
                }
            }
            if ($v->errors()->isEmpty()) {
                $definition->afterValidation($config, $secrets, $v);
            }
        });
    }

    /**
     * Column values for a new channel, with the defaults applied.
     *
     * @return array<string, mixed>
     */
    public function channelAttributes(): array
    {
        return $this->columns() + ['is_default' => false, 'active' => true, 'templates' => null, 'secrets' => null]
            + ['type' => $this->channelType() ?? throw new \LogicException('Missing channel type.')];
    }

    /**
     * Only the columns the request touches, plus the merged config and secrets.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $columns = $this->columns();
        $changes = [];
        foreach ($columns as $column => $value) {
            if (in_array($column, ['config', 'secrets'], true) || $this->has($column)) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    public function applyToExisting(): bool
    {
        return $this->boolean('apply_to_existing');
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(): array
    {
        $type = $this->channelType() ?? throw new \LogicException('Missing channel type.');
        $definition = $type->definition();
        $validated = $this->validated();

        $columns = [
            'config' => $this->onlyKeys($validated['config'] ?? [], $this->configKeys($definition)),
        ];
        $secrets = $this->onlyKeys($validated['secrets'] ?? [], $this->keysOf($definition->secretRules()));
        $columns['secrets'] = $secrets === [] ? null : $secrets;

        foreach (['name', 'is_default', 'active'] as $column) {
            if (array_key_exists($column, $validated)) {
                $columns[$column] = $validated[$column];
            }
        }
        if (array_key_exists('templates', $validated)) {
            $columns['templates'] = $this->templates($validated['templates']);
        }

        return $columns;
    }

    /**
     * @return array{title?: string, body?: string}|null
     */
    private function templates(mixed $input): ?array
    {
        $templates = [];
        foreach (['title', 'body'] as $key) {
            $value = is_array($input) ? ($input[$key] ?? null) : null;
            if (is_string($value) && trim($value) !== '') {
                $templates[$key] = $value;
            }
        }

        return $templates === [] ? null : $templates;
    }

    /**
     * @return list<string>
     */
    private function configKeys(ChannelDefinition $definition): array
    {
        return array_values(array_unique([...$this->keysOf($definition->configRules()), ...array_keys($definition->defaults())]));
    }

    /**
     * The top-level key names of a rule map whose keys may be dotted paths.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    public static function keysOf(array $rules): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key, 2)[0],
            array_keys($rules),
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $values): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function onlyKeys(mixed $values, array $keys): array
    {
        $values = is_array($values) ? $values : [];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $out[$key] = $values[$key];
            }
        }

        return $out;
    }
}
