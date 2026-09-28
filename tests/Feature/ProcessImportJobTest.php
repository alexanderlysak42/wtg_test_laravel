<?php

namespace Tests\Feature;

use App\Enums\ImportStatusEnum;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

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

    public function test_older_import_does_not_overwrite_newer_offer_data(): void
    {
        $supplier = Supplier::factory()->create();

        $newer = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'sent_at' => '2026-09-02 10:00:00',
            'payload' => [$this->offerPayload('offer-1', price: 8000)],
            'total_offers' => 1,
        ]);

        $older = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'sent_at' => '2026-09-01 10:00:00',
            'payload' => [
                $this->offerPayload('offer-1', price: 5000),
                $this->offerPayload('offer-2', price: 6000),
            ],
            'total_offers' => 2,
        ]);

        (new ProcessImportJob($newer))->handle();
        (new ProcessImportJob($older))->handle();

        $this->assertSame(ImportStatusEnum::COMPLETED, $older->fresh()->status);
        $this->assertDatabaseHas('offers', ['external_id' => 'offer-1', 'price' => 8000]);
        $this->assertDatabaseHas('offers', ['external_id' => 'offer-2', 'price' => 6000]);
    }

    public function test_failed_import_is_rolled_back_and_marked_failed(): void
    {
        $supplier = Supplier::factory()->create();

        $broken = $this->offerPayload('offer-2', price: 6000);
        unset($broken['price']);

        $import = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'payload' => [$this->offerPayload('offer-1', price: 5000), $broken],
            'total_offers' => 2,
        ]);

        $job = new ProcessImportJob($import);

        try {
            $job->handle();
            $this->fail('Job was expected to throw.');
        } catch (Throwable $exception) {
            $job->failed($exception);
        }

        $import->refresh();

        $this->assertSame(ImportStatusEnum::FAILED, $import->status);
        $this->assertNotNull($import->error);
        $this->assertNotNull($import->completed_at);
        $this->assertSame(0, Offer::query()->count());
        $this->assertSame(0, Property::query()->count());
    }

    public function test_reservations_made_after_sent_at_are_subtracted_from_imported_units(): void
    {
        $supplier = Supplier::factory()->create();

        $first = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'sent_at' => now()->subHours(2),
            'payload' => [$this->offerPayload('offer-1', price: 5000)],
            'total_offers' => 1,
        ]);
        (new ProcessImportJob($first))->handle();

        $offer = Offer::query()->where('external_id', 'offer-1')->firstOrFail();

        Reservation::factory()->for($offer)->create(['created_at' => now()->subHours(1)]);
        Reservation::factory()->for($offer)->create(['created_at' => now()->subMinutes(10)]);

        $second = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'sent_at' => now()->subMinutes(30),
            'payload' => [$this->offerPayload('offer-1', price: 5000)],
            'total_offers' => 1,
        ]);
        (new ProcessImportJob($second))->handle();

        $this->assertSame(2, $offer->fresh()->available_units);
    }

    public function test_imported_units_never_go_below_zero(): void
    {
        $supplier = Supplier::factory()->create();

        $offer = Offer::factory()->create([
            'supplier_id' => $supplier->id,
            'external_id' => 'offer-1',
            'source_sent_at' => now()->subHours(2),
        ]);

        Reservation::factory()->for($offer)->count(5)->create(['created_at' => now()->subMinutes(5)]);

        $import = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'sent_at' => now()->subHour(),
            'payload' => [$this->offerPayload('offer-1', price: 5000)],
            'total_offers' => 1,
        ]);
        (new ProcessImportJob($import))->handle();

        $this->assertSame(0, $offer->fresh()->available_units);
    }

    public function test_completed_import_is_not_processed_again(): void
    {
        $supplier = Supplier::factory()->create();

        $import = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => ImportStatusEnum::COMPLETED,
            'payload' => [$this->offerPayload('offer-1', price: 5000)],
            'total_offers' => 1,
        ]);

        (new ProcessImportJob($import))->handle();

        $this->assertSame(0, Offer::query()->count());
    }

    public function test_transient_failures_are_retried(): void
    {
        $job = new ProcessImportJob(Import::factory()->create());

        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff);
    }

    private function offerPayload(string $externalId, int $price): array
    {
        return [
            'external_id' => $externalId,
            'property' => ['code' => 'BCN-0001', 'name' => 'Test Property', 'city' => 'Barcelona'],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => $price,
            'currency' => 'EUR',
            'available_units' => 3,
            'expires_at' => '2026-12-01T00:00:00Z',
        ];
    }
}
