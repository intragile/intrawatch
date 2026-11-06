<?php

namespace App\Monitoring\Net;

use Amp\Socket\ConnectException;

/**
 * Thrown when every address a host resolves to is inside a blocked network.
 */
final class BlockedTarget extends ConnectException {}
