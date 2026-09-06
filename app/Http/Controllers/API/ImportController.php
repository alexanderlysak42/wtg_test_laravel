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
use OpenApi\Attributes as OA;

class ImportController extends Controller
{
    #[OA\Post(
        path: '/api/imports',
        summary: 'Поставити імпорт пропозицій в чергу на обробку',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier', 'external_import_id', 'sent_at', 'offers'],
                properties: [
                    new OA\Property(property: 'supplier', type: 'string', example: 'supplier-a'),
                    new OA\Property(property: 'external_import_id', type: 'string', example: 'import-2026-09-01-001'),
                    new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', example: '2026-09-01T10:00:00Z'),
                    new OA\Property(
                        property: 'offers',
                        type: 'array',
                        items: new OA\Items(
                            required: ['external_id', 'property', 'check_in', 'check_out', 'max_guests', 'price', 'currency', 'available_units', 'expires_at'],
                            properties: [
                                new OA\Property(property: 'external_id', type: 'string', example: 'offer-a-10001'),
                                new OA\Property(
                                    property: 'property',
                                    required: ['code', 'name', 'city'],
                                    properties: [
                                        new OA\Property(property: 'code', type: 'string', example: 'BCN-0001'),
                                        new OA\Property(property: 'name', type: 'string', example: 'Apartment near Sagrada Familia'),
                                        new OA\Property(property: 'city', type: 'string', example: 'Barcelona'),
                                    ],
                                    type: 'object'
                                ),
                                new OA\Property(property: 'check_in', type: 'string', format: 'date', example: '2026-10-10'),
                                new OA\Property(property: 'check_out', type: 'string', format: 'date', example: '2026-10-15'),
                                new OA\Property(property: 'max_guests', type: 'integer', example: 4),
                                new OA\Property(property: 'price', description: 'Ціна в мінімальних одиницях валюти (центах)', type: 'integer', example: 72500),
                                new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
                                new OA\Property(property: 'available_units', type: 'integer', example: 2),
                                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', example: '2026-09-10T23:59:59Z'),
                            ],
                            type: 'object'
                        )
                    ),
                ]
            )
        ),
        tags: ['Imports'],
        responses: [
            new OA\Response(
                response: 202,
                description: 'Імпорт прийнято і поставлено в чергу (або вже існував — ідемпотентний повтор)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 15),
                                new OA\Property(property: 'status', type: 'string', example: 'pending'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Помилка валідації або невідомий supplier'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/imports/{import}',
        summary: 'Отримати поточний статус асинхронного імпорту',
        tags: ['Imports'],
        parameters: [
            new OA\Parameter(name: 'import', description: 'ID імпорту', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Поточний стан імпорту',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 15),
                                new OA\Property(property: 'supplier', type: 'string', example: 'supplier-a'),
                                new OA\Property(property: 'external_import_id', type: 'string', example: 'import-2026-09-01-001'),
                                new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', example: '2026-09-01T10:00:00Z'),
                                new OA\Property(property: 'status', type: 'string', example: 'completed', enum: ['pending', 'processing', 'completed', 'failed']),
                                new OA\Property(property: 'total_offers', type: 'integer', example: 20),
                                new OA\Property(property: 'processed_offers', type: 'integer', example: 20),
                                new OA\Property(property: 'error', type: 'string', example: null, nullable: true),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-09-01T10:00:02Z'),
                                new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', example: '2026-09-01T10:00:04Z', nullable: true),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Імпорт з таким ID не знайдено'),
        ]
    )]
    public function show(Import $import): ImportResource
    {
        return new ImportResource($import->load('supplier'));
    }
}
