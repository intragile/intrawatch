<?php

namespace App\Monitoring\Types\Definitions;

use App\Enums\MonitorType;
use App\Monitoring\Types\JsonPath;
use Illuminate\Validation\Validator;

class HttpDefinition extends AbstractDefinition
{
    /**
     * Whether keyword matching is case-sensitive when a keyword omits case_sensitive. Config defaults are merged
     * one level deep and keyword defaults to null, so the nested default lives here and is applied when the
     * check runs.
     */
    public const KEYWORD_CASE_SENSITIVE = true;

    private const HEADER_NAME = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    public function type(): MonitorType
    {
        return MonitorType::Http;
    }

    public function configRules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https', 'max:'.$this->limit('url_length')],
            'method' => ['required', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'headers' => ['array', 'max:'.$this->limit('max_headers')],
            'headers.*' => ['string', 'max:'.$this->limit('header_value_length')],
            'body' => ['nullable', 'string', 'max:'.$this->limit('body_length')],
            'accepted_status' => ['required', 'array', 'min:1', 'max:20'],
            'accepted_status.*' => ['string', 'regex:/^[1-5]\d{2}(-[1-5]\d{2})?$/'],
            'max_redirects' => ['required', 'integer', 'between:0,'.$this->limit('max_redirects')],
            'keyword' => ['nullable', 'array'],
            'keyword.value' => ['required_with:config.keyword', 'string', 'max:1024'],
            'keyword.mode' => ['required_with:config.keyword', 'in:contains,not_contains'],
            'keyword.case_sensitive' => ['boolean'],
            'json_query' => ['nullable', 'array'],
            'json_query.path' => ['required_with:config.json_query', 'string', 'max:1024', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && JsonPath::isValid($v) ?: $fail('The :attribute must be a valid JSONPath expression.')],
            'json_query.operator' => ['required_with:config.json_query', 'in:==,!=,>,>=,<,<=,contains,exists'],
            'json_query.expected' => ['nullable', 'max:1024'],
            'header_checks' => ['array', 'max:'.$this->limit('max_header_checks')],
            'header_checks.*.name' => ['required', 'string', 'max:255', 'regex:'.self::HEADER_NAME],
            'header_checks.*.operator' => ['required', 'in:equals,contains,exists,not_exists'],
            'header_checks.*.value' => ['nullable', 'string', 'max:'.$this->limit('header_value_length')],
            'auth' => ['array'],
            'auth.type' => ['required_with:config.auth', 'in:none,basic,bearer'],
            'auth.username' => ['nullable', 'string', 'max:255'],
            'mtls' => ['boolean'],
            'proxy' => ['nullable', 'array'],
            'proxy.url' => ['required_with:config.proxy', 'string', 'max:2048', fn (string $a, mixed $v, \Closure $fail) => is_string($v) && $this->isProxyUrl($v) ?: $fail('The :attribute must be an http:// or socks5:// URL with host and port.')],
            'proxy.username' => ['nullable', 'string', fn (string $a, mixed $v, \Closure $fail) => ! is_string($v) || strlen($v) <= 255 ?: $fail('The :attribute may not be longer than 255 bytes.')],
            'tls_verify' => ['boolean'],
            'tls_checks' => ['boolean'],
            'tls_expiry_days' => ['array', 'max:10'],
            'tls_expiry_days.*' => ['integer', 'between:1,3650'],
            'domain_expiry' => ['boolean'],
        ];
    }

    public function secretRules(): array
    {
        return [
            'basic_password' => ['nullable', 'string', 'max:1024'],
            'bearer_token' => ['nullable', 'string', 'max:4096'],
            'mtls_cert' => ['nullable', 'string', 'max:16384'],
            'mtls_key' => ['nullable', 'string', 'max:16384'],
            // RFC 1929 limits SOCKS5 usernames and passwords to 255 bytes.
            'proxy_password' => ['nullable', 'string', fn (string $a, mixed $v, \Closure $fail) => ! is_string($v) || strlen($v) <= 255 ?: $fail('The :attribute may not be longer than 255 bytes.')],
            'secret_headers' => ['nullable', 'array', 'max:'.$this->limit('max_headers')],
            'secret_headers.*' => ['string', 'max:'.$this->limit('header_value_length')],
        ];
    }

    public function defaults(): array
    {
        return [
            'method' => 'GET',
            'headers' => [],
            'body' => null,
            'accepted_status' => ['200-299'],
            'max_redirects' => $this->limit('default_redirects'),
            'keyword' => null,
            'json_query' => null,
            'header_checks' => [],
            'auth' => ['type' => 'none', 'username' => null],
            'mtls' => false,
            'proxy' => null,
            'tls_verify' => true,
            // Applies to https URLs only; plain http targets have no certificate to check.
            'tls_checks' => true,
            'tls_expiry_days' => $this->defaultExpiryDays(),
            'domain_expiry' => true,
        ];
    }

    public function afterValidation(array $config, Validator $v): void
    {
        $errors = $v->errors();
        /** @var array<string, mixed> $data */
        $data = $v->getData();
        $secrets = is_array($data['secrets'] ?? null) ? $data['secrets'] : [];
        $creating = (bool) ($data['_creating'] ?? false);

        $url = $config['url'] ?? null;
        if (is_string($url)) {
            $parts = parse_url($url);
            if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
                $errors->add('config.url', 'The url must not contain credentials; use the authentication settings instead.');
            }
        }

        $statuses = $config['accepted_status'] ?? [];
        if (is_array($statuses)) {
            foreach (array_values($statuses) as $i => $status) {
                if (is_string($status) && preg_match('/^([1-5]\d{2})-([1-5]\d{2})$/', $status, $m) === 1 && (int) $m[1] > (int) $m[2]) {
                    $errors->add("config.accepted_status.$i", 'The range start must not exceed its end.');
                }
            }
        }

        $headers = $config['headers'] ?? [];
        if (is_array($headers)) {
            foreach (array_keys($headers) as $name) {
                if (! is_string($name) || preg_match(self::HEADER_NAME, $name) !== 1) {
                    $errors->add('config.headers', 'Header names may only contain token characters.');
                    break;
                }
            }
        }

        $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        if (($auth['type'] ?? 'none') === 'basic' && $creating && ! $this->filled($secrets, 'basic_password')) {
            $errors->add('secrets.basic_password', 'The basic password is required for basic authentication.');
        }
        if (($auth['type'] ?? 'none') === 'bearer' && $creating && ! $this->filled($secrets, 'bearer_token')) {
            $errors->add('secrets.bearer_token', 'The bearer token is required for bearer authentication.');
        }

        if (($config['mtls'] ?? false) === true) {
            $cert = $secrets['mtls_cert'] ?? null;
            $key = $secrets['mtls_key'] ?? null;
            if (! is_string($cert) || @openssl_x509_read($cert) === false) {
                $errors->add('secrets.mtls_cert', 'A valid PEM client certificate is required when mTLS is enabled.');
            }
            if (! is_string($key) || @openssl_pkey_get_private($key) === false) {
                $errors->add('secrets.mtls_key', 'A valid PEM private key is required when mTLS is enabled.');
            }
        }
    }

    private function limit(string $key): int
    {
        return config()->integer('monitoring.limits.http.'.$key);
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     */
    private function filled(array $secrets, string $key): bool
    {
        return is_string($secrets[$key] ?? null) && $secrets[$key] !== '';
    }

    private function isProxyUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array($parts['scheme'] ?? '', ['http', 'socks5'], true)
            && isset($parts['host'], $parts['port'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && ($parts['path'] ?? '') === '';
    }
}
