<?php

use App\Http\Controllers\loginController;
use App\Http\Controllers\MosIcsController;
use App\Http\Controllers\MosParController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RPCPPE\RPCPPEController;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\MosItrController;
use App\Http\Controllers\MRInventoryRecordsController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::get('/item-master-list', [RPCPPEController::class, 'masterlist']);
Route::get('/acc-officer', [RPCPPEController::class, 'accountableOfficers']);
Route::get('/categories', [RPCPPEController::class, 'categories']);

Route::get('/ppe-types', [RPCPPEController::class, 'getPpeTypes']);
Route::post('/ppe-types', [RPCPPEController::class, 'storePpeType']);
Route::put('/ppe-types/{id}', [RPCPPEController::class, 'updatePpeType']);
Route::delete('/ppe-types/{id}', [RPCPPEController::class, 'deletePpeType']);

Route::get('/articles', [RPCPPEController::class, 'getArticles']);
Route::post('/articles', [RPCPPEController::class, 'storeArticle']);
Route::put('/articles/{id}', [RPCPPEController::class, 'updateArticle']);
Route::delete('/articles/{id}', [RPCPPEController::class, 'deleteArticle']);

Route::get('/rpcppe', [RPCPPEController::class, 'getItems']);
Route::post('/rpcppe/store', [RPCPPEController::class, 'storeItems']);
Route::post('/rpcppe/delete', [RPCPPEController::class, 'deleteItems']);
Route::post('/rpcppe/update', [RPCPPEController::class, 'updateItems']);

Route::post('/rpcppe/preview', [RPCPPEController::class, 'previewReport']);
Route::post('/rpcppe/export-pdf', [RPCPPEController::class, 'exportPdf']);
Route::post('/rpcppe/export-excel', [RPCPPEController::class, 'exportExcel']);
Route::group(['prefix' => 'itr'], function () {
    Route::get('/itrindex', [MosItrController::class, 'index']);
    Route::get('/listinventory', [MosItrController::class, 'listInventory']);
    Route::get('/listgeninventory', [MosItrController::class, 'listGenInventory']);
    Route::get('/listfacilityagency', [MosItrController::class, 'listFacilityAgency']);
    Route::post('/save', [MosItrController::class, 'save']);
    Route::get('/list', [MosItrController::class, 'listItr']);
    Route::get('/export', [MosItrController::class, 'export']);
    Route::get('/{id}/pdf', [MosItrController::class, 'generatePdf']);
    Route::get('/{id}/attachments', [MosItrController::class, 'listAttachments']); // ← ADD
    Route::post('/{id}/upload-attachment', [MosItrController::class, 'uploadAttachment']);
    Route::get('/{id}/attachments/{attachmentId}/download', [MosItrController::class, 'downloadAttachment']);
    Route::get('/{id}', [MosItrController::class, 'showItr']); // ← keep /{id} catch-all LAST
    Route::delete('delete/{id}', [MosItrController::class, 'destroy']);
    Route::delete('/{id}/attachments/{attachmentId}', [MosItrController::class, 'deleteAttachment']);
});

Route::post('/login', [loginController::class, 'login'])->name('login');

Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'success',
            'message' => 'MSSQL Connected',
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
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

Route::group(['prefix' => 'ics'], function () {
    Route::get('/index', [MosIcsController::class, 'index']);
    Route::get('/next-no', [MosIcsController::class, 'nextNo']);
    Route::get('/listinventory', [MosIcsController::class, 'listInventory']);
    Route::get('/list', [MosIcsController::class, 'listIcs']);
    Route::get('/export', [MosIcsController::class, 'export']);
    Route::post('/save', [MosIcsController::class, 'save']);
 
    Route::get('/{id}/pdf', [MosIcsController::class, 'pdf']);
    Route::get('/{id}/attachments', [MosIcsController::class, 'listAttachments']);
    Route::post('/{id}/upload-attachment', [MosIcsController::class, 'uploadAttachment']);
    Route::get('/{id}/attachments/{attachmentId}/download', [MosIcsController::class, 'downloadAttachment']);
    Route::delete('/{id}/attachments/{attachmentId}', [MosIcsController::class, 'deleteAttachment']);
 
    Route::get('/{id}', [MosIcsController::class, 'show']);
    Route::delete('/{id}', [MosIcsController::class, 'destroy']);
});

Route::group(['prefix' => 'par'], function () {
    Route::get('/index', [MosParController::class, 'index']);
    Route::get('/next-no', [MosParController::class, 'nextNo']);
    Route::get('/listinventory', [MosParController::class, 'listInventory']);
    Route::get('/list', [MosParController::class, 'listPar']);
    Route::get('/export', [MosParController::class, 'export']);
    Route::post('/save', [MosParController::class, 'save']);
 
    Route::get('/{id}/pdf', [MosParController::class, 'pdf']);
    Route::get('/{id}/attachments', [MosParController::class, 'listAttachments']);
    Route::post('/{id}/upload-attachment', [MosParController::class, 'uploadAttachment']);
    Route::get('/{id}/attachments/{attachmentId}/download', [MosParController::class, 'downloadAttachment']);
    Route::delete('/{id}/attachments/{attachmentId}', [MosParController::class, 'deleteAttachment']);
 
    Route::get('/{id}', [MosParController::class, 'show']);
    Route::delete('/{id}', [MosParController::class, 'destroy']);
});
 
// });
