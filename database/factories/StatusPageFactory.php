<?php

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StatusPage>
 */
class StatusPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'slug' => 'page-'.Str::lower(Str::random(10)),
            'title' => fake()->company(),
            'published' => false,
        ];
    }
}
