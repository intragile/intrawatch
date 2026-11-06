<?php

namespace App\Monitoring\Types;

use App\Enums\MonitorType;
use Illuminate\Validation\Validator;

/**
 * Describes one monitor type: its config shape, validation and scheduling traits.
 *
 * Definitions are pure. Anything that depends on the workspace (for instance the default cron timezone)
 * is filled in by the caller before validation, never read here.
 */
interface TypeDefinition
{
    public function type(): MonitorType;

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

    public function defaultIntervalSeconds(): int;

    public function minIntervalSeconds(): int;

    /**
     * Whether the outcome of a check may be flipped by the monitor's `inverted` flag.
     */
    public function supportsInverted(): bool;

    /**
     * Whether the check engine polls the target on an interval. Push, cron and group monitors are
     * evaluated from incoming pings or from their children instead.
     */
    public function isScheduled(): bool;

    /**
     * Cross-field checks that single-field rules cannot express. Runs as an `after` hook, so the data is
     * already typed by the rules; the validator's data holds `config`, `secrets` and a boolean `_creating`.
     * Stored secrets of an existing monitor are expected to be merged into `secrets` by the caller.
     *
     * @param  array<string, mixed>  $config  The config with defaults merged in.
     */
    public function afterValidation(array $config, Validator $v): void;
}
