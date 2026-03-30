<?php

use App\Http\Controllers\loginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MosItrController;
use App\Http\Controllers\MRInventoryRecordsController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/itrindex', [MosItrController::class, 'index']);
Route::get('/listinventory', [MosItrController::class, 'listInventory']);
// Route::get('/getsignatories', [MosItrController::class, 'getSignatories']);
Route::post('/login', [loginController::class, 'login'])->name('login');

Route::get('/test-db', function () {

    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'success',
            'message' => 'MSSQL Connected'
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }

});

Route::group(['prefix' => 'mr-inventory-records'], function () {
// Route::group(['middleware' => 'auth:sanctum'], function (){
    //MR INVENTORY RECORDS
Route::get('/', [MRInventoryRecordsController::class, 'index']);
Route::get('/categories', [MRInventoryRecordsController::class, 'categories']);
Route::get('/list', [MRInventoryRecordsController::class, 'inventoryList']);
Route::get('/{inventoryId}/issued', [MRInventoryRecordsController::class, 'recordList']);
Route::delete('/{id}', [MRInventoryRecordsController::class, 'destroy']);
Route::get('/mr-inventory-records-export', [MRInventoryRecordsController::class, 'export']);
});
// });
