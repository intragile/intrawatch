<?php

namespace Database\Factories;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => fake()->unique()->sentence(3),
            'impact' => IncidentImpact::Minor,
            'status' => IncidentStatus::Investigating,
            'started_at' => now()->subHour(),
        ];
    }
}
