<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * GET /api/properties
 */
class PropertySearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->property_code,
            'name' => $this->property_name,
            'city' => $this->property_city,
            'best_offer' => [
                'id' => $this->offer_id,
                'supplier' => $this->supplier_code,
                'price' => (int) $this->price,
                'currency' => $this->currency,
                'available_units' => (int) $this->available_units,
                'expires_at' => Carbon::parse($this->expires_at)->toISOString(),
            ],
        ];
    }
}
