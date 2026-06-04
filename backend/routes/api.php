<?php

use App\Http\Controllers\RunController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Starfall Station API',
    ]);
});

Route::post('/runs', [RunController::class, 'store']);
Route::get('/runs/{run}', [RunController::class, 'show']);
Route::post('/runs/{run}/advance', [RunController::class, 'advance']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
