<?php

namespace App\Providers;

use App\Game\Engine\EffectApplier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // EffectApplier needs the resource ceilings (for clamping) from config,
        // which autowiring can't supply. Everything else in the engine wires up
        // by constructor type-hint.
        $this->app->singleton(EffectApplier::class, function () {
            return new EffectApplier(config('game.resources'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
