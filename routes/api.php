<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Route::post('/register', [UserController::class, 'register']); // Register user baru (bukan melalui admin)
Route::post('/login', [AuthController::class, 'login']); // login user
Route::post('/logout', [AuthController::class, 'logout']); // logout user
Route::get('/checkTokenValid', [AuthController::class, 'checkTokenValid'])->middleware('auth:api'); // periksa apakah token jwt masih valid atau tidak

Route::middleware(['auth:api', 'hashid', 'remove_nanoid', 'protectRootFolder', 'check_admin', 'hide_superadmin_flag'])->group(function () {

    Route::prefix('user')->controller(UserController::class)->group(function () {
        Route::get('/index', 'index'); // Mendapatkan informasi user
        Route::put('/update', 'update'); // Update user
        Route::delete('/delete', 'delete'); // Menghapus user
    });

    Route::prefix('folder')->controller(FolderController::class)->group(function () {
        Route::get('/', 'index'); // dapatkan list folder dan file yang ada pada user yang login saat ini pada folder rootnya.
        Route::get('/info/{id}', 'info'); // Mendapatkan informasi lengkap isi folder tertentu, termasuk file dan subfolder
        Route::post('/create', 'create');  // Membuat folder baru
        Route::put('/update/{user_id}', 'update'); // Memperbarui folder
        Route::delete('/delete/{user_id}', 'delete'); // Menghapus folder
        Route::put('/move', 'move'); // Memindahkan folder ke folder lain menggunakan metode parent_id yang baru
        Route::get('/path/{id}', 'getFullPath'); // Mendapatkan full path dari folder
    });

    Route::prefix('file')->controller(FileController::class)->group(function () {
        Route::get('/{id}', 'info'); // Mendapatkan informasi file
        Route::post('/create', 'create'); // Membuat file baru
        Route::post('/upload', 'upload'); // Mengunggah file
        Route::put('/change_name/{id}', 'updateFileName'); // Memperbarui nama file
        Route::delete('/delete/{id}', 'delete'); // Menghapus file
        Route::put('/move/{id}', 'move'); // Memindahkan file ke folder lain atau ke root
    });
});


// ROUTE KHUSUS UNTUK ADMIN
Route::middleware(['auth:api', 'validate_admin'])->group(function () {

    Route::prefix('admin')->controller(AdminController::class)->group(function () {

        Route::get('/index', 'index'); // dapatkan informasi tentang akun admin yang sedang login saat ini.
        Route::get('/get_user_info/{userId}', 'user_info'); // dapatkan informasi tentang user
        Route::post('/create_user', 'createUserFromAdmin'); // route untuk membuat user baru melalui admin.
        Route::put('/update_user/{userIdToBeUpdated}', 'updateUserFromAdmin'); // route untuk mengupdate user yang sudah ada melalui admin.
        Route::delete('/delete_user/{userIdToBeDeleted}', 'deleteUserFromAdmin'); // route untuk menghapus user yang sudah ada melalui admin. (DANGEROUS!)

        Route::prefix('folder')->controller(FolderController::class)->group(function () {
            Route::get('/', 'index'); // dapatkan list folder dan file yang ada pada user yang login saat ini pada folder rootnya.
            Route::get('/info/{id}', 'info'); // Mendapatkan informasi lengkap isi folder tertentu, termasuk file dan subfolder
            Route::post('/create', 'create');  // Membuat folder baru
            Route::put('/update/{id}', 'update'); // Memperbarui folder
            Route::delete('/delete/{id}', 'delete'); // Menghapus folder
            Route::put('/move', 'move'); // Memindahkan folder ke folder lain menggunakan metode parent_id yang baru
            Route::get('/path/{id}', 'getFullPath'); // Mendapatkan full path dari folder
        });

        Route::prefix('file')->controller(FileController::class)->group(function () {
            Route::get('/{id}', 'info'); // Mendapatkan informasi file
            Route::post('/create', 'create'); // Membuat file baru
            Route::post('/upload', 'upload'); // Mengunggah file
            Route::put('/change_name/{id}', 'updateFileName'); // Memperbarui nama file
            Route::delete('/delete/{id}', 'delete'); // Menghapus file
            Route::put('/move/{id}', 'move'); // Memindahkan file ke folder lain atau ke root
        });

    });

});
