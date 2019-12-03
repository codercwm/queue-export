<?php

namespace Codercwm\QueueExport;

use Illuminate\Support\ServiceProvider;

class QueueExportServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        /*$this->publishes([
            __DIR__.'/../config/queue_export.php' => config_path('queue_export.php')
        ]);*/
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        /*$this->app->singleton('queue_export',function($app){
//            return new QueueExport($app['config']);
        });*/
    }
}
