<?php

namespace App\Monitoring\Net;

use Amp\Socket\ConnectException;

/**
 * Thrown when a connection through a proxy cannot be established: the proxy is misconfigured, unreachable,
 * rejected the credentials or refused to open the tunnel.
 */
final class ProxyFailure extends ConnectException {}
