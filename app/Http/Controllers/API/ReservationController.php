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

class ReservationController extends Controller
{
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
