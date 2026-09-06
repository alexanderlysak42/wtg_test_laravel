<?php

use App\Http\Controllers\API\ImportController;
use App\Http\Controllers\API\PropertyController;
use App\Http\Controllers\API\ReservationController;
use Illuminate\Support\Facades\Route;

Route::post('imports', [ImportController::class, 'store']);
Route::get('imports/{import}', [ImportController::class, 'show']);

Route::get('properties', [PropertyController::class, 'index']);

Route::post('offers/{offer}/reservations', [ReservationController::class, 'store']);
