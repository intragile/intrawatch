<?php

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceInvitation>
 */
class WorkspaceInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'role' => WorkspaceRole::Viewer,
            'token_hash' => WorkspaceInvitation::hashToken(Str::random(64)),
            'invited_by' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * Uses the given plain token, so a test can accept the invitation.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => WorkspaceInvitation::hashToken($token)]);
    }
}
