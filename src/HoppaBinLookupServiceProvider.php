<?php

namespace XLaravel\Payline\BinLookup\Hoppa;

use Illuminate\Support\ServiceProvider;

class HoppaBinLookupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('payline.bin_lookup')->extend('hoppa', function ($app, array $config) {
            return new HoppaBinLookup($config);
        });
    }
}
