<?php

namespace Freyo\Flysystem\QcloudCOSv5;


use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use Qcloud\Cos\Client;

/**
 * Class ServiceProvider.
 */
class ServiceProvider extends LaravelServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->make('filesystem')
                  ->extend('cosv5', function ($app, $config) {
                      $client = new Client($config);
                      $adapter = new Adapter($client, $config);

                      $flysystem = new Filesystem($adapter, $config);

                      return new FilesystemAdapter(
                          new Filesystem($adapter, $config),
                          $adapter,
                          $config
                      );
                  });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/filesystems.php', 'filesystems'
        );
    }
}
