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
