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
    /**
     * @response list<array{type: \App\Enums\ChannelType, supports_digest: bool, defaults: array<string, mixed>, config_keys: list<string>, secret_keys: list<string>, destination_keys: list<string>}>
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(array_map(static fn ($definition): array => [
            'type' => $definition->type()->value,
            'supports_digest' => $definition->supportsDigest(),
            'defaults' => (object) $definition->defaults(),
            'config_keys' => NotificationChannelRequest::keysOf($definition->configRules()),
            'secret_keys' => NotificationChannelRequest::keysOf($definition->secretRules()),
            // The config paths that decide where the secrets are sent; served so the SPA keeps no copy of its own.
            'destination_keys' => $definition->destinationKeys(),
        ], ChannelRegistry::all()));
    }
}
