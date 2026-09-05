<?php

namespace Database\Factories;

use App\Enums\ImportStatusEnum;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => $this->faker->unique()->uuid(),
            'sent_at' => now(),
            'status' => ImportStatusEnum::PENDING,
            'total_offers' => 0,
            'processed_offers' => 0,
            'payload' => [],
        ];
    }
}
