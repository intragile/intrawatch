<?php

namespace App\Monitoring\Checks\Dns;

use Amp\Dns\DnsRecord;

/**
 * Renders DNS records in one canonical text form so configured values and answers compare equal.
 *
 * Hostnames are lower-cased without the trailing dot. The resolver exposes multi-field records as comma
 * separated fields, which are rendered space separated.
 */
final class RecordFormatter
{
    public static function format(DnsRecord $record): string
    {
        $value = $record->getValue();

        return match ($record->getType()) {
            DnsRecord::MX => self::fields($value, 2, [1]),
            DnsRecord::SRV => self::fields($value, 4, [3]),
            DnsRecord::SOA => self::fields($value, 7, [0, 1]),
            DnsRecord::CAA => self::fields($value, 3, []),
            DnsRecord::CNAME, DnsRecord::NS, DnsRecord::PTR => self::hostname($value),
            DnsRecord::A, DnsRecord::AAAA => strtolower(trim($value)),
            default => $value,
        };
    }

    /**
     * Normalizes a value written by a user, which may use either field separator.
     */
    public static function normalize(string $type, string $value): string
    {
        $type = (int) (self::TYPES[$type] ?? 0);

        return $type === 0 ? trim($value) : self::format(new DnsRecord(trim($value), $type));
    }

    /** @var array<string, int> */
    public const TYPES = [
        'A' => DnsRecord::A,
        'AAAA' => DnsRecord::AAAA,
        'CNAME' => DnsRecord::CNAME,
        'MX' => DnsRecord::MX,
        'NS' => DnsRecord::NS,
        'TXT' => DnsRecord::TXT,
        'SOA' => DnsRecord::SOA,
        'SRV' => DnsRecord::SRV,
        'CAA' => DnsRecord::CAA,
        'PTR' => DnsRecord::PTR,
    ];

    /**
     * @param  list<int>  $hostnameFields  Indexes of the fields holding hostnames.
     */
    private static function fields(string $value, int $count, array $hostnameFields): string
    {
        $parts = preg_split('/[,\s]+/', trim($value), $count) ?: [];

        foreach ($hostnameFields as $index) {
            if (isset($parts[$index])) {
                $parts[$index] = self::hostname($parts[$index]);
            }
        }

        return implode(' ', $parts);
    }

    private static function hostname(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }
}
