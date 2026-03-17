<?php

namespace App\Jobs;

use App\Services\TickTickService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAllTickTickTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TickTickService $tickTickService): void
    {
        try {
            $tickTickService->syncAllTasksToCache();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
