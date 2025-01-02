<?php

// Superadmin code import from namespace 'Superadmin'
use App\Http\Controllers\Superadmin\Users\UserController as UserSuperadminController;
use App\Http\Controllers\Superadmin\AppStatisticController;
use App\Http\Controllers\Superadmin\Instance\InstanceController as InstanceSuperadminController;
use App\Http\Controllers\Superadmin\Instance\Section\InstanceSectionController as InstanceSectionSuperadminController;
use App\Http\Controllers\Superadmin\Instance\Statistic\InstanceStatisticController as InstanceStatisticSuperadminController;


// Admin code import from namespace 'Admin'
use App\Http\Controllers\Admin\Users\UserController as UserAdminController;
use App\Http\Controllers\Admin\Instances\InstanceController as InstanceAdminController;
use App\Http\Controllers\Admin\Instances\Sections\InstanceSectionController;
use App\Http\Controllers\Admin\Instances\Statistics\InstanceStatisticController;


// Public/Other code import
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FAQs\FAQController;
use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\Files\FileFavoriteController;
use App\Http\Controllers\Folders\FolderController;
use App\Http\Controllers\Folders\FolderFavoriteController;
use App\Http\Controllers\Instances\InstanceController;
use App\Http\Controllers\LegalBasis\LegalBasisController;
use App\Http\Controllers\News\NewsController;
use App\Http\Controllers\Permissions\PermissionFileController;
use App\Http\Controllers\Permissions\PermissionFolderController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\SharingController;
use App\Http\Controllers\Tags\TagController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\VerificationAndForgetPasswordController;
use App\Http\Controllers\Webhook\WebhookController;
use Illuminate\Support\Facades\Route;


// Github Webhook Route
Route::post('/github-webhook', [WebhookController::class, 'handle']);

 // Public Auth Routes
 Route::post('/login', [AuthController::class, 'login']);
 Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
 Route::post('/refresh_token', [AuthController::class, 'refresh']);
 Route::post('/check_access_token_valid', [AuthController::class, 'checkAccessTokenValid']);
 Route::post('/check_refresh_token_valid', [AuthController::class, 'checkRefreshTokenValid']);
 Route::post('/send_link_reset_password', [VerificationAndForgetPasswordController::class, 'sendPasswordResetLink']);
 Route::post('/reset_password', [VerificationAndForgetPasswordController::class, 'resetPassword']);

Route::prefix('public')->group(function () {    
    // Public Legal Basis Routes
    Route::prefix('/legal_basis')->group(function () {
        Route::get('/all', [LegalBasisController::class, 'getAll']);
        Route::get('/detail/{id}', [LegalBasisController::class, 'getSpesificLegalBasis']);
    });

    // Public News Routes
    Route::prefix('news')->group(function () {
        Route::get('/all', [NewsController::class, 'getAllNewsForPublic']);
        Route::get('/detail/by_id/{id}', [NewsController::class, 'getNewsById']);
        Route::get('/detail/by_slug/{newsSlug}', [NewsController::class, 'getNewsBySlug']);
    });
});

// Route for file access
Route::prefix('media')->group(function () {
    Route::get('/preview/{id}', [FileController::class, 'serveFileById'])->name('file.url');
    Route::get('/video_stream/{id}', [FileController::class, 'serveFileVideoById'])->name('video.stream');
});

