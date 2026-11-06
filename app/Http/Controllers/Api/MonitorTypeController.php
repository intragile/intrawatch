<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Monitoring\Types\TypeRegistry;
use Illuminate\Http\JsonResponse;

/**
 * The monitor type catalogue: scheduling traits and config defaults, for building forms.
 */
class MonitorTypeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(array_map(static fn ($definition): array => [
            'type' => $definition->type()->value,
            'default_interval_seconds' => $definition->defaultIntervalSeconds(),
            'min_interval_seconds' => $definition->minIntervalSeconds(),
            'supports_inverted' => $definition->supportsInverted(),
            'scheduled' => $definition->isScheduled(),
            'defaults' => (object) $definition->defaults(),
        ], TypeRegistry::all()));
    }
}
