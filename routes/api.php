<?php

use App\Http\Controllers\FolderController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [UserController::class, 'register']); // Register user baru (bukan melalui admin)
Route::post('/login', [AuthController::class, 'login']); // login user
Route::post('/logout', [AuthController::class, 'logout']); // logout user

Route::middleware(['auth:api', 'hashid', 'remove_nanoid', 'protectRootFolder'])->group(function () {

    Route::get('/checkTokenValid', [AuthController::class, 'checkTokenValid']); // periksa apakah token jwt masih valid atau tidak

    Route::prefix('user')->group(function () {
        Route::get('/', [UserController::class, 'index']); // Mendapatkan informasi user
        Route::put('/update', [UserController::class, 'update']); // Update user
        Route::delete('/delete', [UserController::class, 'delete']); // Menghapus user
    });

    Route::prefix('folder')->group(function () {
        Route::get('/', [FolderController::class, 'index']); // dapatkan list folder dan file yang ada pada user yang login saat ini pada folder rootnya.
        Route::get('/info/{id}', [FolderController::class, 'info']); // Mendapatkan informasi lengkap isi folder tertentu, termasuk file dan subfolder
        Route::post('/create', [FolderController::class, 'create']);  // Membuat folder baru
        Route::put('/update/{id}', [FolderController::class, 'update']); // Memperbarui folder
        Route::delete('/delete/{id}', [FolderController::class, 'delete']); // Menghapus folder
        Route::put('/move', [FolderController::class, 'move']); // Memindahkan folder ke folder lain menggunakan metode parent_id yang baru
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
