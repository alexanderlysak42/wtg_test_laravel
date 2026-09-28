<?php

namespace App\Jobs;

use App\Enums\ImportStatusEnum;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public Import $import)
    {}

    public function handle(): void
    {
        if ($this->import->status === ImportStatusEnum::COMPLETED) {
            return;
        }

        $this->import->update(['status' => ImportStatusEnum::PROCESSING]);

        $processed = DB::transaction(function (): int {

            $offers = $this->import->payload;

            $propertiesByCode = [];
            foreach ($offers as $offerData) {
                $propertiesByCode[$offerData['property']['code']] = [
                    'code' => $offerData['property']['code'],
                    'name' => $offerData['property']['name'],
                    'city' => $offerData['property']['city'],
                ];
            }

            Property::upsert(
                array_values($propertiesByCode),
                uniqueBy: ['code'],
                update: ['name', 'city']
            );

            $propertyIdsByCode = Property::query()
                ->whereIn('code', array_keys($propertiesByCode))
                ->pluck('id', 'code');

            $sentAt = $this->import->sent_at;

            $existingOffers = Offer::query()
                ->where('supplier_id', $this->import->supplier_id)
                ->whereIn('external_id', array_column($offers, 'external_id'))
                ->lockForUpdate()
                ->get(['id', 'external_id', 'source_sent_at'])
                ->keyBy('external_id');

            $reservedUnitsSinceSentAt = Reservation::query()
                ->whereIn('offer_id', $existingOffers->pluck('id'))
                ->where('created_at', '>', $sentAt)
                ->groupBy('offer_id')
                ->selectRaw('offer_id, SUM(units) AS reserved_units')
                ->pluck('reserved_units', 'offer_id');

            $offersData = [];
            foreach ($offers as $offerData) {
                $existingOffer = $existingOffers->get($offerData['external_id']);

                if ($existingOffer?->source_sent_at?->gt($sentAt)) {
                    continue;
                }

                $reservedUnits = $existingOffer
                    ? (int) $reservedUnitsSinceSentAt->get($existingOffer->id, 0)
                    : 0;

                $offersData[] = [
                    'supplier_id' => $this->import->supplier_id,
                    'property_id' => $propertyIdsByCode[$offerData['property']['code']],
                    'external_id' => $offerData['external_id'],
                    'check_in' => Carbon::parse($offerData['check_in'])->toDateString(),
                    'check_out' => Carbon::parse($offerData['check_out'])->toDateString(),
                    'max_guests' => $offerData['max_guests'],
                    'price' => $offerData['price'],
                    'currency' => $offerData['currency'],
                    'available_units' => max($offerData['available_units'] - $reservedUnits, 0),
                    'expires_at' => Carbon::parse($offerData['expires_at'])->toDateTimeString(),
                    'source_sent_at' => $sentAt->toDateTimeString(),
                ];
            }

            if ($offersData !== []) {
                Offer::upsert(
                    $offersData,
                    uniqueBy: ['supplier_id', 'external_id'],
                    update: [
                        'property_id', 'check_in', 'check_out', 'max_guests',
                        'price', 'currency', 'available_units', 'expires_at',
                        'source_sent_at',
                    ]
                );
            }

            return count($offers);
        });

        $this->import->update([
            'status' => ImportStatusEnum::COMPLETED,
            'processed_offers' => $processed,
            'completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->update([
            'status' => ImportStatusEnum::FAILED,
            'error' => $exception?->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
