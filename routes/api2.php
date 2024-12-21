<?php

// Superadmin code import
use App\Http\Controllers\Superadmin\Users\UserController as UserSuperadminController;
use App\Http\Controllers\Superadmin\AppStatisticController;
use App\Http\Controllers\Superadmin\Instance\InstanceController as InstanceSuperadminController;
use App\Http\Controllers\Superadmin\Instance\Section\InstanceSectionController as InstanceSectionSuperadminController;
use App\Http\Controllers\Superadmin\Instance\Statistic\InstanceStatisticController as InstanceStatisticSuperadminController;


// Admin code import
use App\Http\Controllers\Admin\Users\UserController;
use App\Http\Controllers\Admin\Instances\InstanceController as InstanceAdminController;
use App\Http\Controllers\Admin\Instances\Sections\InstanceSectionController;
use App\Http\Controllers\Admin\Instances\Statistics\InstanceStatisticController;


// Public code import
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FAQs\FAQController;
use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\Files\FileFavoriteController;
use App\Http\Controllers\Folders\FolderController;
use App\Http\Controllers\Folders\FolderFavoriteController;
use App\Http\Controllers\Instance\InstanceController;
use App\Http\Controllers\LegalBasis\LegalBasisController;
use App\Http\Controllers\News\NewsController;
use App\Http\Controllers\Permissions\PermissionFileController;
use App\Http\Controllers\Permissions\PermissionFolderController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\SharingController;
use App\Http\Controllers\Tags\TagController;
use App\Http\Controllers\VerificationAndForgetPasswordController;
use App\Http\Controllers\Webhook\WebhookController;
use Illuminate\Support\Facades\Route;

