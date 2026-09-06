<?php

namespace App\Http\Controllers\API;

use App\Exceptions\NoAvailableUnitsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ReservationController extends Controller
{
    #[OA\Post(
        path: '/api/offers/{offer}/reservations',
        summary: 'Забронювати пропозицію',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_reference', 'customer_name', 'customer_email'],
                properties: [
                    new OA\Property(property: 'client_reference', type: 'string', example: 'web-order-9f782b1c'),
                    new OA\Property(property: 'customer_name', type: 'string', example: 'John Smith'),
                    new OA\Property(property: 'customer_email', type: 'string', format: 'email', example: 'john@example.com'),
                ]
            )
        ),
        tags: ['Reservations'],
        parameters: [
            new OA\Parameter(name: 'offer', description: 'ID пропозиції', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 125)),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Бронювання успішно створено',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 7),
                                new OA\Property(property: 'offer_id', type: 'integer', example: 125),
                                new OA\Property(property: 'client_reference', type: 'string', example: 'web-order-9f782b1c'),
                                new OA\Property(property: 'customer_name', type: 'string', example: 'John Smith'),
                                new OA\Property(property: 'customer_email', type: 'string', example: 'john@example.com'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-09-01T10:05:00Z'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Пропозицію з таким ID не знайдено'),
            new OA\Response(response: 409, description: 'Вільних місць на цю пропозицію не залишилось'),
            new OA\Response(response: 422, description: 'Помилка валідації даних бронювання'),
        ]
    )]
    public function store(StoreReservationRequest $request, Offer $offer): JsonResponse
    {
        $data = $request->validated();

        $reservation = DB::transaction(function () use ($offer, $data) {

            $lockedOffer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->available_units < 1) {
                throw new NoAvailableUnitsException();
            }

            $lockedOffer->decrement('available_units');

            return Reservation::create([
                'offer_id' => $lockedOffer->id,
                'client_reference' => $data['client_reference'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
            ]);
        });

        return (new ReservationResource($reservation))
            ->response()
            ->setStatusCode(201);
    }
}
