<?php

namespace App\Alerting\Channels;

use App\Enums\ChannelType;
use Illuminate\Validation\Validator;

/**
 * Describes one notification channel type: its config and secret shape and its validation.
 *
 * Definitions are pure; nothing here reads the workspace or the database.
 */
interface ChannelDefinition
{
    public function type(): ChannelType;

    /**
     * Validation rules for the `config` object; keys are relative to `config.`.
     *
     * @return array<string, mixed>
     */
    public function configRules(): array;

    /**
     * Validation rules for the write-only `secrets` object; keys are relative to `secrets.`.
     *
     * @return array<string, mixed>
     */
    public function secretRules(): array;

    /**
     * Values merged under the submitted config.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Secret keys that must exist after a create or update, counting stored secrets of an existing channel.
     *
     * @return list<string>
     */
    public function requiredSecrets(): array;

    /**
     * Whether the channel can carry several alerts in one message.
     */
    public function supportsDigest(): bool;

    /**
     * Cross-field checks that single-field rules cannot express. Runs as an `after` hook.
     *
     * @param  array<string, mixed>  $config  The config with defaults merged in.
     * @param  array<string, mixed>  $secrets  The secrets, with stored ones of an existing channel merged in.
     */
    public function afterValidation(array $config, array $secrets, Validator $v): void;
}
