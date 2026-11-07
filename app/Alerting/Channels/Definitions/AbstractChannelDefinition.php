<?php

namespace App\Alerting\Channels\Definitions;

use App\Alerting\Channels\ChannelDefinition;
use Illuminate\Validation\Validator;

/**
 * Neutral defaults for the traits most channels share; a channel overrides only what differs.
 */
abstract class AbstractChannelDefinition implements ChannelDefinition
{
    protected const URL_MAX_LENGTH = 2048;

    public function secretRules(): array
    {
        return [];
    }

    public function defaults(): array
    {
        return [];
    }

    public function requiredSecrets(): array
    {
        return [];
    }

    public function supportsDigest(): bool
    {
        return true;
    }

    public function afterValidation(array $config, array $secrets, Validator $v): void {}

    /**
     * Rules for an http(s) URL without embedded credentials.
     *
     * @return list<mixed>
     */
    protected function urlRule(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:'.self::URL_MAX_LENGTH,
            'url:http,https',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }
                $parts = parse_url($value);
                if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                    $fail('The :attribute must not contain credentials.');
                }
            },
        ];
    }

    /**
     * Rules of the single `webhook_url` secret shared by the incoming-webhook channels.
     *
     * @return array<string, mixed>
     */
    protected function webhookUrlSecretRules(): array
    {
        return ['webhook_url' => $this->urlRule()];
    }
}
