<?php

namespace App\Providers;

use App\Services\CheckAdminService;
use App\Services\CheckFilePermissionService;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateURLService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Illuminate\Support\Facades\Blade;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CheckAdminService::class, function ($app) {
            return new CheckAdminService();
        });

        $this->app->bind(CheckFolderPermissionService::class, function ($app) {
            return new CheckFolderPermissionService();
        });

        $this->app->bind(CheckFilePermissionService::class, function ($app) {
            return new CheckFilePermissionService();
        });

        $this->app->singleton(GenerateURLService::class, function ($app) {
            return new GenerateURLService();
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // FOR FIXING ERROR NO HINT PATH FOR ERRORS TEMPLATE
        View::addNamespace('errors', resource_path('views/errors'));

        LogViewer::auth(function () {
            return Auth::guard('web')->check();
        });
    }
}
