<?php

namespace App\Http\Controllers\Api;

use App\Alerting\Channels\ChannelRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationChannelRequest;
use Illuminate\Http\JsonResponse;

/**
 * The notification channel catalogue: field names and defaults, for building forms.
 */
class NotificationChannelTypeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(array_map(static fn ($definition): array => [
            'type' => $definition->type()->value,
            'supports_digest' => $definition->supportsDigest(),
            'defaults' => (object) $definition->defaults(),
            'config_keys' => NotificationChannelRequest::keysOf($definition->configRules()),
            'secret_keys' => NotificationChannelRequest::keysOf($definition->secretRules()),
        ], ChannelRegistry::all()));
    }
}