// Public Routes
Route::post('/storeImageTesting', [NewsController::class, 'storeImage']);
Route::post('/github-webhook', [WebhookController::class, 'handle']);
Route::post('/sendLinkResetPassword', [VerificationAndForgetPasswordController::class, 'sendPasswordResetLink']);
Route::post('/resetPassword', [VerificationAndForgetPasswordController::class, 'resetPassword']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refreshToken', [AuthController::class, 'refresh']);
Route::post('/checkAccessTokenValid', [AuthController::class, 'checkAccessTokenValid']);
Route::post('/checkRefreshTokenValid', [AuthController::class, 'checkRefreshTokenValid']);
Route::get('/file/preview/{id}', [FileController::class, 'serveFileById'])->name('file.url');
Route::get('/file/videoStream/{id}', [FileController::class, 'serveFileVideoById'])->name('video.stream');

// Public Legal Basis Routes
Route::prefix('/legal_basis')->group(function () {
    Route::get('/getAllLegalBasis', [LegalBasisController::class, 'getAll']);
});

// Public News Routes
Route::prefix('news')->group(function () {
    Route::get('/getAllNews', [NewsController::class, 'getAllNewsForPublic']);
    Route::get('/detail/id/{id}', [NewsController::class, 'getNewsById']);
    Route::get('/detail/slug/{newsSlug}', [NewsController::class, 'getNewsBySlug']);
});

// Authenticated User Routes
Route::middleware(['auth:api', 'protectRootFolder', 'protectRootTag'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/index', [UserController::class, 'index']);

    // Search Routes
    Route::get('/searchUser', [SearchController::class, 'searchUser']);
    Route::get('/searchFolderOrFile', [SearchController::class, 'searchFoldersAndFiles']);

    // Sharing Routes
    Route::get('/getSharedFolderAndFile', [SharingController::class, 'getSharedFolderAndFile']);

    // User Routes
    Route::prefix('user')->group(function () {
        Route::get('/userInfo/{id}', [UserController::class, 'userInfo']);
        Route::get('/index', [UserController::class, 'index']);
        Route::put('/update', [UserController::class, 'update']);
        Route::put('/updatePassword', [UserController::class, 'updatePassword']);
        Route::delete('/delete', [UserController::class, 'delete']);
    });

    // Folder Routes
    Route::prefix('folder')->group(function () {
        Route::get('/', [FolderController::class, 'index']);
        Route::get('/info/{id}', [FolderController::class, 'info']);
        Route::get('/countAllFolder', [FolderController::class, 'countTotalFolderUser']);
        Route::get('/favorite', [FolderFavoriteController::class, 'getAllFavoriteItems']);
        Route::get('/countFavorite', [FolderFavoriteController::class, 'countAllFavoriteFolders']);
        Route::post('/addToFavorite', [FolderFavoriteController::class, 'addNewFavorite']);
        Route::delete('/deleteFavorite/{id}', [FolderFavoriteController::class, 'deleteFavoriteFolder']);
        Route::get('/getUserSharedFolder/{id}', [SharingController::class, 'getListUserSharedFolder']);
        Route::get('/generateShareLink/{folderId}', [SharingController::class, 'generateShareableFolderLink']);
        Route::post('/create', [FolderController::class, 'create']);
        Route::put('/update/{id}', [FolderController::class, 'update']);
        Route::post('/delete', [FolderController::class, 'delete']);
        Route::post('/addTag', [FolderController::class, 'addTagToFolder']);
        Route::post('/removeTag', [FolderController::class, 'removeTagFromFolder']);
        Route::put('/move', [FolderController::class, 'move']);
        Route::get('/path/{id}', [FolderController::class, 'getFullPath']);
    });

    // File Routes
    Route::prefix('file')->group(function () {
        Route::get('/all', [FileController::class, 'getAllFilesAndTotalSize']);
        Route::get('/favorite', [FileFavoriteController::class, 'getAllFavoriteFile']);
        Route::get('/countFavorite', [FileFavoriteController::class, 'countAllFavoriteFiles']);
        Route::post('/addToFavorite', [FileFavoriteController::class, 'addNewFavorite']);
        Route::delete('/deleteFavorite/{id}', [FileFavoriteController::class, 'deleteFavoriteFile']);
        Route::get('/generateShareLink/{fileId}', [SharingController::class, 'generateShareableFileLink']);
        Route::get('/info/{id}', [FileController::class, 'info']);
        Route::post('/upload', [FileController::class, 'upload']);
        Route::post('/download', [FileController::class, 'downloadFile']);
        Route::post('/addTag', [FileController::class, 'addTagToFile']);
        Route::post('/removeTag', [FileController::class, 'removeTagFromFile']);
        Route::put('/change_name/{id}', [FileController::class, 'updateFileName']);
        Route::post('/delete', [FileController::class, 'delete']);
        Route::put('/move/{id}', [FileController::class, 'move']);
    });

    // Tag Routes
    Route::prefix('tag')->group(function () {
        Route::get('/index', [TagController::class, 'index']);
        Route::get('/getTagsInfo/{tagId}', [TagController::class, 'getTagsInformation']);
    });

    // Permission Routes
    Route::prefix('permission')->group(function () {
        Route::prefix('folder')->group(function () {
            Route::get('/getAllPermission/{folderId}', [PermissionFolderController::class, 'getAllPermissionOnFolder']);
            Route::post('/getPermission', [PermissionFolderController::class, 'getPermission']);
            Route::post('/grantPermission', [PermissionFolderController::class, 'grantFolderPermission']);
            Route::put('/changePermission', [PermissionFolderController::class, 'changeFolderPermission']);
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

// Admin Routes
Route::prefix('admin')->middleware(['auth:api', 'validate_admin'])->group(function () {

    Route::prefix('statistic')->group(function () {
        Route::prefix('instance')->group(function () {
            Route::get('/usage', [InstanceStatisticController::class, 'getCurrentInstanceUsageStatistics']);
        });
    });

    Route::prefix('user')->group(function () {
        Route::get('/countAllUserSameInstance', [UserController::class, 'countAllUserSameInstance']);
        Route::get('/listUser', [UserController::class, 'listUser']);
        Route::get('/userInfo/{id}', [UserController::class, 'user_info']);
        Route::post('/createUser', [UserController::class, 'createUserFromAdmin']);
        Route::put('/updateUser/{id}', [UserController::class, 'updateUserFromAdmin']);
        Route::put('/updateUserPassword/{id}', [UserController::class, 'updateUserPassword']);
        Route::delete('/deleteUser/{id}', [UserController::class, 'deleteUserFromAdmin']);
    });

    Route::prefix('instance')->group(function () {
        Route::get('/index', [InstanceAdminController::class, 'index']);
        Route::put('/update', [InstanceAdminController::class, 'updateInstance']);
        Route::delete('/delete', [InstanceAdminController::class, 'deleteInstance']);
        Route::prefix('section')->group(function () {
            Route::get('/all', [InstanceSectionController::class, 'getAllSections']);
            Route::get('/{id}', [InstanceSectionController::class, 'getInstanceSectionById']);
            Route::post('/create', [InstanceSectionController::class, 'createNewInstanceSection']);
            Route::put('/update/{id}', [InstanceSectionController::class, 'updateInstanceSection']);
            Route::delete('/delete/{id}', [InstanceSectionController::class, 'deleteInstanceSection']);
        });
    });
});

// Superadmin Routes
Route::prefix('superadmin')->middleware(['auth:api', 'validate_superadmin'])->group(function () {
    Route::prefix('statistics')->group(function () {
        Route::get('/storageUsage', [AppStatisticController::class, 'storageUsage']);
        Route::get('/allFolderCount', [AppStatisticController::class, 'allFolderCount']);
        Route::get('/allFileCount', [AppStatisticController::class, 'allFileCount']);
        Route::get('/allTagUsage', [AppStatisticController::class, 'getTagUsageStatistics']);

        Route::prefix('instance')->group(function () {
            Route::get('/usage', [InstanceStatisticSuperadminController::class, 'getInstanceUsageStatistics']);
            Route::get('/countAllInstance', [InstanceStatisticSuperadminController::class, 'countAllInstance']);
            Route::get('/storageUsagePerInstance', [AppStatisticController::class, 'storageUsagePerInstance']);
            Route::get('/tagsUsedByInstance', [AppStatisticController::class, 'tagsUsedByInstance']);
        });
    });

    Route::prefix('user')->group(function () {
        Route::get('/countAllUser', [UserSuperadminController::class, 'countAllUser']);
        Route::get('/listUser', [UserSuperadminController::class, 'listUser']);
        Route::get('/userInfo/{id}', [UserSuperadminController::class, 'user_info']);
        Route::post('/createUser', [UserSuperadminController::class, 'createUserFromAdmin']);
        Route::put('/updateUser/{id}', [UserSuperadminController::class, 'updateUserFromAdmin']);
        Route::put('/updateUserPassword/{id}', [UserSuperadminController::class, 'updateUserPassword']);
        Route::delete('/deleteUser/{id}', [UserSuperadminController::class, 'deleteUserFromAdmin']);
    });

    Route::prefix('instance')->group(function () {
        Route::get('/index', [InstanceSuperadminController::class, 'index']);
        Route::get('/search', [InstanceSuperadminController::class, 'getInstanceWithName']);
        Route::post('/create', [InstanceSuperadminController::class, 'store']);
        Route::put('/update/{id}', [InstanceSuperadminController::class, 'update']);
        Route::delete('/delete/{id}', [InstanceSuperadminController::class, 'destroy']);
        Route::get('/exampleImportDownload', [InstanceSuperadminController::class, 'exampleImportDownload']);
        Route::post('/import', [InstanceSuperadminController::class, 'import']);

        Route::prefix('section')->group(function () {
            Route::get('/all/{instanceId}', [InstanceSectionSuperadminController::class, 'getAllSections']);
            Route::get('/{instanceSectionId}', [InstanceSectionSuperadminController::class, 'getInstanceSection']);
            Route::post('/create', [InstanceSectionSuperadminController::class, 'createNewInstanceSection']);
            Route::put('/update', [InstanceSectionSuperadminController::class, 'updateInstanceSection']);
            Route::delete('/delete/{instanceId}/{instanceSectionId}', [InstanceSectionSuperadminController::class, 'deleteInstanceSection']);
        });
    });
});



// Dibawah ini adalah route untuk tag yang dapat diakses oleh superadmin dan admin (menghindari duplikasi kode route).
$tagRoutes = function () {
    Route::prefix('tag')->group(function () {
        Route::get('/index', [TagController::class, 'index']);
        Route::get('/getTagsInfo/{tagId}', [TagController::class, 'getTagsInformation']);
        Route::post('/create', [TagController::class, 'store']);
        Route::put('/update/{id}', [TagController::class, 'update']);
        Route::delete('/delete', [TagController::class, 'destroy']);
        Route::get('/countAllTags', [TagController::class, 'countAllTags']);
        Route::post('/import', [TagController::class, 'import']);
        Route::get('/exampleImportDownload', [TagController::class, 'exampleImportDownload']);
    });
};
// Tag Routes for Admin and Superadmin from $tagRoutes
Route::prefix('admin')->middleware(['auth:api', 'validate_admin'])->group($tagRoutes);
Route::prefix('superadmin')->middleware(['auth:api', 'validate_superadmin'])->group($tagRoutes);