<?php

namespace Tests\Unit\Support;

use App\Http\Requests\Concerns\ChecksDestinationChange;
use App\Http\Requests\NotificationChannelRequest;
use App\Support\SecretKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;
use Tests\TestCase;

/**
 * Stored secrets belong to the destination they were entered for. Pointing a monitor or a channel at a new
 * destination must re-confirm every stored secret (or remove it with null), so a secret never silently follows
 * the config to a host it was not meant for. Both requests share this check, so it is pinned down here once.
 */
class DestinationChangeTest extends TestCase
{
    private const KEYS = ['url'];

    public function test_nothing_is_missing_without_stored_secrets(): void
    {
        $this->assertSame([], SecretKeys::missingForDestinationChange(null, ['url' => 'a'], ['url' => 'b'], self::KEYS, []));
        $this->assertSame([], SecretKeys::missingForDestinationChange([], ['url' => 'a'], ['url' => 'b'], self::KEYS, []));
    }

    public function test_nothing_is_missing_when_the_new_config_is_not_an_array(): void
    {
        $this->assertSame([], SecretKeys::missingForDestinationChange(['token' => 'x'], ['url' => 'a'], 'nope', self::KEYS, []));
        $this->assertSame([], SecretKeys::missingForDestinationChange(['token' => 'x'], ['url' => 'a'], null, self::KEYS, []));
    }

    public function test_nothing_is_missing_when_the_destination_stays(): void
    {
        $this->assertSame([], SecretKeys::missingForDestinationChange(['token' => 'x'], ['url' => 'a', 'n' => 1], ['url' => 'a', 'n' => 2], self::KEYS, []));
    }

    public function test_equal_scalars_of_different_types_are_not_a_change(): void
    {
        $this->assertSame([], SecretKeys::missingForDestinationChange(['token' => 'x'], ['port' => 587], ['port' => '587'], ['port'], []));
    }

    public function test_nothing_is_missing_when_every_secret_is_sent_again_or_removed(): void
    {
        $this->assertSame([], SecretKeys::missingForDestinationChange(['token' => 'x', 'key' => 'y'], ['url' => 'a'], ['url' => 'b'], self::KEYS, ['token', 'key']));
    }

    public function test_unsent_secrets_are_listed_sorted(): void
    {
        $this->assertSame(['a', 'b'], SecretKeys::missingForDestinationChange(['b' => 'x', 'a' => 'y', 'c' => 'z'], ['url' => 'a'], ['url' => 'b'], self::KEYS, ['c']));
    }

    public function test_the_trait_adds_the_summary_and_per_key_errors(): void
    {
        $request = $this->request(['secrets' => ['other' => 'v']]);
        $v = $this->validator(['config' => ['url' => 'b']]);

        $request->check($v, ['token' => 'x'], ['url' => 'a'], self::KEYS);

        $this->assertSame(['secrets', 'secrets.token'], $v->errors()->keys());
        $this->assertSame(
            'Re-enter the stored secrets (token) when changing the destination, or remove them with null.',
            $v->errors()->first('secrets'),
        );
        $this->assertSame(SecretKeys::REENTER_SECRET, $v->errors()->first('secrets.token'));
    }

    public function test_the_trait_counts_secrets_sent_as_null_as_sent(): void
    {
        $request = $this->request(['secrets' => ['token' => null]]);
        $v = $this->validator(['config' => ['url' => 'b']]);

        $request->check($v, ['token' => 'x'], ['url' => 'a'], self::KEYS);

        $this->assertSame([], $v->errors()->keys());
    }

    public function test_the_trait_lists_several_missing_secrets_in_order(): void
    {
        $request = $this->request([]);
        $v = $this->validator(['config' => ['url' => 'b']]);

        $request->check($v, ['b' => 'x', 'a' => 'y'], ['url' => 'a'], self::KEYS);

        $this->assertSame(
            'Re-enter the stored secrets (a, b) when changing the destination, or remove them with null.',
            $v->errors()->first('secrets'),
        );
        $this->assertSame(['secrets', 'secrets.a', 'secrets.b'], $v->errors()->keys());
    }

    public function test_the_trait_stays_quiet_when_the_destination_stays(): void
    {
        $request = $this->request([]);
        $v = $this->validator(['config' => ['url' => 'a']]);

        $request->check($v, ['token' => 'x'], ['url' => 'a'], self::KEYS);

        $this->assertSame([], $v->errors()->keys());
    }

    public function test_the_channel_request_keeps_its_public_message_constant(): void
    {
        $this->assertSame(SecretKeys::REENTER_SECRET, NotificationChannelRequest::REENTER_SECRET);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function request(array $input): DestinationChangeProbe
    {
        $request = new DestinationChangeProbe;
        $request->merge($input);
        $request->remember();

        return $request;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validator(array $data): Validator
    {
        $v = ValidatorFacade::make($data, []);
        $this->assertInstanceOf(Validator::class, $v);
        $v->passes();

        return $v;
    }
}

/**
 * Exposes the trait's protected methods to the test.
 */
final class DestinationChangeProbe extends FormRequest
{
    use ChecksDestinationChange;

    public function remember(): void
    {
        $this->rememberSubmittedSecretKeys();
    }

    /**
     * @param  array<string, mixed>|null  $storedSecrets
     * @param  array<array-key, mixed>  $before
     * @param  list<string>  $destinationKeys
     */
    public function check(Validator $v, ?array $storedSecrets, array $before, array $destinationKeys): void
    {
        $this->checkDestinationChange($v, $storedSecrets, $before, $destinationKeys);
    }
}
