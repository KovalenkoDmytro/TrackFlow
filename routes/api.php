<?php

declare(strict_types=1);

use App\Http\Controllers\ConversionController;
use Illuminate\Support\Facades\Route;

Route::post('/conversions', [ConversionController::class, 'track']);
