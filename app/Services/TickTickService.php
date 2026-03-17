<?php

namespace App\Services;

use App\Models\TickTickConnection;
use App\Models\TickTickTaskCache;
use Illuminate\Support\Facades\Http;

/**
 * TickTick Open API クライアント
 *
 * @see https://developer.ticktick.com/docs
 */
class TickTickService
{
    private const API_BASE = 'https://api.ticktick.com/open/v1';

    /** inbox 系プロジェクト（inbox, inbox131190907 など）の統一表示名 */
    public const INBOX_DISPLAY_NAME = '✉️inbox';

    private const OAUTH_TOKEN_URL = 'https://ticktick.com/oauth/token';

    private const OAUTH_AUTHORIZE_URL = 'https://ticktick.com/oauth/authorize';

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => config('services.ticktick.client_id'),
            'scope' => 'tasks:read tasks:write',
            'state' => $state,
            'redirect_uri' => config('services.ticktick.redirect_uri'),
            'response_type' => 'code',
        ]);

        return self::OAUTH_AUTHORIZE_URL.'?'.$params;
    }

    /**
     * 認可コードをアクセストークンに交換
     *
     * @return array{access_token: string, refresh_token?: string, expires_in?: int}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'client_id' => config('services.ticktick.client_id'),
            'client_secret' => config('services.ticktick.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.ticktick.redirect_uri'),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('TickTick token exchange failed: '.$response->body());
        }

        $data = $response->json();
        $expiresIn = $data['expires_in'] ?? 0;

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $expiresIn,
            'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
        ];
    }

    /**
     * リフレッシュトークンでアクセストークンを更新
     */
    public function refreshAccessToken(TickTickConnection $connection): void
    {
        if (! $connection->refresh_token) {
            throw new \RuntimeException('Refresh token not available');
        }

        $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'client_id' => config('services.ticktick.client_id'),
            'client_secret' => config('services.ticktick.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('TickTick token refresh failed: '.$response->body());
        }

        $data = $response->json();
        $expiresIn = $data['expires_in'] ?? 0;

        $connection->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
        ]);
    }

    /**
     * プロジェクト（リスト）一覧を取得
     *
     * @return array<int, array{id: string, name: string, ...}>
     */
    public function getProjects(TickTickConnection $connection): array
    {
        $this->ensureValidToken($connection);

        $response = Http::withToken($connection->access_token)
            ->get(self::API_BASE.'/project');

        if (! $response->successful()) {
            throw new \RuntimeException('TickTick get projects failed: '.$response->body());
        }

        return $response->json();
    }

    /** キャッシュ有効期限（分） */
    private const CACHE_TTL_MINUTES = 30;

    /**
     * タスク一覧を取得（プロジェクト指定 or 全件）
     * 全件取得時はローカルキャッシュを優先（30分以内に同期済みならキャッシュを使用）
     *
     * TickTick Open API では GET /task がサポートされていないため、
     * 各プロジェクトの /project/{id}/data を取得してタスクを集約する。
     * get_projects() は inbox を返さないため、全件取得時は inbox を明示的に追加する。
     *
     * @return array<int, array{id: string, title: string, projectId: string, ...}>
     */
    public function getTasks(TickTickConnection $connection, ?string $projectId = null): array
    {
        if ($projectId === null && $this->isCacheFresh($connection)) {
            return $this->getTasksFromCache($connection);
        }

        if ($projectId === null) {
            $this->syncTasksToCache($connection);

            return $this->getTasksFromCache($connection);
        }

        return $this->fetchTasksFromApi($connection, $projectId);
    }

    /**
     * API からタスクを取得しローカルキャッシュに保存する（単一接続用）
     */
    public function syncTasksToCache(TickTickConnection $connection): void
    {
        $toInsert = $this->buildTaskCacheRows($connection);
        TickTickTaskCache::where('ticktick_connection_id', $connection->id)->delete();
        foreach (array_chunk($toInsert, 100) as $chunk) {
            TickTickTaskCache::insert($chunk);
        }
    }

    /**
     * 全接続のタスクを同期し、ID を 1 からリセットする
     */
    public function syncAllTasksToCache(): void
    {
        $connections = TickTickConnection::all();
        if ($connections->isEmpty()) {
            return;
        }

        $allRows = [];
        foreach ($connections as $connection) {
            $allRows = array_merge($allRows, $this->buildTaskCacheRows($connection));
        }

        TickTickTaskCache::truncate();
        foreach (array_chunk($allRows, 100) as $chunk) {
            TickTickTaskCache::insert($chunk);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTaskCacheRows(TickTickConnection $connection): array
    {
        $tasks = $this->fetchTasksFromApi($connection, null);
        $projects = collect($this->getProjects($connection))->keyBy('id');
        $projects->put('inbox', ['id' => 'inbox', 'name' => self::INBOX_DISPLAY_NAME]);

        $now = now();
        $rows = [];

        foreach ($tasks as $task) {
            $status = ($task['status'] ?? 0) === 2 || ($task['completedTime'] ?? $task['completed_time'] ?? null)
                ? '完了'
                : '未完了';
            $pid = $task['projectId'] ?? $task['project_id'] ?? '';
            $project = $projects->get($pid);

            $rows[] = [
                'ticktick_connection_id' => $connection->id,
                'ticktick_task_id' => $task['id'] ?? '',
                'title' => $task['title'] ?? '',
                'project_id' => $pid,
                'project_name' => $project['name'] ?? ((str_starts_with((string) $pid, 'inbox') || $pid === '') ? self::INBOX_DISPLAY_NAME : $pid),
                'due_date' => $task['dueDate'] ?? $task['due_date'] ?? null,
                'start_date' => $task['startDate'] ?? $task['start_date'] ?? null,
                'content' => $task['content'] ?? $task['desc'] ?? null,
                'status' => $status,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * API からタスクを取得（キャッシュを使わない）
     *
     * @return array<int, array{id: string, title: string, projectId: string, ...}>
     */
    private function fetchTasksFromApi(TickTickConnection $connection, ?string $projectId): array
    {
        $this->ensureValidToken($connection);

        $projects = $projectId
            ? [['id' => $projectId === 'inbox' ? 'inbox' : $projectId]]
            : $this->getProjectsWithInbox($connection);

        $allTasks = [];
        foreach ($projects as $project) {
            $id = $project['id'] ?? null;
            if (! $id) {
                continue;
            }
            $response = Http::withToken($connection->access_token)
                ->get(self::API_BASE."/project/{$id}/data");

            if (! $response->successful()) {
                if (str_starts_with((string) $id, 'inbox')) {
                    \Illuminate\Support\Facades\Log::warning('TickTick inbox fetch failed (skipping): '.$response->body());
                    continue;
                }
                throw new \RuntimeException('TickTick get tasks failed: '.$response->body());
            }

            $data = $response->json();
            $tasks = $data['tasks'] ?? $data['task'] ?? [];
            if (! is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                $task['projectId'] = $task['projectId'] ?? $task['project_id'] ?? $id;
                $allTasks[] = $task;
            }
        }

        return $allTasks;
    }

    private function isCacheFresh(TickTickConnection $connection): bool
    {
        $lastSynced = TickTickTaskCache::where('ticktick_connection_id', $connection->id)->max('synced_at');
        if (! $lastSynced) {
            return false;
        }

        return \Illuminate\Support\Carbon::parse($lastSynced)->gt(now()->subMinutes(self::CACHE_TTL_MINUTES));
    }

    /**
     * @return array<int, array{id: string, title: string, projectId: string, ...}>
     */
    private function getTasksFromCache(TickTickConnection $connection): array
    {
        return TickTickTaskCache::where('ticktick_connection_id', $connection->id)
            ->get()
            ->map(fn (TickTickTaskCache $c) => [
                'id' => $c->ticktick_task_id,
                'title' => $c->title,
                'content' => $c->content,
                'projectId' => $c->project_id ?? '',
                'project_id' => $c->project_id,
                'dueDate' => $c->due_date,
                'startDate' => $c->start_date,
                'status' => $c->status,
            ])
            ->all();
    }

    /**
     * プロジェクト一覧を取得し、inbox を含める。
     * get_projects() は inbox を返さないため、全件取得時に明示的に追加する。
     *
     * @return array<int, array{id: string, name?: string, ...}>
     */
    private function getProjectsWithInbox(TickTickConnection $connection): array
    {
        $projects = $this->getProjects($connection);
        $projects = array_map(function ($p) {
            if (str_starts_with((string) ($p['id'] ?? ''), 'inbox')) {
                $p['name'] = self::INBOX_DISPLAY_NAME;
            }

            return $p;
        }, $projects);

        $hasInbox = collect($projects)->contains(fn ($p) => str_starts_with((string) ($p['id'] ?? ''), 'inbox'));
        if (! $hasInbox) {
            $projects[] = ['id' => 'inbox', 'name' => self::INBOX_DISPLAY_NAME];
        }

        return $projects;
    }

    /**
     * タスクを作成
     *
     * @param  array{title: string, projectId?: string, content?: string, dueDate?: string, startDate?: string, priority?: int}  $taskData
     * @return array{id: string, title: string, ...}
     */
    public function createTask(TickTickConnection $connection, array $taskData): array
    {
        $this->ensureValidToken($connection);

        $payload = array_filter([
            'title' => $taskData['title'] ?? '',
            'projectId' => $taskData['projectId'] ?? '',
            'content' => $taskData['content'] ?? null,
            'desc' => $taskData['desc'] ?? null,
            'dueDate' => $taskData['dueDate'] ?? null,
            'startDate' => $taskData['startDate'] ?? null,
            'priority' => $taskData['priority'] ?? 0,
            'allDay' => $taskData['allDay'] ?? false,
            'timeZone' => $taskData['timeZone'] ?? 'Asia/Tokyo',
        ], fn ($v) => $v !== null && $v !== '');

        $response = Http::withToken($connection->access_token)
            ->post(self::API_BASE.'/task', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('TickTick create task failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * タスクを完了にする
     */
    public function completeTask(TickTickConnection $connection, string $projectId, string $taskId): void
    {
        $this->ensureValidToken($connection);

        $response = Http::withToken($connection->access_token)
            ->post(self::API_BASE."/project/{$projectId}/task/{$taskId}/complete");

        if (! $response->successful()) {
            throw new \RuntimeException('TickTick complete task failed: '.$response->body());
        }
    }

    private function ensureValidToken(TickTickConnection $connection): void
    {
        if ($connection->needsRefresh()) {
            $this->refreshAccessToken($connection);
            $connection->refresh();
        }
    }
}
