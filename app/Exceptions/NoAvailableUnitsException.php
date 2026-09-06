<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class NoAvailableUnitsException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'No available units left for this offer.',
        ], 409);
    }
}
