<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class OfferExpiredException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'This offer has expired.',
        ], 409);
    }
}
