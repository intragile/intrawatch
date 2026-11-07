<?php

namespace Database\Factories;

use App\Enums\MaintenanceStrategy;
use App\Models\Maintenance;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Maintenance>
 */
class MaintenanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => fake()->unique()->sentence(3),
            'strategy' => MaintenanceStrategy::Once,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'active' => true,
        ];
    }
}
