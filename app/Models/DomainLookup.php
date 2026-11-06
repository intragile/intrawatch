<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The cached registration lookup of a registrable domain. The table is shared by all workspaces because it only
 * holds public data.
 *
 * Rows keyed `whois:{tld}` are not domains: they cache the WHOIS server of a TLD in `registrar`, with status `ok`
 * when IANA referred to it and `unknown` when it is the conventional `whois.nic.{tld}` guess.
 *
 * @property string $domain
 * @property string $source `rdap` or `whois`
 * @property string $status `ok`, `unknown`, `not_found` or `error`
 * @property Carbon|null $expires_at
 * @property string|null $registrar
 * @property string|null $error
 * @property Carbon $checked_at
 */
class DomainLookup extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'domain';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
