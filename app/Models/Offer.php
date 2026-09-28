<?php

namespace App\Models;

use App\Exceptions\NoAvailableUnitsException;
use App\Exceptions\OfferExpiredException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'property_id',
        'external_id',
        'check_in',
        'check_out',
        'max_guests',
        'price',
        'currency',
        'available_units',
        'expires_at',
        'source_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'expires_at' => 'datetime',
            'source_sent_at' => 'datetime',
            'max_guests' => 'integer',
            'price' => 'integer',
            'available_units' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public static function cheapestPerPropertyQuery(
        string $checkIn,
        string $checkOut,
        int $guests,
        ?string $city = null,
    ): Builder {
        $ranked = static::query()
            ->join('properties', 'properties.id', '=', 'offers.property_id')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->where('offers.check_in', $checkIn)
            ->where('offers.check_out', $checkOut)
            ->where('offers.max_guests', '>=', $guests)
            ->where('offers.available_units', '>', 0)
            ->where('offers.expires_at', '>', now())
            ->when(
                !empty($city),
                fn ($query) => $query->where('properties.city', $city)
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

        return DB::query()
            ->fromSub($ranked, 'ranked_offers')
            ->where('rn', 1)
            ->orderBy('price')
            ->orderBy('offer_id');
    }

    public function reserve(array $customer): Reservation
    {
        return DB::transaction(function () use ($customer) {
            $lockedOffer = static::whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->expires_at->lte(now())) {
                throw new OfferExpiredException();
            }

            if ($lockedOffer->available_units < 1) {
                throw new NoAvailableUnitsException();
            }

            $lockedOffer->decrement('available_units');

            return $lockedOffer->reservations()->create([
                'client_reference' => $customer['client_reference'],
                'customer_name' => $customer['customer_name'],
                'customer_email' => $customer['customer_email'],
            ]);
        });
    }
}
