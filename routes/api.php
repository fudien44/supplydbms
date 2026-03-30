<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RPCPPE\RPCPPEController;
use Illuminate\Support\Facades\Auth;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/item-master-list', [RPCPPEController::class, 'masterlist']);
Route::get('/acc-officer', [RPCPPEController::class, 'accOfficer']);
Route::get('/categories', [RPCPPEController::class, 'categories']);
Route::get('/fund-cluster', [RPCPPEController::class, 'fund_cluster']);
Route::get('/entities', [RPCPPEController::class, 'entity']);
