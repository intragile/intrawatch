<?php

namespace App\Http\Requests;

use App\Models\StatusPage;
use App\Monitoring\Types\Hostname;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of status page writes. Creating needs the slug and title; updating accepts any subset. The
 * custom domain is normalised (lower-case, no trailing dot) before it is validated and stored.
 */
class StatusPageRequest extends FormRequest
{
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])$/';

    public const RESERVED_SLUGS = ['api', 'admin', 'assets', 'badge', 'feed', 'status', 'setup', 'login'];

    private const COLUMNS = [
        'slug', 'title', 'description', 'custom_domain', 'published', 'locale', 'accent_color', 'logo_url',
        'favicon_url', 'custom_css', 'footer', 'bar_days', 'uptime_days', 'upcoming_maintenance_days',
        'incident_history_days', 'show_latency',
    ];

    public function authorize(): bool
    {
        return true;
    }

    private function stored(): ?StatusPage
    {
        $page = $this->route('statusPage');

        return $page instanceof StatusPage ? $page : null;
    }

    protected function prepareForValidation(): void
    {
        $domain = $this->input('custom_domain');
        if (is_string($domain)) {
            $domain = strtolower(trim($domain));
            $domain = rtrim($domain, '.');
            $this->merge(['custom_domain' => $domain === '' ? null : $domain]);
        }
        $color = $this->input('accent_color');
        if (is_string($color)) {
            $this->merge(['accent_color' => strtolower($color)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $stored = $this->stored();
        $presence = $stored === null ? 'required' : 'sometimes';
        $ignore = $stored?->id;

        return [
            'slug' => [$presence, 'string', 'regex:'.self::SLUG_PATTERN, Rule::notIn(self::RESERVED_SLUGS), Rule::unique('status_pages', 'slug')->ignore($ignore)],
            'title' => [$presence, 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:253', $this->domainRule(), Rule::unique('status_pages', 'custom_domain')->ignore($ignore)],
            'published' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', Rule::in(['en', 'hu'])],
            'accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-f]{6}$/'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048', $this->httpsUrlRule()],
            'favicon_url' => ['sometimes', 'nullable', 'string', 'max:2048', $this->httpsUrlRule()],
            'custom_css' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'footer' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'bar_days' => ['sometimes', 'integer', 'between:7,365'],
            'uptime_days' => ['sometimes', 'integer', Rule::in([1, 7, 30, 90, 365])],
            'upcoming_maintenance_days' => ['sometimes', 'integer', 'between:0,90'],
            'incident_history_days' => ['sometimes', 'integer', 'between:0,90'],
            'show_latency' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The page columns present in the request.
     *
     * @return array<string, mixed>
     */
    public function pageAttributes(): array
    {
        $attributes = [];
        foreach (self::COLUMNS as $field) {
            if ($this->exists($field)) {
                $attributes[$field] = $this->input($field);
            }
        }

        return $attributes;
    }

    private function domainRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }
            if (Hostname::isIp($value) || str_starts_with($value, '[')) {
                $fail('The custom domain must be a hostname, not an IP address.');

                return;
            }
            if (! Hostname::isHostname($value, 2)) {
                $fail('The custom domain must be a valid hostname with at least two labels.');

                return;
            }
            $own = parse_url(config()->string('app.url', ''), PHP_URL_HOST);
            if (is_string($own) && strtolower(rtrim($own, '.')) === $value) {
                $fail('The custom domain must not be the host of this installation.');
            }
        };
    }

    private function httpsUrlRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }
            $parts = parse_url($value);
            $valid = $parts !== false
                && strtolower($parts['scheme'] ?? '') === 'https'
                && ($parts['host'] ?? '') !== ''
                && ! isset($parts['user'])
                && ! isset($parts['pass'])
                && filter_var($value, FILTER_VALIDATE_URL) !== false
                && preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
            if (! $valid) {
                $fail('The :attribute must be an https URL without credentials.');
            }
        };
    }
}
