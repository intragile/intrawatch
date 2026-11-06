<?php

namespace App\Http\Controllers\Api;

use App\Actions\CompleteSetup;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SetupController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['required' => ! User::query()->exists()]);
    }

    /**
     * Complete the first-run setup. Must be called as a stateful (cookie) request: send an `Origin` that
     * matches `APP_URL` and the `X-XSRF-TOKEN` obtained from `GET /sanctum/csrf-cookie`.
     */
    public function store(Request $request, CompleteSetup $completeSetup): JsonResponse
    {
        $user = $completeSetup->handle([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => $request->input('password'),
            'workspace_name' => $request->input('workspace_name'),
        ]);

        // The route requires a session, so the new owner is logged in right away.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => MeController::payload($user)], 201);
    }
}
