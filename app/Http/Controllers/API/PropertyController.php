<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertySearchResource;
use App\Models\Offer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class PropertyController extends Controller
{
    #[OA\Get(
        path: '/api/properties',
        summary: 'Знайти актуальні об\'єкти житла з найдешевшою пропозицією на кожен',
        tags: ['Properties'],
        parameters: [
            new OA\Parameter(name: 'city', description: 'Фільтр за містом', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'Barcelona')),
            new OA\Parameter(name: 'check_in', description: 'Дата заїзду', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-10-10')),
            new OA\Parameter(name: 'check_out', description: 'Дата виїзду (пізніше за check_in)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-10-15')),
            new OA\Parameter(name: 'guests', description: 'Кількість гостей (за замовчуванням 1)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 2, minimum: 1)),
            new OA\Parameter(name: 'page', description: 'Номер сторінки', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 1, minimum: 1)),
            new OA\Parameter(name: 'per_page', description: 'Розмір сторінки (за замовчуванням 15, максимум 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 15, maximum: 100, minimum: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Список об\'єктів житла з найдешевшою актуальною пропозицією на кожен',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'code', type: 'string', example: 'BCN-0001'),
                                    new OA\Property(property: 'name', type: 'string', example: 'Apartment near Sagrada Familia'),
                                    new OA\Property(property: 'city', type: 'string', example: 'Barcelona'),
                                    new OA\Property(
                                        property: 'best_offer',
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 125),
                                            new OA\Property(property: 'supplier', type: 'string', example: 'supplier-a'),
                                            new OA\Property(property: 'price', description: 'Ціна в мінімальних одиницях валюти (центах)', type: 'integer', example: 72500),
                                            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
                                            new OA\Property(property: 'available_units', type: 'integer', example: 2),
                                            new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', example: '2026-09-10T23:59:59Z'),
                                        ],
                                        type: 'object'
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(
                            property: 'meta',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 1),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Помилка валідації параметрів пошуку (відсутні або некоректні check_in/check_out)'),
        ]
    )]
    public function index(SearchPropertiesRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $properties = Offer::cheapestPerPropertyQuery(
            $data['check_in'],
            $data['check_out'],
            $data['guests'] ?? 1,
            $data['city'] ?? null,
        )
            ->paginate($data['per_page'] ?? 15)
            ->withQueryString();

        return PropertySearchResource::collection($properties);
    }
}
