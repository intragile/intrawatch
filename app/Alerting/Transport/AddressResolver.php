<?php

namespace App\Alerting\Transport;

interface AddressResolver
{
    /**
     * Every IPv4 and IPv6 address a host name resolves to; an IP literal resolves to itself.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
