<?php

namespace App\Alerting\Channels\Definitions;

use App\Enums\ChannelType;
use Illuminate\Validation\Validator;

class WebhookDefinition extends AbstractChannelDefinition
{
    private const HEADER_NAME = '/^[A-Za-z0-9-]{1,64}\z/';

    /** Headers the sender sets itself; lower-cased. */
    private const RESERVED_HEADERS = ['host', 'content-length', 'content-type', 'transfer-encoding'];

    public function type(): ChannelType
    {
        return ChannelType::Webhook;
    }

    public function destinationKeys(): array
    {
        return ['url'];
    }

    public function sensitiveConfigKeys(): array
    {
        return ['url'];
    }

    public function configRules(): array
    {
        return [
            'url' => $this->urlRule(),
            'method' => ['required', 'in:POST,PUT'],
            'format' => ['required', 'in:json,form'],
            'headers' => ['array', 'max:20'],
            'headers.*' => ['string', 'max:1024'],
        ];
    }

    public function secretRules(): array
    {
        return [
            'secret_headers' => ['nullable', 'array', 'max:20'],
            'secret_headers.*' => ['string', 'max:1024'],
            'signing_secret' => ['nullable', 'string', 'min:16', 'max:256'],
        ];
    }

    public function defaults(): array
    {
        return ['method' => 'POST', 'format' => 'json', 'headers' => []];
    }

    public function supportsDigest(): bool
    {
        return false;
    }

    public function afterValidation(array $config, array $secrets, Validator $v): void
    {
        $this->checkHeaders($config['headers'] ?? [], 'config.headers', $v);
        $this->checkHeaders($secrets['secret_headers'] ?? [], 'secrets.secret_headers', $v);
    }

    private function checkHeaders(mixed $headers, string $key, Validator $v): void
    {
        if (! is_array($headers) || $headers === []) {
            return;
        }
        if (array_is_list($headers)) {
            $v->errors()->add($key, 'The headers must be a map of header names to values.');

            return;
        }
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            if (preg_match(self::HEADER_NAME, $name) !== 1) {
                $v->errors()->add($key, 'Header names may only contain letters, digits and hyphens (up to 64 characters).');

                return;
            }
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                $v->errors()->add($key, "The {$name} header is set by the sender and cannot be overridden.");

                return;
            }
            if (is_string($value) && preg_match('/[\r\n]/', $value) === 1) {
                $v->errors()->add($key, 'Header values must not contain line breaks.');

                return;
            }
        }
    }
}
