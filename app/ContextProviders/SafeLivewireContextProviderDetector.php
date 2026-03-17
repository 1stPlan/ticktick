<?php

namespace App\ContextProviders;

use Illuminate\Http\Request;
use Spatie\LaravelIgnition\ContextProviders\LaravelConsoleContextProvider;
use Spatie\LaravelIgnition\ContextProviders\LaravelContextProviderDetector;
use Spatie\LaravelIgnition\ContextProviders\LaravelRequestContextProvider;

/**
 * Livewire 4 互換のコンテキストプロバイダー検出器
 *
 * Laravel Ignition の LaravelLivewireRequestContextProvider は
 * Livewire 3 の ComponentRegistry を参照するため Livewire 4 でエラーになる。
 * Livewire リクエスト時は通常の LaravelRequestContextProvider を使用する。
 */
class SafeLivewireContextProviderDetector extends LaravelContextProviderDetector
{
    public function detectCurrentContext(): \Spatie\FlareClient\Context\ContextProvider
    {
        if (app()->runningInConsole()) {
            return new LaravelConsoleContextProvider($_SERVER['argv'] ?? []);
        }

        $request = app(Request::class);

        // Livewire 4 では ComponentRegistry が存在しないため、
        // Livewire リクエストでも通常の RequestContextProvider を使用
        return new LaravelRequestContextProvider($request);
    }
}
