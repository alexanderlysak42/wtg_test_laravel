<?php

namespace Tests\Feature;

use App\Enums\ImportStatusEnum;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_accepted_and_queued(): void
    {
        Queue::fake();

        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

        $response = $this->postJson('/api/imports', $this->samplePayload($supplier, 'import-001'));

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('imports', [
            'supplier_id' => $supplier->id,
            'external_import_id' => 'import-001',
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_duplicate_import_is_not_created_twice(): void
    {
        Queue::fake();

        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $payload = $this->samplePayload($supplier, 'import-duplicate');

        $this->postJson('/api/imports', $payload)->assertStatus(202);
        $this->postJson('/api/imports', $payload)->assertStatus(202);

        $this->assertSame(1, Import::query()->count());
        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_unknown_supplier_is_rejected(): void
    {
        Queue::fake();

        $payload = $this->samplePayload(new Supplier(['code' => 'unknown-supplier']), 'import-002');

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier')
            ->assertJsonMissingValidationErrors('offers');

        $this->assertSame(0, Import::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_import_status_is_returned(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);

        $import = Import::factory()->create([
            'supplier_id' => $supplier->id,
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01 10:00:00',
            'status' => ImportStatusEnum::COMPLETED,
            'total_offers' => 20,
            'processed_offers' => 20,
            'completed_at' => '2026-09-01 10:00:04',
        ]);

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $import->id,
                    'supplier' => 'supplier-a',
                    'external_import_id' => 'import-2026-09-01-001',
                    'status' => 'completed',
                    'total_offers' => 20,
                    'processed_offers' => 20,
                    'error' => null,
                ],
            ])
            ->assertJsonPath('data.sent_at', '2026-09-01T10:00:00.000000Z')
            ->assertJsonPath('data.completed_at', '2026-09-01T10:00:04.000000Z');
    }

    public function test_unknown_import_returns_404(): void
    {
        $this->getJson('/api/imports/999999')->assertNotFound();
    }

    private function samplePayload(Supplier $supplier, string $externalImportId): array
    {
        return [
            'supplier' => $supplier->code,
            'external_import_id' => $externalImportId,
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [
                [
                    'external_id' => 'offer-a-10001',
                    'property' => [
                        'code' => 'BCN-0001',
                        'name' => 'Apartment near Sagrada Familia',
                        'city' => 'Barcelona',
                    ],
                    'check_in' => '2026-10-10',
                    'check_out' => '2026-10-15',
                    'max_guests' => 4,
                    'price' => 72500,
                    'currency' => 'EUR',
                    'available_units' => 2,
                    'expires_at' => '2026-09-10T23:59:59Z',
                ],
            ],
        ];
    }
}
