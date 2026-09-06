<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPropertiesRequest;
use App\Http\Resources\PropertySearchResource;
use App\Models\Offer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function index(SearchPropertiesRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();
        $guests = $data['guests'] ?? 1;
        $perPage = $data['per_page'] ?? 15;

        $ranked = Offer::query()
            ->join('properties', 'properties.id', '=', 'offers.property_id')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->where('offers.check_in', $data['check_in'])
            ->where('offers.check_out', $data['check_out'])
            ->where('offers.max_guests', '>=', $guests)
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now())
            ->when(
                !empty($data['city']),
                fn ($query) => $query->where('properties.city', $data['city'])
            )
            ->select([
                'properties.code as property_code',
                'properties.name as property_name',
                'properties.city as property_city',
                'offers.id as offer_id',
                'suppliers.code as supplier_code',
                'offers.price as price',
                'offers.currency as currency',
                'offers.available_units as available_units',
                'offers.expires_at as expires_at',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY offers.property_id ORDER BY offers.price ASC, offers.id ASC) AS rn'
            );

        $propertiesPaginated = DB::query()
            ->fromSub($ranked, 'ranked_offers')
            ->where('rn', 1)
            ->orderBy('price')
            ->paginate($perPage)
            ->withQueryString();

        return PropertySearchResource::collection($propertiesPaginated);
    }
}
