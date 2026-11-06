<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * The same answer whether or not a reset link was sent, so the endpoint does not reveal which
 * addresses have an account (or are in the broker's cooldown).
 */
final class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'If the address belongs to an account, a password reset link has been sent to it.';

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(['message' => self::MESSAGE]);
    }
}
