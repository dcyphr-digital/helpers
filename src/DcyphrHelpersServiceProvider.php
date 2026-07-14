<?php

namespace DcyphrDigital\Helpers;

use Illuminate\Support\ServiceProvider;

class DcyphrHelpersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/dcyphr-helpers.php',
            'dcyphr-helpers',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/dcyphr-helpers.php' => config_path('dcyphr-helpers.php'),
            ], 'dcyphr-helpers-config');
        }
    }
}
