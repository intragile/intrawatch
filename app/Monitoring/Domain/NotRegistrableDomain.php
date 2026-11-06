<?php

namespace App\Monitoring\Domain;

use InvalidArgumentException;

/**
 * The host is an IP address, a public suffix, or not below any suffix the Public Suffix List knows.
 */
final class NotRegistrableDomain extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Not a registrable domain');
    }
}
