<?php

namespace App\Console\Commands;

use App\Models\TickTickConnection;
use App\Services\TickTickService;
use Illuminate\Console\Command;

class SyncTickTickTasks extends Command
{
    protected $signature = 'ticktick:sync {--connection= : 特定の接続IDのみ同期}';

    protected $description = 'TickTick タスクをローカルキャッシュに同期する';

    public function handle(TickTickService $tickTickService): int
    {
        $connectionId = $this->option('connection');

        if ($connectionId) {
            $connection = TickTickConnection::find($connectionId);
            if (! $connection) {
                $this->error("接続 ID {$connectionId} が見つかりません。");

                return 1;
            }
            $tickTickService->syncTasksToCache($connection);
            $this->info("接続 ID {$connectionId} の同期を実行しました。");

            return 0;
        }

        $connections = TickTickConnection::all();
        if ($connections->isEmpty()) {
            $this->warn('TickTick 接続がありません。');

            return 0;
        }

        $tickTickService->syncAllTasksToCache();
        $this->info("{$connections->count()} 件の接続を同期しました（ID を 1 からリセット）。");

        return 0;
    }
}
