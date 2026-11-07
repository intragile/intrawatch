<?php

namespace Tests\Unit\Alerting;

use App\Alerting\Transport\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_errors_are_redacted(): void
    {
        $msg = Redactor::redact('POST https://api.telegram.org/bot123:ABCsecretTOKENxyz/sendMessage failed', ['bot_token' => '123:ABCsecretTOKENxyz']);

        $this->assertStringNotContainsString('ABCsecretTOKENxyz', $msg);
        $this->assertStringContainsString('***', $msg);
    }

    public function test_url_encoded_forms_are_redacted(): void
    {
        $msg = Redactor::redact('failed: bot123%3AABCsecretTOKENxyz and bot123%3aX', ['t' => '123:ABCsecretTOKENxyz']);

        $this->assertStringNotContainsString('ABCsecretTOKENxyz', $msg);
    }

    public function test_short_values_are_left_alone(): void
    {
        $this->assertSame('code 1234 ok', Redactor::redact('code 1234 ok', ['pin' => '1234']));
    }

    public function test_nested_secret_arrays_are_searched(): void
    {
        $msg = Redactor::redact('sent X-Auth: hunter2hunter2', ['secret_headers' => ['X-Auth' => 'hunter2hunter2'], 'n' => 5]);

        $this->assertSame('sent X-Auth: ***', $msg);
    }

    public function test_url_userinfo_and_query_are_stripped(): void
    {
        $msg = Redactor::redact('GET https://user:pw@example.com/path?token=abc#frag failed, see http://a.test/x?y=1.', []);

        $this->assertSame('GET https://example.com/path failed, see http://a.test/x', $msg);
    }

    public function test_url_secret_path_segments_are_masked_when_echoed_alone(): void
    {
        $url = 'https://hooks.slack.com/services/T0000000/B1111111/XXXXsecretXXXX';
        $msg = Redactor::redact('invalid_token at /services/T0000000/B1111111/XXXXsecretXXXX; part XXXXsecretXXXX', ['webhook_url' => $url]);

        $this->assertStringNotContainsString('XXXXsecretXXXX', $msg);
        $this->assertStringNotContainsString('T0000000', $msg);
    }

    public function test_json_escaped_url_is_masked(): void
    {
        $url = 'https://hooks.example.test/a/secretpathvalue';
        $msg = Redactor::redact('{"url":"https:\\/\\/hooks.example.test\\/a\\/secretpathvalue"}', ['u' => $url]);

        $this->assertStringNotContainsString('secretpathvalue', $msg);
    }

    public function test_encoded_forms_match_case_insensitively(): void
    {
        $msg = Redactor::redact('bot123%3aABCsecretTOKENxyz', ['t' => '123:ABCsecretTOKENxyz']);

        $this->assertStringNotContainsString('ABCsecretTOKENxyz', $msg);
    }
}
