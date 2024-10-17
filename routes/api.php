<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FAQController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileFavoriteController;
use App\Http\Controllers\FolderFavoriteController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\LegalBasisController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\NewsTagController;
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

Route::post('/refreshToken', [AuthController::class, 'refresh']); // refresh token

Route::post('/checkAccessTokenValid', [AuthController::class, 'checkAccessTokenValid']); //periksa apakah token jwt access token masih valid atau tidak

Route::post('/checkRefreshTokenValid', [AuthController::class, 'checkRefreshTokenValid']); //periksa apakah token jwt refresh token masih valid atau tidak

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');  // logout user

Route::get('/file/preview/{hashedId}', [FileController::class, 'serveFileByHashedId'])->name('file.url')->middleware(['auth:api']);

Route::get('/file/videoStream/{hashedId}', [FileController::class, 'serveFileVideoByHashedId'])->name('video.stream')->middleware(['auth:api']);

Route::get('/index', [UserController::class, 'index'])->middleware(['auth:api', 'remove_nanoid', 'hide_superadmin_flag']);

Route::prefix('/legal_basis')->group(function () {
    Route::get('/getAllLegalBasis', [LegalBasisController::class, 'getAll']);
});

Route::prefix('news')->group(function () {
    Route::get('/getAllNews', [NewsController::class, 'getAllNewsForPublic']); // Mendapatkan semua berita untuk publik

    Route::get('/detail/id/{id}', [NewsController::class, 'getNewsById']); // lihat detail berita

    Route::get('/detail/slug/{newsSlug}', [NewsController::class, 'getNewsBySlug']);

});

