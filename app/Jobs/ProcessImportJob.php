<?php

namespace App\Jobs;

use App\Enums\ImportStatusEnum;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    // Данные детерминированы (пришли одним payload'ом), повторный запуск после
    // ошибки только продублирует ту же ошибку — поэтому не ретраим бесконечно.

    public int $tries = 1;

    public function __construct(public Import $import)
    {}

    public function handle(): void
    {
        $this->import->update(['status' => ImportStatusEnum::PROCESSING]);

        $processed = DB::transaction(function (): int {

            // Import::$casts содержит 'payload' => 'array', это уже PHP-массив офферов.
            $offers = $this->import->payload;

            // --- Фаза 1: пакетный upsert Property ---
            // Один и тот же объект недвижимости может встречаться в нескольких офферах
            // одного импорта, поэтому сначала дедуплицируем по code в PHP.
            $propertiesByCode = [];
            foreach ($offers as $offerData) {
                $propertiesByCode[$offerData['property']['code']] = [
                    'code' => $offerData['property']['code'],
                    'name' => $offerData['property']['name'],
                    'city' => $offerData['property']['city'],
                ];
            }

            // upsert() выполняется на уровне query builder'а одним SQL-запросом
            // (без мутаторов/событий модели); timestamps Eloquent проставляет сам.
            Property::upsert(
                array_values($propertiesByCode),
                uniqueBy: ['code'],
                update: ['name', 'city']
            );

            // Одним запросом получаем актуальные id всех нужных property.
            $propertyIdsByCode = Property::query()
                ->whereIn('code', array_keys($propertiesByCode))
                ->pluck('id', 'code');

            // --- Фаза 2: пакетный upsert Offer ---
            $offersData = [];
            foreach ($offers as $offerData) {
                $offersData[] = [
                    'supplier_id' => $this->import->supplier_id,
                    'property_id' => $propertyIdsByCode[$offerData['property']['code']],
                    'external_id' => $offerData['external_id'],
                    // upsert() пишет значения напрямую в БД, минуя casts модели,
                    // поэтому даты нормализуем вручную под тип колонки
                    // (offers.check_in/check_out — date, expires_at — dateTime).
                    'check_in' => Carbon::parse($offerData['check_in'])->toDateString(),
                    'check_out' => Carbon::parse($offerData['check_out'])->toDateString(),
                    'max_guests' => $offerData['max_guests'],
                    'price' => $offerData['price'],
                    'currency' => $offerData['currency'],
                    'available_units' => $offerData['available_units'],
                    'expires_at' => Carbon::parse($offerData['expires_at'])->toDateTimeString(),
                ];
            }

            Offer::upsert(
                $offersData,
                uniqueBy: ['supplier_id', 'external_id'],
                update: [
                    'property_id', 'check_in', 'check_out', 'max_guests',
                    'price', 'currency', 'available_units', 'expires_at',
                ]
            );

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
        // Laravel вызывает этот метод сам, когда джоба окончательно провалилась —
        // при $tries = 1 это сразу после первой же непойманной ошибки в handle().
        // DB::transaction() к этому моменту уже откатил все изменения текущего импорта,
        // так что остаётся только зафиксировать финальный статус.
        $this->import->update([
            'status' => ImportStatusEnum::FAILED,
            'error' => $exception?->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
