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

    public function store(Request $request, CompleteSetup $completeSetup): JsonResponse
    {
        $user = $completeSetup->handle([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => $request->input('password'),
            'workspace_name' => $request->input('workspace_name'),
        ]);

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json(['data' => MeController::payload($user)], 201);
    }
}
