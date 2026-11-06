<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\Domain\WhoisParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhoisParserTest extends TestCase
{
    private static function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../../Fixtures/whois/'.$name.'.txt');
        self::assertIsString($contents);

        return $contents;
    }

    public function test_whois_fallback_parses_common_formats(): void
    {
        $com = WhoisParser::parse(self::fixture('com'));
        $this->assertSame('2027-08-13T04:00:00Z', $com->expiresAt?->toIso8601ZuluString());
        $this->assertSame('RESERVED-Internet Assigned Numbers Authority', $com->registrar);
        $this->assertFalse($com->notFound);

        $uk = WhoisParser::parse(self::fixture('uk'));
        $this->assertSame('2034-12-13T00:00:00Z', $uk->expiresAt?->toIso8601ZuluString());
        $this->assertSame('British Broadcasting Corporation [Tag = BBC]', $uk->registrar);
        $this->assertFalse($uk->notFound);

        // DENIC does not publish an expiry date on port 43.
        $de = WhoisParser::parse(self::fixture('de'));
        $this->assertNull($de->expiresAt);
        $this->assertFalse($de->notFound);

        $hu = WhoisParser::parse(self::fixture('hu'));
        $this->assertNull($hu->expiresAt);
        $this->assertFalse($hu->notFound);

        $unknown = WhoisParser::parse(self::fixture('unknown'));
        $this->assertNull($unknown->expiresAt);
        $this->assertNull($unknown->registrar);
        $this->assertFalse($unknown->notFound);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function labelledDates(): array
    {
        return [
            'registry expiry' => ["Registry Expiry Date: 2030-01-02T03:04:05Z\n", '2030-01-02T03:04:05Z'],
            'fractional seconds' => ["Registry Expiry Date: 2030-01-02T03:04:05.0Z\n", '2030-01-02T03:04:05Z'],
            'expiry date' => ["Expiry Date: 2030-01-02\n", '2030-01-02T00:00:00Z'],
            'expiration date with time' => ["Expiration Date: 2030-01-02 03:04:05\n", '2030-01-02T03:04:05Z'],
            'paid-till' => ["paid-till:     2030-01-02T21:00:00Z\n", '2030-01-02T21:00:00Z'],
            'expires on' => ["Expires On: 02-Jan-2030\n", '2030-01-02T00:00:00Z'],
            'expire, dotted' => ["expire: 2030.01.02\n", '2030-01-02T00:00:00Z'],
            'day first, dotted' => ["Expiry Date: 02.01.2030\n", '2030-01-02T00:00:00Z'],
            'with offset' => ["Expiration Date: 2030-01-02T03:04:05+02:00\n", '2030-01-02T01:04:05Z'],
        ];
    }

    #[DataProvider('labelledDates')]
    public function test_expiry_labels_and_date_formats(string $text, string $expected): void
    {
        $this->assertSame($expected, WhoisParser::parse($text)->expiresAt?->toIso8601ZuluString());
    }

    public function test_an_unparseable_or_ambiguous_date_is_ignored(): void
    {
        $this->assertNull(WhoisParser::parse("Expiry Date: 01/02/2030\n")->expiresAt);
        $this->assertNull(WhoisParser::parse("Expiry Date: soon\n")->expiresAt);
        $this->assertNull(WhoisParser::parse("Registered until expiry date.\n")->expiresAt);
    }

    public function test_not_found_answers_are_recognised(): void
    {
        foreach (['not-found-com', 'not-found-uk', 'not-found-de', 'not-found-hu'] as $name) {
            $this->assertTrue(WhoisParser::parse(self::fixture($name))->notFound, $name);
        }
    }

    public function test_rate_limit_answers_are_errors_not_unknown(): void
    {
        $this->assertTrue(WhoisParser::parse("%% Query rate limit exceeded, try again later\n")->throttled);
        $this->assertFalse(WhoisParser::parse(self::fixture('hu'))->throttled);
    }

    public function test_referral_from_iana(): void
    {
        $this->assertSame('whois.nic.hu', WhoisParser::referral(self::fixture('iana-hu')));
        $this->assertSame('whois.example.net', WhoisParser::referral("refer:        whois.example.net\n"));
        $this->assertNull(WhoisParser::referral("domain: HU\n"));
        $this->assertNull(WhoisParser::referral("whois: not a host!\n"));
    }
}
