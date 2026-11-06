<?php

namespace App\Monitoring\Engine;

use App\Enums\MonitorStatus;
use Throwable;

/**
 * The raw result of one check, before retries, inversion, thresholds and maintenance are applied.
 */
final readonly class CheckOutcome
{
    public const MAX_MESSAGE_LENGTH = 500;

    public string $message;

    /**
     * @param  array<string, scalar|list<scalar>|null>  $facts  Structured details stored with the result.
     * @param  array<string, string>  $watch  Values whose changes are reported as monitor events.
     * @param  bool  $final  Whether the failure is definitive and retries are skipped.
     * @param  ?Deadline  $deadline  The missed push or cron deadline this outcome reports, if any.
     */
    private function __construct(
        public MonitorStatus $status,
        public ?int $latencyMs,
        string $message,
        public array $facts,
        public array $watch,
        public bool $final,
        public ?Deadline $deadline = null,
    ) {
        $this->message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $facts
     * @param  array<string, string>  $watch
     */
    public static function up(?int $latencyMs = null, string $message = '', array $facts = [], array $watch = []): self
    {
        return new self(MonitorStatus::Up, $latencyMs, $message, $facts, $watch, false);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $facts
     * @param  array<string, string>  $watch
     */
    public static function down(string $message, ?int $latencyMs = null, array $facts = [], array $watch = []): self
    {
        return new self(MonitorStatus::Down, $latencyMs, $message, $facts, $watch, false);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $facts
     * @param  array<string, string>  $watch
     */
    public static function degraded(string $message, ?int $latencyMs = null, array $facts = [], array $watch = []): self
    {
        return new self(MonitorStatus::Degraded, $latencyMs, $message, $facts, $watch, false);
    }

    /**
     * A down outcome for an unexpected exception. Only the short class name is kept: exception messages can
     * carry URLs with credentials or other secrets.
     */
    public static function fromThrowable(Throwable $e, ?int $latencyMs = null): self
    {
        $class = $e::class;
        $position = strrpos($class, '\\');

        return self::down($position === false ? $class : substr($class, $position + 1), $latencyMs);
    }

    /**
     * A copy that skips the retry budget when it reports a failure.
     */
    public function final(): self
    {
        return new self($this->status, $this->latencyMs, $this->message, $this->facts, $this->watch, true, $this->deadline);
    }

    /**
     * A copy that reports a missed deadline. The recorder skips it when the monitor reported in after the
     * deadline was evaluated, or when a down for the same deadline was recorded already.
     */
    public function forDeadline(Deadline $deadline): self
    {
        return new self($this->status, $this->latencyMs, $this->message, $this->facts, $this->watch, $this->final, $deadline);
    }
}
