<?php

namespace App\Http\Controllers\API;

use App\Enums\ImportStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    public function store(StoreImportRequest $request): JsonResponse
    {
        $data = $request->validated();

        $supplier = Supplier::where('code', $data['supplier'])->firstOrFail();

        // firstOrCreate: сначала SELECT по ключу дедупликации (supplier_id + external_import_id).
        // Если записи нет — пытается create(); если в этот момент параллельный запрос уже
        // вставил такую же строку, Laravel 12 поймает UniqueConstraintViolationException
        // внутри createOrFirst() и вернёт уже существующую запись вместо падения с ошибкой.

        $import = Import::firstOrCreate(
            [
                'supplier_id' => $supplier->id,
                'external_import_id' => $data['external_import_id'],
            ],
            [
                'sent_at' => $data['sent_at'],
                'status' => ImportStatusEnum::PENDING,
                'total_offers' => count($data['offers']),
                'payload' => $data['offers'],
            ]
        );

        // Диспатчим обработку только для реально новой записи — иначе повторная отправка
        // того же импорта (тот же external_import_id) заново запустит обработку офферов.

        if ($import->wasRecentlyCreated) {
           ProcessImportJob::dispatch($import);
        }

        return response()->json([
            'data' => ['id' => $import->id, 'status' => $import->status->value],
        ], 202);

    }

    public function show(Import $import): ImportResource
    {
        return new ImportResource($import->load('supplier'));
    }
}
