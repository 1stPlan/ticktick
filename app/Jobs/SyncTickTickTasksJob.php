<?php

namespace App\Jobs;

use App\Models\TickTickConnection;
use App\Services\TickTickService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncTickTickTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $tickTickConnectionId
    ) {}

    public function handle(TickTickService $tickTickService): void
    {
        $connection = TickTickConnection::find($this->tickTickConnectionId);
        if (! $connection) {
            return;
        }

        try {
            $tickTickService->syncTasksToCache($connection);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
