<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'WTG Test API documentation',
    title: 'WTG Test API'
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'Локальне Docker-оточення'
)]
abstract class Controller
{
    //
}
