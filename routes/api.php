<?php

use App\Http\Controllers\FolderController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'index']);
Route::post('/logout', [LoginController::class, 'logout']);

Route::middleware('auth:api')->group(function () {
    Route::prefix('folder')->group(function () {
        Route::get('/', [FolderController::class, 'index']); // Rute untuk folder root (tanpa parentId)
        Route::get('/{parentId}', [FolderController::class, 'index']); // Rute untuk folder dengan parentId
        Route::get('/info/{id}', [FolderController::class, 'info']); // Mendapatkan informasi lengkap folder, termasuk file dan subfolder
        Route::post('/create', [FolderController::class, 'create']);  // Membuat folder baru
        Route::put('/update/{id}', [FolderController::class, 'update']); // Memperbarui nama folder
        Route::delete('/delete/{id}', [FolderController::class, 'delete']); // Menghapus folder
        Route::put('/move/{id}', [FolderController::class, 'move']); // Memindahkan folder ke parent folder lain
        Route::get('/path/{id}', [FolderController::class, 'getFullPath']); // Mendapatkan full path dari folder
    });

    Route::prefix('file')->group(function () {
        Route::get('/{id}', [FileController::class, 'info']); // Mendapatkan informasi file
        Route::post('/create', [FileController::class, 'create']); // Membuat file baru
        Route::post('/upload', [FileController::class, 'upload']); // Mengunggah file
        Route::put('/update/{id}', [FileController::class, 'update']); // Memperbarui nama file
        Route::delete('/delete/{id}', [FileController::class, 'delete']); // Menghapus file
        Route::post('/move/{id}', [FileController::class, 'move']); // Memindahkan file ke folder lain atau ke root
    });
});
