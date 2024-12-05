<?php

namespace App\Providers;

use App\Services\CheckAdminService;
use App\Services\CheckFilePermissionService;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateURLService;
use App\Services\GetPathService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CheckAdminService::class, function () {
            return new CheckAdminService();
        });

        $this->app->singleton(CheckFolderPermissionService::class, function () {
            return new CheckFolderPermissionService();
        });

        $this->app->singleton(CheckFilePermissionService::class, function () {
            return new CheckFilePermissionService();
        });

        $this->app->singleton(GenerateURLService::class, function () {
            return new GenerateURLService();
        });

        $this->app->singleton(GetPathService::class, function () {
            return new GetPathService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // FOR FIXING ERROR NO HINT PATH FOR ERRORS TEMPLATE
        View::addNamespace('errors', resource_path('views/errors'));
    }
}
