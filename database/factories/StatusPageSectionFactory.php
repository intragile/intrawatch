<?php

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\StatusPageSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPageSection>
 */
class StatusPageSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status_page_id' => StatusPage::factory(),
            'name' => fake()->words(2, true),
            'position' => 0,
        ];
    }
}
