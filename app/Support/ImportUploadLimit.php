<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * The import upload limit that actually applies: IMPORT_MAX_UPLOAD_MB, clamped to PHP's upload_max_filesize and
 * post_max_size (PHP drops a larger file before the application sees it). The upload request enforces it and the
 * SPA shell hands the same value to the frontend.
 */
class ImportUploadLimit
{
    private const MEGABYTE = 1024 * 1024;

    /**
     * The smaller of PHP's upload_max_filesize and post_max_size in bytes (PHP_INT_MAX when both are unlimited).
     */
    public function phpMaxBytes(): int
    {
        $max = SymfonyUploadedFile::getMaxFilesize();

        return $max >= PHP_INT_MAX ? PHP_INT_MAX : (int) $max;
    }

    public function bytes(): int
    {
        return self::clamp($this->phpMaxBytes());
    }

    /**
     * The limit in megabytes: whole megabytes as an integer, anything else (a PHP limit under 1 MB) as a fraction.
     */
    public function megabytes(): int|float
    {
        $bytes = $this->bytes();

        return $bytes % self::MEGABYTE === 0 ? intdiv($bytes, self::MEGABYTE) : round($bytes / self::MEGABYTE, 2);
    }

    /**
     * The configured limit, or the given PHP limit when that is lower.
     */
    public static function clamp(int $phpMaxBytes): int
    {
        return max(1, min(self::configuredBytes(), $phpMaxBytes));
    }

    public static function configuredMegabytes(): int
    {
        return max(1, config()->integer('intrawatch.import.max_upload_mb', 256));
    }

    private static function configuredBytes(): int
    {
        return self::configuredMegabytes() * self::MEGABYTE;
    }
}
