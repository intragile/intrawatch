<?php

namespace App\Alerting\Transport;

/**
 * Resolves through the system resolver. Blocking, so for queue workers only.
 */
final class SystemAddressResolver implements AddressResolver
{
    public function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record[$field]) && is_string($record[$field])) {
                    $addresses[] = $record[$field];
                }
            }
        }

        if ($addresses === []) {
            $v4 = @gethostbynamel($host);
            $addresses = is_array($v4) ? $v4 : [];
        }

        return array_values(array_unique($addresses));
    }
}
