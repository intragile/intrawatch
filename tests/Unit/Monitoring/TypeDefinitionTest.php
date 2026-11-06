<?php

namespace Tests\Unit\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Monitoring\Types\TypeRegistry;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;
use Tests\TestCase;

class TypeDefinitionTest extends TestCase
{
    /**
     * Validate a config the way the request layer does: defaults merged under the input, rules prefixed with
     * `config.` / `secrets.`, then the cross-field hook.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     */
    private function validate(MonitorType $type, array $config, array $secrets = [], bool $creating = true): Validator
    {
        $definition = $type->definition();
        $config = array_merge($definition->defaults(), $config);

        $rules = [];
        foreach ($definition->configRules() as $key => $rule) {
            $rules['config.'.$key] = $rule;
        }
        foreach ($definition->secretRules() as $key => $rule) {
            $rules['secrets.'.$key] = $rule;
        }

        $validator = ValidatorFactory::make(
            ['config' => $config, 'secrets' => $secrets, '_creating' => $creating],
            $rules,
        );
        $validator->after(fn (Validator $v) => $definition->afterValidation($config, $v));
        $validator->passes();

        return $validator;
    }

    public function test_defaults_merge_under_submitted_config(): void
    {
        $d = MonitorType::Http->definition();
        $merged = array_merge($d->defaults(), ['url' => 'https://x.test']);

        $this->assertSame(['200-299'], $merged['accepted_status']);
        $this->assertSame('https://x.test', $merged['url']);
        $this->assertSame(3600, MonitorType::Domain->definition()->minIntervalSeconds());
        $this->assertSame(20, MonitorType::Http->definition()->minIntervalSeconds());
        $this->assertSame(60, MonitorType::Http->definition()->defaultIntervalSeconds());
        $this->assertSame(3600, MonitorType::Tls->definition()->defaultIntervalSeconds());
        $this->assertFalse(MonitorType::Push->definition()->isScheduled());
        $this->assertFalse(MonitorType::Cron->definition()->isScheduled());
        $this->assertFalse(MonitorType::Group->definition()->isScheduled());
        $this->assertTrue(MonitorType::Http->definition()->isScheduled());
        $this->assertFalse(MonitorType::Tls->definition()->supportsInverted());
        $this->assertTrue(MonitorType::Http->definition()->supportsInverted());
        $this->assertSame([30, 14, 7, 1], MonitorType::Tls->definition()->defaults()['expiry_days']);
        $this->assertNull(MonitorType::Cron->definition()->defaults()['timezone']);
    }

    public function test_every_type_has_a_definition_that_reports_its_own_type(): void
    {
        $this->assertCount(count(MonitorType::cases()), TypeRegistry::all());

        foreach (MonitorType::cases() as $type) {
            $this->assertSame($type, TypeRegistry::for($type)->type());
            $this->assertSame($type, $type->definition()->type());
        }
    }

    public function test_status_availability(): void
    {
        $this->assertTrue(MonitorStatus::Up->isAvailable());
        $this->assertTrue(MonitorStatus::Degraded->isAvailable());
        $this->assertTrue(MonitorStatus::Pending->isAvailable());
        $this->assertFalse(MonitorStatus::Down->isAvailable());
        $this->assertFalse(MonitorStatus::Maintenance->isAvailable());
        $this->assertFalse(MonitorStatus::Paused->isAvailable());
    }

    public function test_http_accepts_a_minimal_valid_config(): void
    {
        $this->assertSame([], $this->validate(MonitorType::Http, ['url' => 'https://x.test/health'])->errors()->all());
    }

