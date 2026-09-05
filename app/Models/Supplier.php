<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name'];

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }
}