// Authenticated User Routes (All role can access this routes)
Route::middleware(['auth:api', 'protectRootFolder', 'protectRootTag'])->group(function () {
    // Search Routes
    Route::prefix('search')->group(function () {
        Route::get('/user', [SearchController::class, 'searchUser']);
        Route::get('/folder_or_file', [SearchController::class, 'searchFoldersAndFiles']);
    });

    // Sharing Route
    Route::get('/get_shared_folder_and_file', [SharingController::class, 'getSharedFolderAndFile']);

    // Storage Usage Route
    Route::get('/storage_usage', [FolderController::class, 'storageSizeUsage']);

    // User Routes
    Route::prefix('user')->group(function () {
        Route::get('/user_info/{id}', [UserController::class, 'userInfo']);
        Route::get('/index', [UserController::class, 'index']);
        Route::put('/update', [UserController::class, 'update']);
        Route::put('/update_password', [UserController::class, 'updatePassword']);
        Route::delete('/delete', [UserController::class, 'delete']);
    });

    // Folder Routes
    Route::prefix('folders')->group(function () {
        Route::get('/all', [FolderController::class, 'index']);
        Route::get('/detail/{id}', [FolderController::class, 'info']);
        Route::get('/count_all', [FolderController::class, 'countTotalFolderUser']);
        Route::get('/generate_share_link/{folderId}', [SharingController::class, 'generateShareableFolderLink']);
        Route::post('/create', [FolderController::class, 'create']);
        Route::put('/update/{id}', [FolderController::class, 'update']);
        Route::post('/delete', [FolderController::class, 'delete']);
        Route::put('/move', [FolderController::class, 'move']);
        Route::get('/path/{id}', [FolderController::class, 'getFullPath']);

        // Subgroup for tag in folder
        Route::prefix('tag')->group(function () {
            Route::post('/add', [FolderController::class, 'addTagToFolder']);
            Route::post('/remove', [FolderController::class, 'removeTagFromFolder']);
        });

        // Subgroup for favorite folders
        Route::prefix('favorite')->group(function () {
            Route::get('/all', [FolderFavoriteController::class, 'getAllFavoriteItems']);
            Route::get('/count_all', [FolderFavoriteController::class, 'countAllFavoriteFolders']);
            Route::post('/add', [FolderFavoriteController::class, 'addNewFavorite']);
            Route::delete('/delete/{id}', [FolderFavoriteController::class, 'deleteFavoriteFolder']);
        });
    });

    // File Routes
    Route::prefix('files')->group(function () {
        Route::get('/all', [FileController::class, 'getAllFilesAndTotalSize']);
        Route::get('/generate_share_link/{fileId}', [SharingController::class, 'generateShareableFileLink']);
        Route::get('/detail/{id}', [FileController::class, 'info']);
        Route::post('/upload', [FileController::class, 'upload']);
        Route::post('/download', [FileController::class, 'downloadFile']);
        Route::put('/change_name/{id}', [FileController::class, 'updateFileName']);
        Route::post('/delete', [FileController::class, 'delete']);
        Route::put('/move/{id}', [FileController::class, 'move']);

        // Subgroup for tag in file
        Route::prefix('tag')->group(function () {
            Route::post('/add', [FileController::class, 'addTagToFile']);
            Route::post('/remove', [FileController::class, 'removeTagFromFile']);
        });

        // Subgroup for favorite files
        Route::prefix('favorite')->group(function () {
            Route::get('/all', [FileFavoriteController::class, 'getAllFavoriteFile']);
            Route::get('/count_all', [FileFavoriteController::class, 'countAllFavoriteFiles']);
            Route::post('/add', [FileFavoriteController::class, 'addNewFavorite']);
            Route::delete('/delete/{id}', [FileFavoriteController::class, 'deleteFavoriteFile']);
        });
    });

    // Instance Routes
    Route::prefix('instances')->group(function () {
        Route::get('/all', [InstanceController::class, 'getAllInstanceData']);
        Route::get('/search', [InstanceController::class, 'getInstanceWithName']);
        Route::get('/detail/{id}', [InstanceController::class, 'getInstanceDetailFromId']);
    });

    // Tag Routes
    Route::prefix('tags')->group(function () {
        Route::get('/index', [TagController::class, 'index']);
        Route::get('/detail/{tagId}', [TagController::class, 'getTagsInformation']);
    });

    // FAQ Routes
    Route::prefix('faqs')->group(function () {
        Route::get('/index', [FAQController::class, 'index']);
        Route::get('/detail/{id}', [FAQController::class, 'showSpesificFAQ']);
    });

    // Permission Routes
    Route::prefix('permissions')->group(function () {
        Route::prefix('folders')->group(function () {
            Route::get('/get_all_permission/{folderId}', [PermissionFolderController::class, 'getAllPermissionOnFolder']);
            Route::post('/get_spesific_permission', [PermissionFolderController::class, 'getPermission']);
            Route::post('/grant_permission', [PermissionFolderController::class, 'grantFolderPermission']);
            Route::put('/change_permission', [PermissionFolderController::class, 'changeFolderPermission']);
            Route::post('/revoke_permission', [PermissionFolderController::class, 'revokeFolderPermission']);
        });

        Route::prefix('files')->group(function () {
            Route::get('/get_all_permission/{fileId}', [PermissionFileController::class, 'getAllPermissionOnFile']);
            Route::post('/get_spesific_permission', [PermissionFileController::class, 'getPermission']);
            Route::post('/grant_permission', [PermissionFileController::class, 'grantFilePermission']);
            Route::put('/change_permission', [PermissionFileController::class, 'changeFilePermission']);
            Route::post('/revoke_permission', [PermissionFileController::class, 'revokeFilePermission']);
        });
    });
});

