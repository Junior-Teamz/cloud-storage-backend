<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FAQController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\PermissionFileController;
use App\Http\Controllers\PermissionFolderController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SharingController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;


Route::post('/github-webhook', [WebhookController::class, 'handle']);

// Route::post('/register', [UserController::class, 'register']); // Register user baru (bukan melalui admin)

Route::post('/login', [AuthController::class, 'login']); // login user

Route::post('/checkTokenValid', [AuthController::class, 'checkTokenValid']); // TODO: periksa apakah token jwt masih valid atau tidak

// Route::post('/refreshToken', [AuthController::class, 'refreshToken'])->middleware('auth:api'); // TODO: refresh token jwt

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');  // logout user

Route::get('/file/preview/{fileId}', [FileController::class, 'serveFileImageById'])->name('image.url')->middleware(['auth:api']);

Route::middleware(['encode_id', 'decode_id'])->group(function () {

    Route::middleware(['auth:api', 'remove_nanoid', 'protectRootFolder', 'check_admin', 'hide_superadmin_flag'])->group(function () {

        Route::get('/searchFolderOrFile', [SearchController::class, 'searchFoldersAndFiles']); // Search Folder or File by name

        Route::get('/getSharedFolderAndFile', [SharingController::class, 'getSharedFolderAndFile']); // Mendapatkan semua folder dan file yang dibagikan kepada user

        Route::prefix('user')->group(function () {
            Route::get('/index', [UserController::class, 'index']); // Mendapatkan informasi user

            // Route::put('/update', [UserController::class, 'update']); // Update user

            // Route::delete('/delete', [UserController::class, 'delete']); // Menghapus user
        });

        Route::prefix('folder')->group(function () {
            Route::get('/', [FolderController::class, 'index']); // dapatkan list folder dan file yang ada pada user yang login saat ini pada folder rootnya.

            Route::get('/info/{id}', [FolderController::class, 'info']); // Mendapatkan informasi lengkap isi folder tertentu, termasuk file dan subfolder

            Route::get('/storageSizeUsage', [FolderController::class, 'storageSizeUsage']); // Informasi total penyimpanan yang digunakan

            Route::post('/create', [FolderController::class, 'create']);  // Membuat folder baru

            Route::put('/update/{id}', [FolderController::class, 'update']); // Memperbarui folder

            Route::post('/delete', [FolderController::class, 'delete']); // Menghapus folder. (NOTE: HARUS MENGGUNAKAN ARRAY BERISI ID FOLDER!)

            Route::post('/addTag', [FolderController::class, 'addTagToFolder']); // Tambahkan tag ke folder

            Route::post('/removeTag', [FolderController::class, 'removeTagFromFolder']); // hapus tag dari folder

            Route::put('/move', [FolderController::class, 'move']); // Memindahkan folder ke folder lain menggunakan metode parent_id yang baru

            Route::get('/path/{id}', [FolderController::class, 'getFullPath']); // Mendapatkan full path dari folder
        });

        Route::prefix('file')->group(function () {

            Route::get('/all', [FileController::class, 'getAllFilesAndTotalSize']); // Mendapatkan semua informasi file user, tidak peduli dari folder apapun.

            Route::get('/getUserSharedFolder/{id}', [SharingController::class, 'getListUserSharedFolder']); // Mendapatkan semua list user yang dibagian dari suatu folder

            Route::get('/{id}', [FileController::class, 'info']); // Mendapatkan informasi file

            Route::post('/upload', [FileController::class, 'upload']); // Mengunggah file

            Route::post('/download', [FileController::class, 'downloadFile']); // Mendownload File

            Route::put('/change_name/{id}', [FileController::class, 'updateFileName']); // Memperbarui nama file

            Route::post('/delete', [FileController::class, 'delete']); // Menghapus file

            Route::put('/move/{id}', [FileController::class, 'move']); // Memindahkan file ke folder lain atau ke root
        });

        Route::prefix('permission')->group(function () {

            Route::prefix('folder')->group(function () {

                Route::get('/getAllPermission/{folderId}', [PermissionFolderController::class, 'getAllPermissionOnFolder']); // Get all user permission on folder

                Route::post('/getPermission', [PermissionFolderController::class, 'getPermission']); // Get spesific user permission on folder

                Route::post('/grantPermission', [PermissionFolderController::class, 'grantFolderPermission']); // Grant user permission on folder

                Route::put('/changePermission', [PermissionFolderController::class, 'changeFolderPermission']); // Change user permission on folder

                Route::post('/revokeAllPermission', [PermissionFolderController::class, 'revokeAllFolderPermission']);
            });

            Route::prefix('file')->group(function () {

                Route::get('/getAllPermission/{folderId}', [PermissionFileController::class, 'getAllPermissionOnFile']);

                Route::post('/getPermission', [PermissionFileController::class, 'getPermission']);

                Route::post('/grantPermission', [PermissionFileController::class, 'grantFilePermission']);

                Route::put('/changePermission', [PermissionFileController::class, 'changeFilePermission']);

                Route::post('/revokeAllPermission', [PermissionFileController::class, 'revokeAllFilePermission']);
            });
        });
    });


    // ROUTE KHUSUS UNTUK ADMIN
    Route::prefix('admin')->middleware(['auth:api', 'validate_admin'])->group(function () {

        Route::get('/index', [AdminController::class, 'index']); // dapatkan informasi tentang akun admin yang sedang login saat ini.

        Route::get('/searchFolderOrFile', [SearchController::class, 'searchFoldersAndFiles']); // Search Folder or File by name

        Route::get('/getSharedFolderAndFile', [SharingController::class, 'getSharedFolderAndFile']); // Mendapatkan semua folder dan file yang dibagikan kepada user

        Route::prefix('users')->group(function () {
            Route::get('/list', [AdminController::class, 'listUser']); // dapatkan list user (bisa juga menggunakan query seperti ini: /list?name=namauseryangingindicari)

            Route::get('/info/{userId}', [AdminController::class, 'user_info']); // dapatkan informasi tentang user

            Route::post('/create_user', [AdminController::class, 'createUserFromAdmin']); // route untuk membuat user baru melalui admin.

            Route::put('/update_user/{userIdToBeUpdated}', [AdminController::class, 'updateUserFromAdmin']); // route untuk mengupdate user yang sudah ada melalui admin.

            Route::delete('/delete_user/{userIdToBeDeleted}', [AdminController::class, 'deleteUserFromAdmin']); // route untuk menghapus user yang sudah ada melalui admin. (DANGEROUS!)
        });

        Route::prefix('folder')->group(function () {
            Route::get('/', [FolderController::class, 'index']); // dapatkan list folder dan file yang ada pada user yang login saat ini pada folder rootnya.

            Route::get('/getUserSharedFolder/{id}', [SharingController::class, 'getListUserSharedFolder']); // Mendapatkan semua list user yang dibagian dari suatu folder

            Route::get('/info/{id}', [FolderController::class, 'info']); // Mendapatkan informasi lengkap isi folder tertentu, termasuk file dan subfolder

            Route::get('/storageSizeUsage', [FolderController::class, 'storageSizeUsage']); // Informasi total penyimpanan yang digunakan

            Route::post('/addTag', [FolderController::class, 'addTagToFolder']); // Tambahkan tag ke folder

            Route::post('/removeTag', [FolderController::class, 'removeTagFromFolder']); // hapus tag dari folder

            Route::post('/create', [FolderController::class, 'create']);  // Membuat folder baru

            Route::put('/update/{id}', [FolderController::class, 'update']); // Memperbarui folder

            Route::post('/delete', [FolderController::class, 'delete']); // Menghapus folder

            Route::put('/move', [FolderController::class, 'move']); // Memindahkan folder ke folder lain menggunakan metode parent_id yang baru

            Route::get('/path/{id}', [FolderController::class, 'getFullPath']); // Mendapatkan full path dari folder
        });

        Route::prefix('file')->group(function () {

            Route::get('/all', [FileController::class, 'getAllFilesAndTotalSize']); // Mendapatkan semua informasi file admin, tidak peduli dari folder apapun.

            Route::get('/{id}',  [FileController::class, 'info']); // Mendapatkan informasi file

            // Route::post('/create', [FileController::class, 'create']); // Membuat file baru

            Route::post('/upload', [FileController::class, 'upload']); // Mengunggah file

            Route::post('/download', [FileController::class, 'downloadFile']); // Mendownload File

            Route::post('/addTag', [FileController::class, 'addTagToFile']); // Tambahkan tag ke file

            Route::post('/removeTag', [FileController::class, 'removeTagFromFile']); // hapus tag dari file

            Route::put('/change_name/{id}', [FileController::class, 'updateFileName']); // Memperbarui nama file

            Route::post('/delete', [FileController::class, 'delete']); // Menghapus file

            Route::put('/move/{id}', [FileController::class, 'move']); // Memindahkan file ke folder lain atau ke root
        });

        Route::prefix('tag')->group(function () {
            Route::get('/index', [TagController::class, 'index']); // dapatkan semua list tag yang ada

            Route::get('/getTagsInfo/{tagId}', [TagController::class, 'getTagsInformation']); // informasi tag spesifik

            Route::post('/create', [TagController::class, 'store']); // Buat tag baru

            Route::put('/update/{tagId}', [TagController::class, 'update']); // Update tag yang ada sebelumnya

            Route::post('/delete', [TagController::class, 'destroy']); // Hapus tag yang ada sebelumnya dengan array request body
        });

        Route::prefix('instance')->group(function () {
            Route::get('/index', [InstanceController::class, 'index']); // dapatkan semua list instansi yang ada

            Route::get('/search', [InstanceController::class, 'getInstanceWithName']); // Mendapatkan daftar ID instansi berdasarkan nama (contoh: /instance?name=instansi)

            Route::post('/create', [InstanceController::class, 'store']); // Membuat instansi baru

            Route::put('/update/{id}', [InstanceController::class, 'update']); // Update instansi yang ada sebelumnya

            Route::post('/delete', [InstanceController::class, 'destroy']); // Hapus instansi yang ada sebelumnya dengan array request body
        });

        Route::prefix('faq')->group(function () {
            Route::get('/index', [FAQController::class, 'index']); // dapatkan semua list FAQ yang ada

            Route::get('/info/{id}', [FAQController::class, 'showSpesificFAQ']); // Mendapatkan informasi lengkap FAQ

            Route::post('/create', [FAQController::class, 'store']); // Buat FAQ baru

            Route::put('/update/{id}', [FAQController::class, 'update']); // Update FAQ yang ada sebelumnya

            Route::delete('/delete/{id}', [FAQController::class, 'destroy']); // Hapus FAQ yang ada sebelumnya
        });

        Route::prefix('permission')->group(function () {

            Route::prefix('folder')->group(function () {

                Route::get('/getAllPermission/{folderId}', [PermissionFolderController::class, 'getAllPermissionOnFolder']); // Get all user permission on folder

                Route::post('/getPermission', [PermissionFolderController::class, 'getPermission']); // Get spesific user permission on folder

                Route::post('/grantPermission', [PermissionFolderController::class, 'grantFolderPermission']); // Grant user permission on folder

                Route::put('/changePermission', [PermissionFolderController::class, 'changeFolderPermission']); // Change user permission on folder

                Route::post('/revokePermission', [PermissionFolderController::class, 'revokeFolderPermission']);
            });

            Route::prefix('file')->group(function () {

                Route::get('/getAllPermission/{folderId}', [PermissionFileController::class, 'getAllPermissionOnFile']);

                Route::post('/getPermission', [PermissionFileController::class, 'getPermission']);

                Route::post('/grantPermission', [PermissionFileController::class, 'grantFilePermission']);

                Route::put('/changePermission', [PermissionFileController::class, 'changeFilePermission']);

                Route::post('/revokePermission', [PermissionFileController::class, 'revokeFilePermission']);
            });
        });
    });
});
