<?php

namespace App\Providers;

use App\ContextProviders\SafeLivewireContextProviderDetector;
use App\Services\AgentService;
use App\Services\ChatService;
use App\Services\MemoryService;
use App\Services\TickTickService;
use Spatie\FlareClient\Flare;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MemoryService::class, fn () => new MemoryService);
        $this->app->singleton(ChatService::class, fn ($app) => new ChatService(
            $app->make(MemoryService::class)
        ));
        $this->app->singleton(TickTickService::class, fn () => new TickTickService);
        $this->app->singleton(AgentService::class, fn ($app) => new AgentService(
            $app->make(MemoryService::class),
            $app->make(TickTickService::class),
            $app->make(ChatService::class)
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Livewire 4 と Spatie Ignition の非互換を回避（ComponentRegistry が存在しない）
        $this->app->resolving(Flare::class, function (Flare $flare) {
            $flare->setContextProviderDetector(new SafeLivewireContextProviderDetector());
        });
    }
}
