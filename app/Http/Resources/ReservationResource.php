<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/offers/{offer}/reservations
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'client_reference' => $this->client_reference,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
