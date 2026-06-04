<?php

namespace App\Providers;

use App\Game\Engine\EffectApplier;
use App\Game\Engine\HintService;
use App\Game\Engine\OutcomeWeigher;
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

        $this->app->singleton(HintService::class, function () {
            return new HintService(config('game.risk_bands'), config('game.traits'));
        });

        $this->app->singleton(OutcomeWeigher::class, function () {
            return new OutcomeWeigher(config('game.traits'));
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
