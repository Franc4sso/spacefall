<?php

namespace App\Providers;

use App\Game\Engine\EffectApplier;
use App\Game\Engine\HintService;
use App\Game\Engine\OutcomeWeigher;
use App\Game\ThemeConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // EffectApplier, HintService, OutcomeWeigher resolve config per-call
        // using ThemeConfig so they remain theme-agnostic singletons.
        $this->app->singleton(EffectApplier::class, fn ($app) => new EffectApplier($app->make(ThemeConfig::class)));
        $this->app->singleton(HintService::class, fn ($app) => new HintService($app->make(ThemeConfig::class)));
        $this->app->singleton(OutcomeWeigher::class, fn ($app) => new OutcomeWeigher($app->make(ThemeConfig::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
