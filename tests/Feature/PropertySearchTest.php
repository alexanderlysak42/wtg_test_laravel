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

    public function test_filters_by_guests_and_city(): void
    {
        $barcelona = Property::factory()->create(['city' => 'Barcelona']);
        $madrid = Property::factory()->create(['city' => 'Madrid']);
        $small = Property::factory()->create(['city' => 'Barcelona']);

        $fits = $this->searchableOffer($barcelona, price: 30000, maxGuests: 4);
        $this->searchableOffer($madrid, price: 10000, maxGuests: 4);
        $this->searchableOffer($small, price: 5000, maxGuests: 2);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $barcelona->code)
            ->assertJsonPath('data.0.best_offer.id', $fits->id);

        $this->getJson('/api/properties?check_in=2026-10-10&check_out=2026-10-15&guests=3')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.city', 'Madrid')
            ->assertJsonPath('data.1.city', 'Barcelona');
    }

    public function test_excludes_offers_with_other_dates(): void
    {
        $property = Property::factory()->create(['city' => 'Barcelona']);

        $this->searchableOffer($property, price: 30000, maxGuests: 4, checkOut: '2026-10-16');

        $this->getJson('/api/properties?check_in=2026-10-10&check_out=2026-10-15')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_results_are_paginated_and_sorted_by_price(): void
    {
        foreach ([40000, 10000, 30000] as $price) {
            $this->searchableOffer(Property::factory()->create(['city' => 'Barcelona']), $price, maxGuests: 2);
        }

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.best_offer.price', 10000)
            ->assertJsonPath('data.1.best_offer.price', 30000)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('links.prev', null)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total']]);

        $this->getJson('/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.best_offer.price', 40000)
            ->assertJsonPath('links.next', null);
    }

    public function test_check_in_and_check_out_are_required(): void
    {
        $this->getJson('/api/properties?city=Barcelona')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['check_in', 'check_out']);
    }

    private function searchableOffer(
        Property $property,
        int $price,
        int $maxGuests,
        string $checkOut = '2026-10-15',
    ): Offer {
        return Offer::factory()->create([
            'property_id' => $property->id,
            'check_in' => '2026-10-10',
            'check_out' => $checkOut,
            'max_guests' => $maxGuests,
            'price' => $price,
            'available_units' => 1,
            'expires_at' => now()->addDays(5),
        ]);
    }
}
