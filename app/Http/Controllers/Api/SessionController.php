<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\SignOutOtherSessions;
use App\Support\UserAgent;
use App\Support\UserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's browser sessions. Only the browser session may use it, never an API token.
 */
class SessionController extends Controller
{
    public function __construct(private readonly SignOutOtherSessions $signOutOthers) {}

    /**
     * List my sessions.
     *
     * The sessions of the signed-in user that have not expired, the current one first. `id` is an opaque
     * handle, never the session cookie value. Without the database session driver only the current
     * session is listed. API tokens get a 403.
     *
     * @response array{data: list<array{id: string, ip_address: string|null, user_agent: string|null, browser: string|null, platform: string|null, last_active_at: string, current: bool}>}
     */
    public function index(Request $request): JsonResponse
    {
        $user = PasswordController::sessionUser($request, 'API tokens cannot list sessions.');
        $currentId = $request->session()->getId();

        $current = self::entry(UserSessions::publicId($currentId), $request->ip(), $request->userAgent(), now()->getTimestamp(), true);
        $others = [];
        foreach (UserSessions::active($user) as $row) {
            if ($row->id !== $currentId) {
                $others[] = self::entry(UserSessions::publicId($row->id), $row->ip_address, $row->user_agent, (int) $row->last_activity, false);
            }
        }

        return response()->json(['data' => [$current, ...$others]]);
    }

    /**
     * Sign out all other sessions.
     *
     * Needs the current password. Ends every session and remember cookie of the user except the one
     * making the request. API tokens are not revoked, and cannot call this endpoint (403).
     */
    public function destroyOthers(Request $request): Response
    {
        $user = PasswordController::sessionUser($request, 'API tokens cannot sign out sessions.');

        $request->validate([
            'password' => ['required', 'string', 'current_password:web'],
        ]);

        DB::transaction(function () use ($user, $request): void {
            $ended = ($this->signOutOthers)($user, $request, $request->string('password')->toString());

            Audit::recordGlobal('auth.other_sessions_signed_out', $user, ['other_sessions' => [$ended, 0]]);
        });

        return response()->noContent();
    }

    /**
     * @return array{id: string, ip_address: string|null, user_agent: string|null, browser: string|null, platform: string|null, last_active_at: string, current: bool}
     */
    private static function entry(string $id, ?string $ip, ?string $userAgent, int $lastActivity, bool $current): array
    {
        return [
            'id' => $id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            ...UserAgent::parse($userAgent),
            'last_active_at' => Carbon::createFromTimestamp($lastActivity)->toIso8601String(),
            'current' => $current,
        ];
    }
}