// Admin Routes
Route::prefix('admin')->middleware(['auth:api', 'validate_admin'])->group(function () {
    Route::prefix('statistics')->group(function () {
        Route::prefix('instances')->group(function () {
            Route::get('/usage', [InstanceStatisticController::class, 'getCurrentInstanceUsageStatistics']);
        });

        Route::prefix('users')->group(function () {
            Route::get('/count_all', [UserAdminController::class, 'countAllUserSameInstance']);
        });
    });

    Route::prefix('users')->group(function () {
        Route::get('/list', [UserAdminController::class, 'listUser']);
        Route::get('/detail/{id}', [UserAdminController::class, 'user_info']);
        Route::post('/create', [UserAdminController::class, 'createUserFromAdmin']);
        Route::put('/update/{id}', [UserAdminController::class, 'updateUserFromAdmin']);
        Route::put('/update_user_password/{id}', [UserAdminController::class, 'updateUserPassword']);
        Route::delete('/delete/{id}', [UserAdminController::class, 'deleteUserFromAdmin']);
    });

    Route::prefix('instances')->group(function () {
        Route::get('/index', [InstanceAdminController::class, 'index']);
        Route::put('/update', [InstanceAdminController::class, 'updateInstance']);
        Route::delete('/delete', [InstanceAdminController::class, 'deleteInstance']);

        // Subgroup for instance sections in admin routes
        Route::prefix('sections')->group(function () {
            Route::get('/all', [InstanceSectionController::class, 'getAllSections']);
            Route::get('/detail/{id}', [InstanceSectionController::class, 'getInstanceSectionById']);
            Route::post('/create', [InstanceSectionController::class, 'createNewInstanceSection']);
            Route::put('/update/{id}', [InstanceSectionController::class, 'updateInstanceSection']);
            Route::delete('/delete/{id}', [InstanceSectionController::class, 'deleteInstanceSection']);
        });
    });
});

