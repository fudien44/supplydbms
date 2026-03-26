<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MosItrController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/itrindex', [MosItrController::class, 'index']);
Route::get('/listinventory', [MosItrController::class, 'listInventory']);
// Route::get('/getsignatories', [MosItrController::class, 'getSignatories']);