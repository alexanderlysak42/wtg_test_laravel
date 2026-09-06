<?php

namespace Tests\Feature;

use App\Enums\ImportStatusEnum;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_creates_property_and_offer(): void
    {
        $supplier = Supplier::factory()->create();

        $import = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'payload' => [[
                'external_id' => 'offer-1',
                'property' => ['code' => 'BCN-0001', 'name' => 'Test Property', 'city' => 'Barcelona'],
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 4,
                'price' => 10000,
                'currency' => 'EUR',
                'available_units' => 3,
                'expires_at' => '2026-12-01T00:00:00Z',
            ]],
            'total_offers' => 1,
        ]);

        (new ProcessImportJob($import))->handle();

        $import->refresh();

        $this->assertSame(ImportStatusEnum::COMPLETED, $import->status);
        $this->assertSame(1, $import->processed_offers);
        $this->assertDatabaseHas('properties', ['code' => 'BCN-0001']);
        $this->assertDatabaseHas('offers', [
            'supplier_id' => $supplier->id,
            'external_id' => 'offer-1',
            'available_units' => 3,
        ]);
    }

    public function test_job_updates_existing_offer_with_same_external_id(): void
    {
        $supplier = Supplier::factory()->create();

        $offer = Offer::factory()->create([
            'supplier_id' => $supplier->id,
            'external_id' => 'offer-1',
            'price' => 5000,
        ]);

        $import = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'payload' => [[
                'external_id' => 'offer-1',
                'property' => [
                    'code' => $offer->property->code,
                    'name' => $offer->property->name,
                    'city' => $offer->property->city,
                ],
                'check_in' => $offer->check_in->format('Y-m-d'),
                'check_out' => $offer->check_out->format('Y-m-d'),
                'max_guests' => $offer->max_guests,
                'price' => 9999,
                'currency' => 'EUR',
                'available_units' => 1,
                'expires_at' => now()->addDays(10)->toIso8601String(),
            ]],
            'total_offers' => 1,
        ]);

        (new ProcessImportJob($import))->handle();

        $this->assertSame(1, Offer::query()->count());
        $this->assertDatabaseHas('offers', [
            'id' => $offer->id,
            'price' => 9999,
            'available_units' => 1,
        ]);
    }
}
