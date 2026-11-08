<?php

namespace App\Http\Requests\Concerns;

use App\Support\SecretKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Refuses an update that points the stored secrets at a new destination without sending them again. Shared by
 * the monitor and the notification channel requests so both answer with the same errors.
 *
 * The submitted secret names must be remembered before the stored secrets are merged into the input, or every
 * stored key would look sent and the check would never fire.
 *
 * @phpstan-require-extends FormRequest
 */
trait ChecksDestinationChange
{
    /**
     * The secret names in the request body, including those sent as null.
     *
     * @var list<string>
     */
    private array $submittedSecretKeys = [];

    protected function rememberSubmittedSecretKeys(): void
    {
        $raw = $this->input('secrets');
        $this->submittedSecretKeys = is_array($raw) ? array_map(strval(...), array_keys($raw)) : [];
    }

    /**
     * @param  array<string, mixed>|null  $storedSecrets
     * @param  array<array-key, mixed>  $before  the stored config with the type defaults applied
     * @param  list<string>  $destinationKeys
     */
    protected function checkDestinationChange(Validator $v, ?array $storedSecrets, array $before, array $destinationKeys): void
    {
        $missing = SecretKeys::missingForDestinationChange(
            $storedSecrets,
            $before,
            $v->getData()['config'] ?? null,
            $destinationKeys,
            $this->submittedSecretKeys,
        );
        if ($missing === []) {
            return;
        }
        $v->errors()->add('secrets', 'Re-enter the stored secrets ('.implode(', ', $missing).') when changing the destination, or remove them with null.');
        foreach ($missing as $key) {
            $v->errors()->add('secrets.'.$key, SecretKeys::REENTER_SECRET);
        }
    }
}
