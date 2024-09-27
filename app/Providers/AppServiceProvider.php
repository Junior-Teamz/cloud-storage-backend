<?php

namespace App\Providers;

use App\Services\CheckAdminService;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateURLService;
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
    }
}
