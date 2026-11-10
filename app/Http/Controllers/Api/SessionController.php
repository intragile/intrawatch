<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\SignOutOtherSessions;
use App\Support\SignOutSession;
use App\Support\UserAgent;
use App\Support\UserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user's browser sessions. Only the browser session may use it, never an API token.
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly SignOutOtherSessions $signOutOthers,
        private readonly SignOutSession $signOutOne,
    ) {}

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
     * Sign out one session.
     *
     * Ends one of the user's other sessions, by the `id` the list returned; a raw session id is never
     * accepted. Unlike signing out all other sessions it needs no password: it can only end the user's
     * own sessions and is easy to undo by signing in again, so a prompt would be friction without much
     * gain. It has its own limiter (`session-sign-out`, 10 a minute per user) instead of sharing the
     * password-guessing budget. Because the remember token is per user, every other browser's remember
     * cookie stops working too; the other sessions themselves keep working, and this browser gets a
     * fresh remember cookie if it had one.
     *
     * The current session is refused with a 422 on `id`: sign out instead. An id that matches none of
     * the user's unexpired stored sessions is a 404, whoever else it may belong to. Without the database
     * session driver no other session is stored, so every other id is a 404. API tokens get a 403.
     *
     * @param  string  $id  The session's `id` from the list: 32 lowercase hex characters.
     *
     * @throws ValidationException when the id is the current session
     */
    public function destroy(Request $request, string $id): Response
    {
        $user = PasswordController::sessionUser($request, 'API tokens cannot sign out sessions.');

        if (hash_equals(UserSessions::publicId($request->session()->getId()), $id)) {
            throw ValidationException::withMessages([
                'id' => ['This is the current session; sign out instead.'],
            ]);
        }

        $row = UserSessions::findByPublicId($user, $id);
        abort_if($row === null, 404, 'No such session.');

        DB::transaction(function () use ($user, $request, $row): void {
            ($this->signOutOne)($user, $request, $row->id);

            // A label of the ended device, never its session id.
            Audit::recordGlobal('auth.session_signed_out', $user, [
                'session' => [array_filter([...UserAgent::parse($row->user_agent), 'ip_address' => $row->ip_address]), null],
            ]);
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