Route::middleware(['auth:api', 'protectRootFolder', 'protectRootTag', 'remove_nanoid', 'check_admin', 'hide_superadmin_flag'])->group(function () {

    Route::get('/searchUser', [SearchController::class, 'searchUser']); // Mencari user dengan name atau email

    Route::get('/searchFolderOrFile', [SearchController::class, 'searchFoldersAndFiles']); // Search Folder or File by name

    // Route::put('/update', [UserController::class, 'update']); // Update user

    // Route::delete('/delete', [UserController::class, 'delete']); // Menghapus user

    Route::get('/searchFolderOrFile', [SearchController::class, 'searchFoldersAndFiles']); // Search Folder or File by name

    Route::get('/getSharedFolderAndFile', [SharingController::class, 'getSharedFolderAndFile']); // Mendapatkan semua folder dan file yang dibagikan kepada user

    Route::get('/storageSizeUsage', [FolderController::class, 'storageSizeUsage']); // Informasi total penyimpanan yang digunakan

    Route::prefix('folder')->group(function () {
        Route::get('/', [FolderController::class, 'index']); // dapatkan list folder dan file yang ada pada user yang login saat ini pada folder rootnya.

        Route::get('/info/{id}', [FolderController::class, 'info']); // Mendapatkan informasi lengkap isi folder tertentu, termasuk file dan subfolder

        Route::get('/countAllFolder', [FolderController::class, 'countTotalFolderUser']); // mendapatkan count semua folder user yang sedang login saat ini.

        Route::get('/favorite', [FolderFavoriteController::class, 'getAllFavoriteFolders']); // Mendapatkan semua folder yang di favoritkan

        Route::get('/countFavorite', [FolderFavoriteController::class, 'countAllFavoriteFolders']); // Mendapatkan informasi total folder yang di favoritkan

        Route::post('/addToFavorite', [FolderFavoriteController::class, 'addNewFavorite']);

        Route::delete('/deleteFavorite/{id}', [FolderFavoriteController::class, 'deleteFavoriteFolder']);

        Route::get('/getUserSharedFolder/{id}', [SharingController::class, 'getListUserSharedFolder']); // Mendapatkan semua list user yang dibagian dari suatu folder

        Route::get('/generateShareLink/{fileId}', [SharingController::class, 'generateShareableFolderLink']);

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

        Route::get('/favorite', [FileFavoriteController::class, 'getAllFavoriteFile']); // Mendapatkan semua file yang di favoritkan

        Route::get('/countFavorite', [FileFavoriteController::class, 'countAllFavoriteFiles']); // Mendapatkan informasi total file yang di favoritkan

        Route::post('/addToFavorite', [FileFavoriteController::class, 'addNewFavorite']);

        Route::delete('/deleteFavorite/{id}', [FileFavoriteController::class, 'deleteFavoriteFile']);

        Route::get('/generateShareLink/{fileId}', [SharingController::class, 'generateShareableFileLink']);

        Route::get('/info/{id}', [FileController::class, 'info']); // Mendapatkan informasi file

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


// ROUTE KHUSUS UNTUK ADMIN
Route::prefix('admin')->middleware(['auth:api', 'validate_admin'])->group(function () {

    Route::get('/searchUser', [SearchController::class, 'searchUser']); // Mencari user dengan name atau email

    Route::get('/searchFolderOrFile', [SearchController::class, 'searchFoldersAndFiles']); // Search Folder or File by name

    Route::get('/getSharedFolderAndFile', [SharingController::class, 'getSharedFolderAndFile']); // Mendapatkan semua folder dan file yang dibagikan kepada user

    Route::prefix('statistic_superadmin')->group(function () {
        Route::get('/getStorageUsageTotal', [AdminController::class, 'storageUsage']); // Mendapatkan informasi total penyimpanan digunakan

        Route::get('/storageUsagePerInstance', [AdminController::class, 'storageUsagePerInstance']); // Mendapatkan informasi penggunaan penyimpanan per instansi

        Route::get('/getFolderCreated', [AdminController::class, 'allFolderCount']); // Mendapatkan informasi total folder dibuat

        Route::get('/getFiles', [AdminController::class, 'allFileCount']); // Mendapatkan informasi total file
    });

    Route::prefix('users')->group(function () {
        Route::get('/list', [AdminController::class, 'listUser']); // dapatkan list user (bisa juga menggunakan query seperti ini: /list?name=namauseryangingindicari)

        Route::get('/countTotalUser', [AdminController::class, 'countAllUser']); // dapatkan informasi total user dengan role yang terdaftar.

        Route::get('/info/{userId}', [AdminController::class, 'user_info']); // dapatkan informasi tentang user

        Route::post('/create_user', [AdminController::class, 'createUserFromAdmin']); // route untuk membuat user baru melalui admin.

        Route::put('/update_user/{userIdToBeUpdated}', [AdminController::class, 'updateUserFromAdmin']); // route untuk mengupdate user yang sudah ada melalui admin.

        Route::delete('/delete_user/{userIdToBeDeleted}', [AdminController::class, 'deleteUserFromAdmin']); // route untuk menghapus user yang sudah ada melalui admin. (DANGEROUS!)
    });

    Route::prefix('folder')->group(function () {
        Route::get('/', [FolderController::class, 'index']); // dapatkan list folder dan file yang ada pada user yang login saat ini pada folder rootnya.

        Route::get('/countAllFolder', [FolderController::class, 'countTotalFolderUser']); // mendapatkan count semua folder user yang sedang login saat ini.

        Route::get('/storageSizeUsage', [FolderController::class, 'storageSizeUsage']); // Informasi total penyimpanan yang digunakan

        Route::get('/getUserSharedFolder/{id}', [SharingController::class, 'getListUserSharedFolder']); // Mendapatkan semua list user yang dibagian dari suatu folder

        Route::get('/info/{id}', [FolderController::class, 'info']); // Mendapatkan informasi lengkap isi folder tertentu, termasuk file dan subfolder

        Route::get('/favorite', [FolderFavoriteController::class, 'getAllFavoriteFolders']); // Mendapatkan semua folder yang di favoritkan

        Route::post('/addToFavorite', [FolderFavoriteController::class, 'addNewFavorite']);

        Route::delete('/deleteFavorite/{id}', [FolderFavoriteController::class, 'deleteFavoriteFolder']);

        Route::post('/addTag', [FolderController::class, 'addTagToFolder']); // Tambahkan tag ke folder

        Route::post('/removeTag', [FolderController::class, 'removeTagFromFolder']); // hapus tag dari folder

        Route::post('/create', [FolderController::class, 'create']);  // Membuat folder baru

        Route::put('/update/{id}', [FolderController::class, 'update']); // Memperbarui folder

        Route::post('/delete', [FolderController::class, 'delete']); // Menghapus folder

        Route::put('/move', [FolderController::class, 'move']); // Memindahkan folder ke folder lain menggunakan metode parent_id yang baru

        Route::get('/path/{id}', [FolderController::class, 'getFullPath']); // Mendapatkan full path dari folder
    });

    Route::prefix('file')->group(function () {

        Route::get('/all', [FileController::class, 'getAllFilesAndTotalSize']); // Mendapatkan semua file dan total ukurannya.

        Route::get('/info/{id}',  [FileController::class, 'info']); // Mendapatkan informasi file

        Route::get('/favorite', [FileFavoriteController::class, 'getAllFavoriteFile']); // Mendapatkan semua file yang di favoritkan

        Route::post('/addToFavorite', [FileFavoriteController::class, 'addNewFavorite']);

        Route::delete('/deleteFavorite/{id}', [FileFavoriteController::class, 'deleteFavoriteFile']);

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

        Route::get('/getTagUsageStatistic', [TagController::class, 'getTagUsageStatistics']); // Mendapatkan statistik tag

        Route::get('/countAll', [TagController::class, 'countAllTags']); // Mendapatkan total tag

        Route::post('/create', [TagController::class, 'store']); // Buat tag baru

        Route::get('/importExampleDownload', [TagController::class, 'exampleImportDownload']); // untuk mendownload contoh file import tag.

        Route::post('/import', [TagController::class, 'import']); // Mengimpor tag

        Route::put('/update/{tagId}', [TagController::class, 'update']); // Update tag yang ada sebelumnya

        Route::post('/delete', [TagController::class, 'destroy']); // Hapus tag yang ada sebelumnya dengan array request body
    });

    Route::prefix('instance')->group(function () {
        Route::get('/index', [InstanceController::class, 'index']); // dapatkan semua list instansi yang ada

        Route::get('/search', [InstanceController::class, 'getInstanceWithName']); // Mendapatkan daftar ID instansi berdasarkan nama (contoh: /instance?name=instansi)

        Route::get('/getInstanceUsageStatistic', [InstanceController::class, 'getInstanceUsageStatistics']); // Mendapatkan statistik instansi

        Route::get('/countAll', [InstanceController::class, 'countAllInstance']); // Mendapatkan total instansi

        Route::post('/create', [InstanceController::class, 'store']); // Membuat instansi baru

        Route::get('/importExampleDownload', [InstanceController::class, 'exampleImportDownload']); // untuk mendownload contoh file import instansi.

        Route::post('/import', [InstanceController::class, 'import']); // Mengimpor instansi

        Route::put('/update/{id}', [InstanceController::class, 'update']); // Update instansi yang ada sebelumnya

        Route::delete('/delete/{instanceId}', [InstanceController::class, 'destroy']); // Hapus instansi
    });

    Route::prefix('faq')->group(function () {
        Route::get('/index', [FAQController::class, 'index']); // dapatkan semua list FAQ yang ada

        Route::get('/info/{id}', [FAQController::class, 'showSpesificFAQ']); // Mendapatkan informasi lengkap FAQ

        Route::post('/create', [FAQController::class, 'store']); // Buat FAQ baru

        Route::put('/update/{id}', [FAQController::class, 'update']); // Update FAQ yang ada sebelumnya

        Route::delete('/delete/{id}', [FAQController::class, 'destroy']); // Hapus FAQ yang ada sebelumnya
    });

    Route::prefix('news')->group(function () {
        Route::get('/getAllNews', [NewsController::class, 'getAllNews']);

        Route::get('/getNewsDetailFromId/{newsId}', [NewsController::class, 'getNewsDetailForAdmin']);

        Route::post('/create', [NewsController::class, 'createNews']); // Membuat berita baru

        Route::put('/update/{newsId}', [NewsController::class, 'updateNews']); // Update berita yang ada sebelumnya

        Route::put('/changeStatus/{newsId}', [NewsController::class, 'changeStatus']);

        Route::delete('/delete/{newsId}', [NewsController::class, 'deleteNews']); // Hapus berita
    });

    Route::prefix('news_tag')->group(function () {
        Route::get('/index', [NewsTagController::class, 'index']); // Mendapatkan semua tag news (dapat query juga)

        Route::post('/create', [NewsTagController::class, 'store']); // Buat news tag baru

        Route::put('/update/{newsTagId}', [NewsTagController::class, 'update']); // Update news tag yang sudah ada sebelumnya.

        Route::post('/delete', [NewsTagController::class, 'destroy']); // Hapus tag (menggunakan array)
    });

    Route::prefix('legal_basis')->group(function () {

        // Catatan: untuk mendapatkan semua dasar hukum, gunakan route publik /api/legal_basis/all .

        Route::get('/info/{id}', [LegalBasisController::class, 'getSpesificLegalBasis']); // Mendapatkan dasar hukum berdasarkan id

        Route::post('/save', [LegalBasisController::class, 'save']);

        Route::put('/update/{id}', [LegalBasisController::class, 'update']);

        Route::delete('/delete/{id}', [LegalBasisController::class, 'delete']);
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
