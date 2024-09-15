<?php

namespace App\Providers;

use App\Services\CheckAdminService;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateImageURLService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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

        $this->app->singleton(GenerateImageURLService::class, function ($app) {
            return new GenerateImageURLService();
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
