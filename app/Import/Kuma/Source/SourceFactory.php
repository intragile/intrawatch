<?php

declare(strict_types=1);

namespace App\Import\Kuma\Source;

final class SourceFactory
{
    /**
     * Opens a file path (SQLite database or 1.23 JSON backup) or a `mysql://user:pass@host:port/db` DSN.
     *
     * @throws UnreadableSource
     */
    public static function open(string $source): KumaSource
    {
        if (preg_match('#^mysql://#i', $source) === 1) {
            return PdoKumaSource::mysql($source);
        }

        return match (self::detect($source)) {
            SourceKind::Sqlite => PdoKumaSource::sqlite($source),
            SourceKind::Json => JsonBackupSource::fromFile($source),
            SourceKind::Mysql => throw new UnreadableSource('A MySQL source must be given as a mysql:// DSN.'),
        };
    }

    /**
     * Tells a SQLite database from a JSON backup by its leading bytes.
     *
     * @throws UnreadableSource
     */
    public static function detect(string $path): SourceKind
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new UnreadableSource('The source file does not exist or cannot be read.');
        }
        $head = (string) fread($handle, 64);
        fclose($handle);

        if (str_starts_with($head, "SQLite format 3\0")) {
            return SourceKind::Sqlite;
        }
        if (str_starts_with(ltrim($head, "\xEF\xBB\xBF \t\r\n"), '{')) {
            return SourceKind::Json;
        }

        throw new UnreadableSource('The source is neither an Uptime Kuma SQLite database nor a JSON backup.');
    }
}
