<?php

namespace Tests\Feature;

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
        $response = $this->postJson('/api/imports', [
            'supplier' => 'unknown-supplier',
            'external_import_id' => 'import-002',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [],
        ]);

        $response->assertStatus(422);
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
