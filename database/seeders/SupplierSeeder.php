<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(['code' => 'supplier-a'], ['name' => 'Supplier A']);
        Supplier::updateOrCreate(['code' => 'supplier-b'], ['name' => 'Supplier B']);
    }
}