// Superadmin Routes
Route::prefix('superadmin')->middleware(['auth:api', 'validate_superadmin'])->group(function () {
    Route::prefix('statistics')->group(function () {
        Route::get('/storage_usage', [AppStatisticController::class, 'storageUsage']);
        Route::get('/all_folder_count', [AppStatisticController::class, 'allFolderCount']);
        Route::get('/all_file_count', [AppStatisticController::class, 'allFileCount']);

        Route::prefix('tags')->group(function () {
            Route::get('/all_tag_usage', [AppStatisticController::class, 'getTagUsageStatistics']);
            Route::get('/count_all_tags', [TagController::class, 'countAllTags']);
        });

        // Subgroup for instance statistics in superadmin routes
        Route::prefix('instances')->group(function () {
            Route::get('/usage', [InstanceStatisticSuperadminController::class, 'getInstanceUsageStatistics']);
            Route::get('/count_all_instance', [InstanceStatisticSuperadminController::class, 'countAllInstance']);
            Route::get('/storage_usage_per_instance', [AppStatisticController::class, 'storageUsagePerInstance']);
            Route::get('/tags_used_by_instance', [AppStatisticController::class, 'tagsUsedByInstance']);
        });
    });

    Route::prefix('users')->group(function () {
        Route::get('/count_total', [UserSuperadminController::class, 'countAllUser']);
        Route::get('/list', [UserSuperadminController::class, 'listUser']);
        Route::get('/admin_permissions', [UserSuperadminController::class, 'getAdminPermissions']);
        Route::get('/detail/{id}', [UserSuperadminController::class, 'user_info']);
        Route::post('/create', [UserSuperadminController::class, 'createUserFromAdmin']);
        Route::prefix('update')->group(function () {
            Route::put('/role_user/{id}', [UserSuperadminController::class, 'updateUserRoleUser']);
            Route::put('/role_admin/{id}', [UserSuperadminController::class, 'updateUserRoleAdmin']);
            Route::put('/password/{id}', [UserSuperadminController::class, 'updateUserPassword']);
        });
        Route::delete('/delete/{id}', [UserSuperadminController::class, 'deleteUserFromAdmin']);
    });

    Route::prefix('instances')->group(function () {
        Route::get('/index', [InstanceSuperadminController::class, 'index']);
        Route::get('/search', [InstanceSuperadminController::class, 'getInstanceWithName']);
        Route::post('/create', [InstanceSuperadminController::class, 'store']);
        Route::put('/update/{id}', [InstanceSuperadminController::class, 'update']);
        Route::delete('/delete/{id}', [InstanceSuperadminController::class, 'destroy']);
        Route::get('/example_import_download', [InstanceSuperadminController::class, 'exampleImportDownload']);
        Route::post('/import', [InstanceSuperadminController::class, 'import']);

        // Subgroup for instance sections in superadmin routes
        Route::prefix('sections')->group(function () {
            Route::get('/all', [InstanceSectionSuperadminController::class, 'getAllSections']);
            Route::get('/detail/{instanceSectionId}', [InstanceSectionSuperadminController::class, 'getInstanceSectionDetail']);
            Route::post('/create', [InstanceSectionSuperadminController::class, 'createNewInstanceSection']);
            Route::put('/update/{instanceSectionId}', [InstanceSectionSuperadminController::class, 'updateInstanceSection']);
            Route::delete('/delete/{instanceSectionId}', [InstanceSectionSuperadminController::class, 'deleteInstanceSection']);
        });
    });

    Route::prefix('legal_basis')->group(function () {
        Route::post('/save', [LegalBasisController::class, 'save']);
        Route::put('/update/{id}', [LegalBasisController::class, 'update']);
        Route::delete('/delete/{id}', [LegalBasisController::class, 'delete']);
    });

    Route::prefix('faqs')->group(function () {
        Route::post('/create', [FAQController::class, 'store']);
        Route::put('/update/{id}', [FAQController::class, 'update']);
        Route::delete('/delete/{id}', [FAQController::class, 'destroy']);
    });
});



// Dibawah ini adalah route untuk tag yang dapat diakses oleh superadmin dan admin (menghindari duplikasi kode route).
$routesForAdminAndSuperadmin = function () {
    Route::prefix('tags')->group(function () {
        Route::get('/index', [TagController::class, 'index']);
        Route::get('/detail/{tagId}', [TagController::class, 'getTagsInformation']);
        Route::post('/create', [TagController::class, 'store']);
        Route::put('/update/{id}', [TagController::class, 'update']);
        Route::post('/delete', [TagController::class, 'destroy']);
        Route::get('/example_import_download', [TagController::class, 'exampleImportDownload']);
        Route::post('/import', [TagController::class, 'import']);
    });

    Route::prefix('news')->group(function () {
        Route::get('/all', [NewsController::class, 'getAllNews']);
        Route::get('/detail/{id}', [NewsController::class, 'getNewsDetailForAdmin']);
        Route::post('/create', [NewsController::class, 'createNews']);
        Route::put('/update/{id}', [NewsController::class, 'updateNews']);
        Route::delete('/delete/{id}', [NewsController::class, 'deleteNews']);
        Route::put('/change_status/{id}', [NewsController::class, 'changeStatus']);
    });
};
// Tag Routes for Admin and Superadmin from $routesForAdminAndSuperadmin
Route::prefix('admin')->middleware(['auth:api', 'validate_admin'])->group($routesForAdminAndSuperadmin);
Route::prefix('superadmin')->middleware(['auth:api', 'validate_superadmin'])->group($routesForAdminAndSuperadmin);
