<?php

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\StatusPageItem;
use App\Models\StatusPageSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPageItem>
 */
class StatusPageItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status_page_section_id' => StatusPageSection::factory(),
            'monitor_id' => Monitor::factory(),
            'position' => 0,
        ];
    }
}
