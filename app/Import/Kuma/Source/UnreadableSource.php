<?php

declare(strict_types=1);

namespace App\Import\Kuma\Source;

use RuntimeException;

/**
 * The given source cannot be read as an Uptime Kuma export. The message is safe to show to a user.
 */
final class UnreadableSource extends RuntimeException {}
