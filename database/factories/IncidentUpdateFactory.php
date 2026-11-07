<?php

namespace Database\Factories;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentUpdate>
 */
class IncidentUpdateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'status' => IncidentStatus::Investigating,
            'message' => fake()->sentence(),
        ];
    }
}