    public function test_http_rejects_userinfo_and_bad_status_ranges(): void
    {
        $v = $this->validate(MonitorType::Http, ['url' => 'https://u:p@x.test']);
        $this->assertTrue($v->errors()->has('config.url'));

        $v = $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'accepted_status' => ['299-200']]);
        $this->assertTrue($v->errors()->has('config.accepted_status.0'));

        $v = $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'accepted_status' => ['abc']]);
        $this->assertTrue($v->errors()->has('config.accepted_status.0'));

        $v = $this->validate(MonitorType::Http, ['url' => 'ftp://x.test']);
        $this->assertTrue($v->errors()->has('config.url'));

        $v = $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'accepted_status' => ['200', '301-399']]);
        $this->assertSame([], $v->errors()->all());
    }

    public function test_http_validates_json_path_and_limits(): void
    {
        $query = ['path' => '$.data[0].status', 'operator' => '==', 'expected' => 'ok'];
        $this->assertSame([], $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'json_query' => $query])->errors()->all());

        foreach (['$..items[?(@.price < 10 && @.tag == "x")].name', '$[\'a\',\'b\']', '$.list[-1:]', '$..*', '$[?(length(@.c) > 1)]'] as $path) {
            $query['path'] = $path;
            $this->assertSame([], $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'json_query' => $query])->errors()->all(), $path);
        }

        // Paths the evaluator would reject at check time are rejected up front, including bad filters.
        foreach (['data.status', '$.a[?(foo bar baz)]', '$[?(@.a =~ /x/)]', '$[?()]', '$.a b', '$.'] as $path) {
            $query['path'] = $path;
            $v = $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'json_query' => $query]);
            $this->assertTrue($v->errors()->has('config.json_query.path'), $path);
        }

        $v = $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'max_redirects' => 21]);
        $this->assertTrue($v->errors()->has('config.max_redirects'));

        $v = $this->validate(MonitorType::Http, ['url' => 'https://x.test', 'method' => 'TRACE']);
        $this->assertTrue($v->errors()->has('config.method'));
    }

    public function test_http_proxy_credentials_fit_the_socks5_limit(): void
    {
        $proxy = ['url' => 'socks5://proxy.test:1080', 'username' => str_repeat('u', 255)];
        $config = ['url' => 'https://x.test', 'proxy' => $proxy];
        $this->assertSame([], $this->validate(MonitorType::Http, $config, ['proxy_password' => str_repeat('p', 255)])->errors()->all());

        $v = $this->validate(MonitorType::Http, $config, ['proxy_password' => str_repeat('p', 256)]);
        $this->assertTrue($v->errors()->has('secrets.proxy_password'));

        // The limit is in bytes: 128 two-byte characters are 256 bytes.
        $config['proxy']['username'] = str_repeat('é', 128);
        $this->assertTrue($this->validate(MonitorType::Http, $config)->errors()->has('config.proxy.username'));
    }

    public function test_http_basic_auth_needs_a_password_on_create(): void
    {
        $config = ['url' => 'https://x.test', 'auth' => ['type' => 'basic', 'username' => 'bob']];

        $this->assertTrue($this->validate(MonitorType::Http, $config)->errors()->has('secrets.basic_password'));
        $this->assertSame([], $this->validate(MonitorType::Http, $config, ['basic_password' => 'pw'])->errors()->all());
        $this->assertSame([], $this->validate(MonitorType::Http, $config, [], creating: false)->errors()->all());
    }

    public function test_http_mtls_needs_a_parsable_certificate_and_key(): void
    {
        $config = ['url' => 'https://x.test', 'mtls' => true];

        $v = $this->validate(MonitorType::Http, $config, ['mtls_cert' => 'nope', 'mtls_key' => 'nope']);
        $this->assertTrue($v->errors()->has('secrets.mtls_cert'));
        $this->assertTrue($v->errors()->has('secrets.mtls_key'));

        [$cert, $pem] = $this->selfSignedPair();

        $this->assertSame([], $this->validate(MonitorType::Http, $config, ['mtls_cert' => $cert, 'mtls_key' => $pem])->errors()->all());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function selfSignedPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        if (! $key instanceof \OpenSSLAsymmetricKey) {
            $this->fail('Could not generate a test key.');
        }
        // openssl_csr_new takes the key by reference, so hand it a copy to keep $key typed.
        $keyArg = $key;
        $csr = openssl_csr_new(['commonName' => 'iw.test'], $keyArg);
        if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
            $this->fail('Could not generate a test CSR.');
        }
        $x509 = openssl_csr_sign($csr, null, $key, 1);
        if ($x509 === false) {
            $this->fail('Could not sign the test certificate.');
        }
        $cert = '';
        $pem = '';
        openssl_x509_export($x509, $cert);
        openssl_pkey_export($key, $pem);

        if (! is_string($cert) || ! is_string($pem)) {
            $this->fail('Could not export the test key pair.');
        }

        return [$cert, $pem];
    }

    public function test_http_proxy_url_rejects_credentials_query_and_fragment(): void
    {
        $base = ['url' => 'https://x.test'];
        foreach (['http://user:pw@proxy:3128', 'http://user@proxy:3128', 'http://proxy:3128?a=1', 'http://proxy:3128#f', 'ftp://proxy:21', 'http://proxy'] as $url) {
            $v = $this->validate(MonitorType::Http, $base + ['proxy' => ['url' => $url]]);
            $this->assertTrue($v->errors()->has('config.proxy.url'), $url);
        }
        foreach (['http://proxy:3128', 'socks5://proxy.internal:1080'] as $url) {
            $this->assertSame([], $this->validate(MonitorType::Http, $base + ['proxy' => ['url' => $url, 'username' => 'u']])->errors()->all(), $url);
        }
    }

    public function test_dns_names_may_use_underscore_labels_but_hosts_may_not(): void
    {
        foreach (['_dmarc.example.com', 'selector._domainkey.example.com', '_sip._tcp.example.com'] as $name) {
            $this->assertSame([], $this->validate(MonitorType::Dns, ['name' => $name, 'record_type' => 'TXT'])->errors()->all(), $name);
        }
        $this->assertTrue($this->validate(MonitorType::Tcp, ['host' => '_x.example.com', 'port' => 80])->errors()->has('config.host'));
        $this->assertTrue($this->validate(MonitorType::Dns, ['name' => '-bad.example.com', 'record_type' => 'A'])->errors()->has('config.name'));
    }

    public function test_tcp_ping_and_dns_rules(): void
    {
        $this->assertSame([], $this->validate(MonitorType::Tcp, ['host' => 'db.internal', 'port' => 5432])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Tcp, ['host' => 'db.internal', 'port' => 70000])->errors()->has('config.port'));
        $this->assertTrue($this->validate(MonitorType::Tcp, ['host' => 'bad host!', 'port' => 80])->errors()->has('config.host'));
        $this->assertSame([], $this->validate(MonitorType::Tcp, ['host' => '2001:db8::1', 'port' => 80])->errors()->all());

        $this->assertSame([], $this->validate(MonitorType::Ping, ['host' => '10.0.0.1'])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Ping, ['host' => '10.0.0.1', 'count' => 6])->errors()->has('config.count'));

        $this->assertSame([], $this->validate(MonitorType::Dns, ['name' => 'x.test', 'record_type' => 'MX', 'resolver' => '1.1.1.1:53'])->errors()->all());
        $this->assertSame([], $this->validate(MonitorType::Dns, ['name' => 'x.test', 'record_type' => 'A', 'resolver' => '[::1]:53'])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Dns, ['name' => 'x.test', 'record_type' => 'A', 'resolver' => 'dns.google'])->errors()->has('config.resolver'));
        $this->assertTrue($this->validate(MonitorType::Dns, ['name' => 'x.test', 'record_type' => 'LOC'])->errors()->has('config.record_type'));
        $this->assertTrue($this->validate(MonitorType::Dns, ['name' => 'x.test', 'record_type' => 'A', 'match' => 'some'])->errors()->has('config.match'));
    }

    public function test_tls_and_domain_rules(): void
    {
        $this->assertSame([], $this->validate(MonitorType::Tls, ['host' => 'x.test'])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Tls, ['host' => 'x.test', 'starttls' => 'ftp'])->errors()->has('config.starttls'));

        $this->assertSame([], $this->validate(MonitorType::Domain, ['domain' => 'example.com'])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Domain, ['domain' => 'localhost'])->errors()->has('config.domain'));
        $this->assertSame([], $this->validate(MonitorType::Domain, ['domain' => 'www.example.co.uk'])->errors()->all());
        $this->assertSame([], $this->validate(MonitorType::Domain, ['domain' => 'münchen.de'])->errors()->all());
        $this->assertSame([], $this->validate(MonitorType::Domain, ['domain' => 'xn--mnchen-3ya.de'])->errors()->all());
        foreach (['co.uk', 'uk', '192.0.2.1', 'example.invalid', '_whois.hu'] as $domain) {
            $this->assertTrue($this->validate(MonitorType::Domain, ['domain' => $domain])->errors()->has('config.domain'), $domain);
        }
        $this->assertStringContainsString('registrable domain', (string) $this->validate(MonitorType::Domain, ['domain' => 'co.uk'])->errors()->first('config.domain'));
    }

    public function test_push_rules(): void
    {
        $this->assertSame([], $this->validate(MonitorType::Push, [])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Push, ['grace_seconds' => 3601])->errors()->has('config.grace_seconds'));
    }

    public function test_cron_rejects_invalid_expression_and_group_quorum_needs_number(): void
    {
        $this->assertSame([], $this->validate(MonitorType::Cron, ['schedule' => '*/5 * * * *', 'timezone' => 'Europe/Budapest'])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Cron, ['schedule' => 'not a cron'])->errors()->has('config.schedule'));
        $this->assertTrue($this->validate(MonitorType::Cron, ['schedule' => '* * * * *', 'timezone' => 'Mars/Base'])->errors()->has('config.timezone'));
        $this->assertTrue($this->validate(MonitorType::Cron, ['schedule' => '* * * * *', 'max_runtime_seconds' => 0])->errors()->has('config.max_runtime_seconds'));

        $this->assertSame([], $this->validate(MonitorType::Group, [])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Group, ['mode' => 'quorum'])->errors()->has('config.quorum'));
        $this->assertTrue($this->validate(MonitorType::Group, ['mode' => 'quorum', 'quorum' => 0])->errors()->has('config.quorum'));
        $this->assertSame([], $this->validate(MonitorType::Group, ['mode' => 'quorum', 'quorum' => 2])->errors()->all());
        $this->assertTrue($this->validate(MonitorType::Group, ['mode' => 'most'])->errors()->has('config.mode'));
    }
}
