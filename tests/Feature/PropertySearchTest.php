<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_cheapest_offer_per_property(): void
    {
        $supplierA = Supplier::factory()->create(['code' => 'supplier-a']);
        $supplierB = Supplier::factory()->create(['code' => 'supplier-b']);
        $property = Property::factory()->create(['city' => 'Barcelona']);

        $cheap = Offer::factory()->create([
            'supplier_id' => $supplierB->id,
            'property_id' => $property->id,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 50000,
            'available_units' => 1,
            'expires_at' => now()->addDays(5),
        ]);

        Offer::factory()->create([
            'supplier_id' => $supplierA->id,
            'property_id' => $property->id,
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'available_units' => 2,
            'expires_at' => now()->addDays(5),
        ]);

        $response = $this->getJson(
            '/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2'
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.id', $cheap->id)
            ->assertJsonPath('data.0.best_offer.price', 50000);
    }

    public function test_excludes_expired_and_sold_out_offers(): void
    {
        $property = Property::factory()->create(['city' => 'Madrid']);

        Offer::factory()->create([
            'property_id' => $property->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
            'available_units' => 0,
            'expires_at' => now()->addDays(5),
        ]);

        Offer::factory()->create([
            'property_id' => $property->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
            'available_units' => 3,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->getJson(
            '/api/properties?city=Madrid&check_in=2026-11-01&check_out=2026-11-05&guests=1'
        );

        $response->assertOk()->assertJsonCount(0, 'data');
    }
}
