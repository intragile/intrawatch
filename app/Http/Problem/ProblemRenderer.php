<?php

namespace App\Http\Problem;

use App\Exceptions\ImportConflict;
use App\Exceptions\SessionUnavailable;
use App\Exceptions\SetupAlreadyCompleted;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders any exception as an RFC 9457 problem document.
 */
final class ProblemRenderer
{
    private const CONTENT_TYPE = 'application/problem+json';

    private const TITLES = [
        419 => 'Page Expired',
        423 => 'Locked',
    ];

    public static function render(Throwable $e): JsonResponse
    {
        $headers = [];
        $detail = null;
        $errors = null;
        $type = 'about:blank';

        if ($e instanceof ValidationException) {
            $status = $e->status;
            $detail = $e->getMessage();
            $errors = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
        } elseif ($e instanceof AuthorizationException) {
            $status = $e->status() ?? 403;
            $detail = self::nonEmpty($e->getMessage());
        } elseif ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            $status = 404;
        } elseif ($e instanceof MethodNotAllowedHttpException) {
            $status = 405;
            $headers = $e->getHeaders();
        } elseif ($e instanceof TokenMismatchException) {
            $status = 419;
        } elseif ($e instanceof SetupAlreadyCompleted) {
            $status = 409;
            $type = 'urn:problem:setup-already-completed';
            $detail = $e->getMessage();
        } elseif ($e instanceof ImportConflict) {
            $status = 409;
            $type = $e->type;
            $detail = $e->getMessage();
        } elseif ($e instanceof SessionUnavailable) {
            $status = 400;
            $type = 'urn:problem:session-unavailable';
            $detail = $e->getMessage();
        } elseif ($e instanceof UniqueConstraintViolationException) {
            $status = 409;
            $detail = 'The resource conflicts with an existing one.';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $detail = $status >= 500 ? null : self::nonEmpty($e->getMessage());
        } else {
            $status = 500;
            $detail = config('app.debug') === true ? self::nonEmpty($e->getMessage()) : null;
        }

        $body = ['type' => $type, 'title' => self::title($status), 'status' => $status];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }
        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        $headers['Content-Type'] = self::CONTENT_TYPE;

        return new JsonResponse($body, $status, $headers);
    }

    private static function title(int $status): string
    {
        return self::TITLES[$status] ?? Response::$statusTexts[$status] ?? 'Error';
    }

    private static function nonEmpty(string $message): ?string
    {
        return $message === '' ? null : $message;
    }
}
