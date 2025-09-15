<?php

namespace Siddharthgor\UpdateFileGenerator;

use Illuminate\Support\ServiceProvider;
use Siddharthgor\UpdateFileGenerator\Commands\MakeFreshCommand;
use Siddharthgor\UpdateFileGenerator\Commands\MakeUpdateCommand;

class UpdateFileGeneratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeFreshCommand::class,
                MakeUpdateCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/update-file-generator.php' => config_path('update-file-generator.php'),
            ], 'config');
        }
    }
}
