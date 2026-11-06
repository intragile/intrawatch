<?php

namespace App\Console\Commands;

use App\Monitoring\Domain\RdapBootstrap;
use App\Monitoring\Domain\RegistrableDomains;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

#[Signature('intrawatch:rdap:refresh')]
#[Description('Download the IANA RDAP bootstrap registry and the Public Suffix List into storage')]
class RdapRefreshCommand extends Command
{
    public const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    public const PUBLIC_SUFFIX_LIST_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    public function handle(): int
    {
        $ok = $this->refresh('RDAP bootstrap', self::BOOTSTRAP_URL, RdapBootstrap::STORAGE_PATH, static function (string $body): void {
            RdapBootstrap::parse($body);
        });

        $ok = $this->refresh('Public Suffix List', self::PUBLIC_SUFFIX_LIST_URL, RegistrableDomains::STORAGE_PATH, static function (string $body): void {
            RegistrableDomains::parse($body);
        }) && $ok;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Downloads a list and replaces the stored copy atomically, only when the download is valid. Workers pick the
     * new copy up within an hour.
     *
     * @param  Closure(string): void  $validate  Throws when the content is unusable.
     */
    private function refresh(string $name, string $url, string $path, Closure $validate): bool
    {
        try {
            $response = Http::timeout(60)->connectTimeout(15)->accept('*/*')->get($url);
            if (! $response->successful()) {
                throw new RuntimeException("HTTP {$response->status()}");
            }

            $body = $response->body();
            $validate($body);

            $target = storage_path($path);
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
                throw new RuntimeException('Cannot create '.dirname($target));
            }

            $temporary = $target.'.tmp';
            if (file_put_contents($temporary, $body) === false || ! rename($temporary, $target)) {
                @unlink($temporary);

                throw new RuntimeException('Cannot write '.$target);
            }
        } catch (Throwable $e) {
            $this->error("{$name} not updated: {$e->getMessage()}");

            return false;
        }

        $this->info("{$name} updated.");

        return true;
    }
}
