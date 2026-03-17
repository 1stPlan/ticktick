<?php

namespace App\Services;

use App\Models\TickTickConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenAI;

/**
 * AIエージェントサービス
 *
 * タスク分解・ツール実行（TickTick 等）を行う。
 * OpenAI Function Calling でツールを呼び出し。
 */
class AgentService
{
    private static function getTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_ticktick_task',
                    'description' => 'TickTick にタスクを追加する。「〇〇をタスクに追加して」「明日の予定に〇〇を入れて」などで使用。「明日の予定に〇〇を入れて」の場合は due_date を明日の日付に必ず設定する。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => [
                                'type' => 'string',
                                'description' => 'タスクのタイトル（必須）',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'タスクの詳細・メモ',
                            ],
                            'due_date' => [
                                'type' => 'string',
                                'description' => '締め切り日時（ISO8601形式、例: 2025-03-18T10:00:00+09:00）',
                            ],
                            'project_id' => [
                                'type' => 'string',
                                'description' => 'プロジェクトID。空文字でインボックス。不明な場合は空にする。',
                            ],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_ticktick_tasks',
                    'description' => 'TickTick のタスク・予定一覧を取得する。「今日のタスク」「今週の予定」「〇〇の予定はいつ？」「〇〇の日付と詳細を教えて」などで使用。特定タスク（〇〇）の日付・詳細を聞かれたら search に〇〇を指定して呼び出す。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => 'string',
                                'description' => 'タイトルで検索するキーワード。「会食の予定はいつ？」なら「会食」。空なら全件。',
                            ],
                            'project_id' => [
                                'type' => 'string',
                                'description' => '特定プロジェクトのタスクのみ取得する場合のプロジェクトID。空なら全タスク。',
                            ],
                            'start_date' => [
                                'type' => 'string',
                                'description' => 'フィルタ開始日（YYYY-MM-DD）。今週=今週月曜、今月=今月1日、来月=来月1日、来週=来週月曜。空なら制限なし。',
                            ],
                            'end_date' => [
                                'type' => 'string',
                                'description' => 'フィルタ終了日（YYYY-MM-DD）。今週=今週日曜、今月=今月末、来月=来月末、来週=来週日曜。空なら制限なし。',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_ticktick_projects',
                    'description' => 'TickTick のプロジェクト（リスト）一覧を取得する。タスクを追加する先のリストを確認したい時に使用。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            '_unused' => [
                                'type' => 'string',
                                'description' => '未使用（パラメータなしのため空で可）',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function __construct(
        private readonly MemoryService $memoryService,
        private readonly TickTickService $tickTickService,
        private readonly ChatService $chatService
    ) {}

    /**
     * エージェントにメッセージを送り、ツール実行を含む応答を取得
     *
     * @param  array<int, array{role: string, content: string}>  $conversationHistory
     */
    public function run(
        string $userMessage,
        array $conversationHistory,
        ?TickTickConnection $tickTickConnection,
        ?int $userId = null
    ): string {
        $memories = $this->memoryService->recall($userMessage, $userId, limit: 5);
        $memoryContext = $this->memoryService->formatMemoriesForPrompt($memories);

        $systemPrompt = $this->buildSystemPrompt($memoryContext, $tickTickConnection !== null);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$conversationHistory,
            ['role' => 'user', 'content' => $userMessage],
        ];

        $tools = $tickTickConnection ? self::getTools() : [];
        $maxIterations = 5;
        $iteration = 0;

        $forceListTasks = $tickTickConnection
            && $this->isScheduleRelatedQuery($userMessage)
            && ! $this->isCreateTaskQuery($userMessage);

        while ($iteration < $maxIterations) {
            $toolChoice = ($iteration === 0 && $forceListTasks)
                ? ['type' => 'function', 'function' => ['name' => 'list_ticktick_tasks']]
                : 'auto';
            $response = $this->callOpenAI($messages, $tools, $toolChoice);
            $choice = $response->choices[0];
            $message = $choice->message;

            $assistantMsg = [
                'role' => 'assistant',
                'content' => $message->content ?? null,
            ];
            if (isset($message->toolCalls) && count($message->toolCalls) > 0) {
                $assistantMsg['tool_calls'] = array_map(fn ($tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->function->name,
                        'arguments' => $tc->function->arguments,
                    ],
                ], $message->toolCalls);
            }
            $messages[] = $assistantMsg;

            if ($choice->finishReason === 'stop' || empty($message->toolCalls)) {
                $content = $message->content ?? null;
                if ($content === null || $content === '' || trim($content) === '') {
                    $fallback = $this->formatLastToolResultAsFallback($messages, $userMessage);
                    if ($fallback !== null) {
                        Log::info('AgentService: モデルが content を返さなかったため、ツール結果をフォールバックとして使用');
                        $content = $fallback;
                    } elseif ($forceListTasks && $tickTickConnection) {
                        // モデルがツールを呼ばずに stop した場合、手動で list_ticktick_tasks を実行
                        $today = now();
                        $args = ['start_date' => '', 'end_date' => ''];
                        // 特定タスク検索（〇〇の日付など）の場合は日付範囲を指定せず全件から検索
                        if (! preg_match('/(.+?)の(日付|詳細|予定|いつ)/u', $userMessage)) {
                            [$args['start_date'], $args['end_date']] = $this->inferDateRangeFromMessage($userMessage, $today);
                        }
                        $result = $this->executeTool(
                            'list_ticktick_tasks',
                            $args,
                            $tickTickConnection,
                            $userMessage,
                            $messages
                        );
                        $content = $this->formatToolResultAsText($result, $userMessage);
                        Log::info('AgentService: モデルがツールを呼ばなかったため、手動で list_ticktick_tasks を実行');
                    } else {
                        $content = '応答を生成できませんでした。';
                    }
                }
                $this->chatService->rememberConversation($userMessage, $content, $userId);

                return $content;
            }

            foreach ($message->toolCalls ?? [] as $toolCall) {
                $toolArgs = json_decode($toolCall->function->arguments, true) ?? [];
                $toolResult = $this->executeTool(
                    $toolCall->function->name,
                    $toolArgs,
                    $tickTickConnection,
                    $userMessage,
                    $messages
                );

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                ];
            }

            $iteration++;
        }

        return '申し訳ございません。処理が複雑になりすぎたため、もう一度お試しください。';
    }

    private function buildSystemPrompt(string $memoryContext, bool $tickTickConnected): string
    {
        $today = now()->format('Y-m-d');

        $base = <<<'PROMPT'
あなたはユーザー専用の「最強のAI秘書」です。
丁寧で親しみやすい口調で話してください。

PROMPT;

        if ($tickTickConnected) {
            $base .= <<<PROMPT

【TickTick 連携済み - 重要】
- ユーザーが「予定」「タスク」「やること」について聞いた場合、ユーザーに聞き返してはいけません。必ず list_ticktick_tasks ツールを実行してTickTickからデータを取得し、その結果を伝えてください。
- 「今週の予定教えて」「今日のタスクは？」「やること見せて」など、予定・タスク関連の質問には、まず list_ticktick_tasks を呼び出してください。ユーザーが把握している予定を教えてもらうような返答は禁止です。
- 日付範囲（今週、今日、本日など）が指定された場合は start_date と end_date を YYYY-MM-DD で設定。今日・本日は {$today}
- 「〇〇を追加して」「明日の予定に〇〇を入れて」などタスク追加には create_ticktick_task を使用。「明日の予定に〇〇を入れて」の場合は due_date を明日に必ず設定。「そのまま追加して」など詳細を省略された場合は、直前の会話で言及された日付（明日・今日・来週など）を due_date に反映すること。
- 「〇〇の予定はいつ？」「〇〇の日付と詳細を教えて」と聞かれたら list_ticktick_tasks を search に〇〇を指定して呼び出し、該当タスクの日付・詳細を返す。今週の予定一覧ではなく、該当タスクのみを返すこと。
- タスクの追加、プロジェクト確認も可能です。ツール実行後は結果を分かりやすく要約して伝えてください。

PROMPT;
        } else {
            $base .= "\n【TickTick 未連携】タスク管理機能を使うには、まず TickTick と連携してください。\n";
        }

        if ($memoryContext !== '') {
            $base .= "\n{$memoryContext}\n\n上記の記憶を踏まえて応答してください。\n";
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<int, array{role: string, content: ?string}>  $conversationMessages
     */
    private function executeTool(string $name, array $args, ?TickTickConnection $connection, string $userMessage = '', array $conversationMessages = []): string|array
    {
        if (! $connection) {
            return 'TickTick が連携されていません。設定から連携してください。';
        }

        try {
            return match ($name) {
                'create_ticktick_task' => $this->toolCreateTask($connection, $args, $userMessage, $conversationMessages),
                'list_ticktick_tasks' => $this->toolListTasks($connection, $args, $userMessage),
                'list_ticktick_projects' => $this->toolListProjects($connection),
                default => "不明なツール: {$name}",
            };
        } catch (\Throwable $e) {
            report($e);

            return 'エラーが発生しました: '.$e->getMessage();
        }
    }

    /**
     * @param  array{title?: string, content?: string, due_date?: string, project_id?: string}  $args
     * @param  array<int, array{role: string, content: ?string}>  $conversationMessages
     */
    private function toolCreateTask(TickTickConnection $connection, array $args, string $userMessage = '', array $conversationMessages = []): array
    {
        $title = $args['title'] ?? '';
        if ($title === '') {
            return ['success' => false, 'message' => 'タイトルは必須です'];
        }

        $dueDate = $args['due_date'] ?? null;
        $contextForDate = $userMessage;
        foreach ($conversationMessages as $m) {
            if (($m['role'] ?? '') === 'user' && strlen($m['content'] ?? '') > 0) {
                $contextForDate .= ' '.$m['content'];
            }
        }
        $normalized = mb_strtolower($contextForDate);
        if (empty($dueDate) && $this->isCreateTaskQuery($userMessage)) {
            if (str_contains($normalized, '明日')) {
                $dueDate = now()->addDay()->startOfDay()->format('Y-m-d\TH:i:sP');
            } elseif (str_contains($normalized, '今日')) {
                $dueDate = now()->startOfDay()->format('Y-m-d\TH:i:sP');
            } elseif (str_contains($normalized, '来週')) {
                $dueDate = now()->addWeek()->startOfWeek()->format('Y-m-d\TH:i:sP');
            } elseif (str_contains($normalized, '今月')) {
                $dueDate = now()->endOfMonth()->format('Y-m-d\TH:i:sP');
            } elseif (str_contains($normalized, '来月')) {
                $dueDate = now()->addMonth()->startOfMonth()->format('Y-m-d\TH:i:sP');
            }
        }

        $projectId = $args['project_id'] ?? '';
        if ($projectId === '') {
            $projectId = $connection->default_project_id ?? '';
        }

        $taskData = [
            'title' => $title,
            'content' => $args['content'] ?? null,
            'projectId' => $projectId,
            'dueDate' => $dueDate,
            'timeZone' => 'Asia/Tokyo',
        ];

        $task = $this->tickTickService->createTask($connection, $taskData);

        \App\Jobs\SyncTickTickTasksJob::dispatch($connection->id);

        return [
            'success' => true,
            'message' => "タスク「{$title}」を追加しました",
            'task_id' => $task['id'] ?? null,
        ];
    }

    /**
     * @param  array{project_id?: string, start_date?: string, end_date?: string, search?: string}  $args
     */
    private function toolListTasks(TickTickConnection $connection, array $args, string $userMessage = ''): array
    {
        $projectId = $args['project_id'] ?? null;
        $startDate = $args['start_date'] ?? null;
        $endDate = $args['end_date'] ?? null;
        $search = $args['search'] ?? null;

        // 「〇〇の日付を教えて」「〇〇の日付と詳細を教えて」などで search が空なら、ユーザーメッセージから抽出
        // 「本日」「今日」は日付表現なので検索キーワードにしない
        if (empty($search) && $userMessage !== '' && preg_match('/(.+?)の(日付|詳細|予定|いつ)/u', $userMessage, $m)) {
            $extracted = trim($m[1]);
            if (! in_array($extracted, ['本日', '今日', '明日'], true)) {
                $search = $extracted;
            }
        }

        // モデルが start_date/end_date を渡さない場合、メッセージから推測
        if ((empty($startDate) || empty($endDate)) && $userMessage !== '' && empty($search)) {
            [$inferredStart, $inferredEnd] = $this->inferDateRangeFromMessage($userMessage, now());
            $startDate = $startDate ?: $inferredStart;
            $endDate = $endDate ?: $inferredEnd;
        }

        $tasks = $this->tickTickService->getTasks($connection, $projectId);
        $projects = collect($this->tickTickService->getProjects($connection))->keyBy('id');
        $projects->put('inbox', ['id' => 'inbox', 'name' => TickTickService::INBOX_DISPLAY_NAME]);

        $list = array_map(function ($t) use ($projects) {
            $status = $t['status'] ?? 0;
            $completedTime = $t['completedTime'] ?? $t['completed_time'] ?? null;
            $completed = $status === 2 || $status === true || $completedTime !== null;
            $pid = $t['projectId'] ?? $t['project_id'] ?? '';
            $project = $projects->get($pid);
            $projectName = $project['name'] ?? (str_starts_with((string) $pid, 'inbox') ? TickTickService::INBOX_DISPLAY_NAME : ($pid ?: TickTickService::INBOX_DISPLAY_NAME));

            return [
                'id' => $t['id'] ?? '',
                'title' => $t['title'] ?? '',
                'content' => $t['content'] ?? $t['desc'] ?? null,
                'projectId' => $pid,
                'projectName' => $projectName,
                'dueDate' => $t['dueDate'] ?? $t['due_date'] ?? null,
                'startDate' => $t['startDate'] ?? $t['start_date'] ?? null,
                'status' => $completed ? '完了' : '未完了',
            ];
        }, $tasks);

        // 特定タスク検索時はタイトルでフィルタし、日付のないタスクも含める
        if ($search !== null && $search !== '') {
            $list = array_filter($list, fn ($t) => str_contains($t['title'] ?? '', $search));
        } else {
            // 日付のないタスクは除外
            $list = array_filter($list, function ($t) {
                $date = $t['dueDate'] ?? $t['startDate'] ?? null;

                return $date !== null && $date !== '';
            });
        }

        if (($startDate || $endDate) && empty($search)) {
            $list = array_filter($list, function ($t) use ($startDate, $endDate) {
                $date = $t['dueDate'] ?? $t['startDate'] ?? null;
                if (empty($date)) {
                    return false;
                }
                $d = strlen($date) >= 10 ? substr($date, 0, 10) : null;
                if (! $d) {
                    return false;
                }
                if ($startDate && $d < $startDate) {
                    return false;
                }
                if ($endDate && $d > $endDate) {
                    return false;
                }

                return true;
            });
        }

        $list = array_values(array_slice($list, 0, 50));

        return [
            'tasks' => $list,
            'count' => count($list),
            'search' => $search ?: null,
        ];
    }

    private function toolListProjects(TickTickConnection $connection): array
    {
        $projects = $this->tickTickService->getProjects($connection);

        $list = array_map(fn ($p) => [
            'id' => $p['id'] ?? '',
            'name' => $p['name'] ?? '',
        ], $projects);

        return ['projects' => $list];
    }

    private function isScheduleRelatedQuery(string $message): bool
    {
        $keywords = ['予定', 'タスク', 'やること', '今週', '今月', '来週', '来月', '今日', '本日', '明日', 'スケジュール', '確認', '見せて', '教えて', 'リスト'];
        $normalized = mb_strtolower($message);

        return collect($keywords)->contains(fn ($k) => str_contains($normalized, $k));
    }

    /**
     * タスク追加・作成の依頼かどうか（この場合は list を強制しない）
     */
    private function isCreateTaskQuery(string $message): bool
    {
        $keywords = ['追加', '追加して', '入れて', '作成', '登録', '入れて', 'リマインダー'];
        $normalized = mb_strtolower($message);

        return collect($keywords)->contains(fn ($k) => str_contains($normalized, $k));
    }

    /**
     * メッセージから日付範囲を推測する。
     *
     * @return array{string, string} [start_date, end_date] YYYY-MM-DD
     */
    private function inferDateRangeFromMessage(string $message, \Illuminate\Support\Carbon $today): array
    {
        $normalized = mb_strtolower($message);

        if (preg_match('/(\d{1,2})\/(\d{1,2})/u', $message, $m)) {
            $month = (int) $m[1];
            $day = (int) $m[2];
            $year = $today->year;
            try {
                $d = \Illuminate\Support\Carbon::createFromDate($year, $month, $day)->format('Y-m-d');

                return [$d, $d];
            } catch (\Throwable) {
            }
        }

        if (str_contains($normalized, '今日') || str_contains($normalized, '本日')) {
            $d = $today->format('Y-m-d');

            return [$d, $d];
        }
        if (str_contains($normalized, '明日')) {
            $d = $today->copy()->addDay()->format('Y-m-d');

            return [$d, $d];
        }
        if (str_contains($normalized, '来週')) {
            $nextWeek = $today->copy()->addWeek();

            return [
                $nextWeek->copy()->startOfWeek()->format('Y-m-d'),
                $nextWeek->copy()->endOfWeek()->format('Y-m-d'),
            ];
        }
        if (str_contains($normalized, '今月')) {
            return [
                $today->copy()->startOfMonth()->format('Y-m-d'),
                $today->copy()->endOfMonth()->format('Y-m-d'),
            ];
        }
        if (str_contains($normalized, '来月')) {
            $nextMonth = $today->copy()->addMonth();

            return [
                $nextMonth->copy()->startOfMonth()->format('Y-m-d'),
                $nextMonth->copy()->endOfMonth()->format('Y-m-d'),
            ];
        }

        $startOfWeek = $today->copy()->startOfWeek()->format('Y-m-d');
        $endOfWeek = $today->copy()->endOfWeek()->format('Y-m-d');

        return [$startOfWeek, $endOfWeek];
    }

    /**
     * メッセージから予定一覧の見出しを推測する。
     */
    private function inferScheduleHeaderFromMessage(string $message): string
    {
        $normalized = mb_strtolower($message);
        if (str_contains($normalized, '今日') || str_contains($normalized, '本日')) {
            return '【今日の予定】';
        }
        if (preg_match('/(\d{1,2})\/(\d{1,2})/u', $message, $m)) {
            return "【{$m[1]}月{$m[2]}日の予定】";
        }
        if (str_contains($normalized, '明日')) {
            return '【明日の予定】';
        }
        if (str_contains($normalized, '来週')) {
            return '【来週の予定】';
        }
        if (str_contains($normalized, '今月')) {
            return '【今月の予定】';
        }
        if (str_contains($normalized, '来月')) {
            return '【来月の予定】';
        }
        if (str_contains($normalized, '今週')) {
            return '【今週の予定】';
        }

        return '【予定】';
    }

    /**
     * 日付文字列（YYYY-MM-DD）を「3月21日(土)」形式に変換する。
     */
    private function formatDateJapanese(string $dateStr): string
    {
        $carbon = \Illuminate\Support\Carbon::parse($dateStr);
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return $carbon->format('n月j日').'('.$weekdays[(int) $carbon->format('w')].')';
    }

    /**
     * タスク一覧をリスト名付きで整形する。
     *
     * @param  array<int, array{title: string, projectId?: string, projectName?: string, dueDate?: string, startDate?: string}>  $tasks
     */
    /**
     * @param  bool  $includeTasksWithoutDate  検索結果などで日付のないタスクも表示するか
     */
    private function formatTasksWithProjects(array $tasks, string $scheduleHeader, bool $includeTasksWithoutDate = false): string
    {
        if (! $includeTasksWithoutDate) {
            $tasks = array_filter($tasks, function ($t) {
                $date = $t['dueDate'] ?? $t['startDate'] ?? $t['due_date'] ?? $t['start_date'] ?? null;

                return $date !== null && $date !== '';
            });
        }
        if (empty($tasks)) {
            return '該当する予定はありません。';
        }
        $lines = [$scheduleHeader];
        $byProject = collect($tasks)->groupBy(fn ($t) => $t['projectName'] ?? $t['project_id'] ?? $t['projectId'] ?? 'その他');
        foreach ($byProject as $projectName => $projectTasks) {
            $projectTasks = $projectTasks->sortBy(fn ($t) => $t['dueDate'] ?? $t['startDate'] ?? $t['due_date'] ?? $t['start_date'] ?? '');
            if ($projectName) {
                $lines[] = '   '.$projectName;
            }
            foreach ($projectTasks as $t) {
                $title = $t['title'] ?? '(無題)';
                $content = $t['content'] ?? null;
                $due = $t['dueDate'] ?? $t['startDate'] ?? $t['due_date'] ?? $t['start_date'] ?? null;
                $dateStr = ($due !== null && $due !== '')
                    ? $this->formatDateJapanese(substr($due, 0, 10))
                    : '日付未設定';
                $lines[] = '   '.$dateStr.'  '.$title;
                if ($includeTasksWithoutDate && $content !== null && $content !== '') {
                    $lines[] = '      詳細: '.$content;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * ツール実行後にモデルが content を返さなかった場合、直近のツール結果を整形してフォールバックとして返す。
     *
     * @param  array<int, array{role: string, content: ?string}>  $messages
     */
    private function formatLastToolResultAsFallback(array $messages, string $userMessage = ''): ?string
    {
        $scheduleHeader = $this->inferScheduleHeaderFromMessage($userMessage);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            if (($m['role'] ?? '') !== 'tool') {
                continue;
            }
            $content = $m['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return $content;
            }
            if (isset($decoded['tasks'])) {
                $search = $decoded['search'] ?? null;
                $header = $search ? "【{$search}の予定】" : $scheduleHeader;

                return $this->formatTasksWithProjects($decoded['tasks'], $header, (bool) $search);
            }
            if (isset($decoded['success'], $decoded['message'])) {
                return $decoded['message'];
            }
            if (isset($decoded['projects'])) {
                $projects = $decoded['projects'];
                if (empty($projects)) {
                    return 'プロジェクトはありません。';
                }
                $lines = ['【プロジェクト】'];
                foreach ($projects as $p) {
                    $lines[] = '・'.($p['name'] ?? $p['id'] ?? '');
                }

                return implode("\n", $lines);
            }

            return $content;
        }

        return null;
    }

    /**
     * ツール実行結果（配列または文字列）をユーザー向けテキストに整形する。
     *
     * @param  string|array  $result
     */
    private function formatToolResultAsText(string|array $result, string $userMessage = ''): string
    {
        if (is_string($result)) {
            return $result;
        }
        if (isset($result['tasks'])) {
            $search = $result['search'] ?? null;
            $header = $search ? "【{$search}の予定】" : $this->inferScheduleHeaderFromMessage($userMessage);

            return $this->formatTasksWithProjects($result['tasks'], $header, (bool) $search);
        }
        if (isset($result['success'], $result['message'])) {
            return $result['message'];
        }
        if (isset($result['projects'])) {
            $projects = $result['projects'];
            if (empty($projects)) {
                return 'プロジェクトはありません。';
            }
            $lines = ['【プロジェクト】'];
            foreach ($projects as $p) {
                $lines[] = '・'.($p['name'] ?? $p['id'] ?? '');
            }

            return implode("\n", $lines);
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param  array<int, array{role: string, content: ?string, tool_calls?: mixed}>  $messages
     * @param  string|array  $toolChoice
     */
    private function callOpenAI(array $messages, array $tools, string|array $toolChoice = 'auto'): \OpenAI\Responses\Chat\CreateResponse
    {
        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY が設定されていません');
        }

        $client = OpenAI::client($apiKey, config('services.openai.organization'));

        $params = [
            'model' => config('services.openai.chat_model', 'gpt-4o-mini'),
            'messages' => $messages,
            'max_tokens' => 1000,
        ];

        if ($tools !== []) {
            $params['tools'] = $tools;
            $params['tool_choice'] = $toolChoice;
        }

        return $client->chat()->create($params);
    }
}
