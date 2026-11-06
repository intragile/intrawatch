<?php

namespace App\Monitoring\Checks;

use App\Enums\MonitorType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Maps monitor types to the checker that polls them.
 */
final class CheckerRegistry
{
    /**
     * Checker classes by monitor type value; types evaluated without polling have no entry.
     *
     * @var array<string, class-string<Checker>>
     */
    private array $checkers = [];

    /** @var array<string, Checker> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function supports(MonitorType $type): bool
    {
        return isset($this->checkers[$type->value]);
    }

    public function for(MonitorType $type): Checker
    {
        $class = $this->checkers[$type->value]
            ?? throw new InvalidArgumentException("No checker is registered for the {$type->value} monitor type.");

        if (isset($this->resolved[$type->value])) {
            return $this->resolved[$type->value];
        }

        $checker = $this->container->make($class);
        if (! $checker instanceof Checker) {
            throw new InvalidArgumentException("The checker for the {$type->value} monitor type does not implement Checker.");
        }

        return $this->resolved[$type->value] = $checker;
    }
}
