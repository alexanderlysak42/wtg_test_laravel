<?php

namespace Tests\Feature;

use App\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_succeeds_and_decrements_units(): void
    {
        $offer = Offer::factory()->create(['available_units' => 2]);

        $response = $this->postJson("/api/offers/{$offer->id}/reservations", [
            'client_reference' => 'web-order-9f782b1c',
            'customer_name' => 'John Smith',
            'customer_email' => 'john@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.client_reference', 'web-order-9f782b1c');

        $this->assertSame(1, $offer->fresh()->available_units);
        $this->assertDatabaseHas('reservations', ['offer_id' => $offer->id]);
    }

    public function test_reservation_fails_when_no_units_left(): void
    {
        $offer = Offer::factory()->create(['available_units' => 0]);

        $response = $this->postJson("/api/offers/{$offer->id}/reservations", [
            'client_reference' => 'web-order-full',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseMissing('reservations', ['offer_id' => $offer->id]);
    }
}
