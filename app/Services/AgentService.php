<?php

namespace App\Services;

use App\Models\TickTickConnection;
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
    private static function weatherToolDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_weather_forecast',
                'description' => '日本国内の天気予報を取得する。ユーザーが天気・気温・降水・予報などを尋ねたときに使う。地名はユーザーが言及した都道府県・市区町村を location に入れる。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'location' => [
                            'type' => 'string',
                            'description' => '地名（例: 山口県、東京都、大阪市）。',
                        ],
                        'when' => [
                            'type' => 'string',
                            'description' => 'today=今日, tomorrow=明日, day_after_tomorrow=明後日。省略時はユーザーの文から推測。',
                            'enum' => ['today', 'tomorrow', 'day_after_tomorrow'],
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ];
    }

    private static function tickTickToolDefinitions(): array
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTools(?TickTickConnection $tickTickConnection): array
    {
        $tools = [];
        if (config('services.weather.enabled', true)) {
            $tools[] = self::weatherToolDefinition();
        }
        if ($tickTickConnection !== null) {
            $tools = array_merge($tools, self::tickTickToolDefinitions());
        }

        return $tools;
    }

    public function __construct(
        private readonly MemoryService $memoryService,
        private readonly TickTickService $tickTickService,
        private readonly ChatService $chatService,
        private readonly WeatherService $weatherService,
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

        $tools = $this->buildTools($tickTickConnection);
        $maxIterations = 5;
        $iteration = 0;

        $bothWeatherAndSchedule = $this->isWeatherQuery($userMessage) && $this->isScheduleRelatedQuery($userMessage);

        $forceListTasks = $tickTickConnection
            && $this->isScheduleRelatedQuery($userMessage)
            && ! $this->isCreateTaskQuery($userMessage)
            && ! $bothWeatherAndSchedule;

        $forceCreateTask = $tickTickConnection && $this->isCreateTaskQuery($userMessage);

        $forceWeather = config('services.weather.enabled', true)
            && $this->isWeatherQuery($userMessage)
            && ! $this->isCreateTaskQuery($userMessage)
            && ! $bothWeatherAndSchedule;

        while ($iteration < $maxIterations) {
            $toolChoice = $this->initialToolChoice($iteration, $forceListTasks, $forceCreateTask, $forceWeather);
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

            // finish_reason=stop でも tool_calls が付いていると、旧条件だとツール未実行で finalize に入ってしまう。
            // ツール呼び出しがあるときは必ず実行し、空のときだけ本文確定へ進む。
            if (empty($message->toolCalls)) {
                $content = $this->finalizeAssistantContentWithoutToolLoop(
                    $message,
                    $userMessage,
                    $tickTickConnection,
                    $messages,
                    $forceListTasks,
                    $forceCreateTask
                );
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

    /**
     * finishReason=stop かつツール未実行のとき、本文を確定する（モデルが誤案内のみ返した場合はフォールバックで API 追加）
     *
     * @param  array<int, array{role: string, content: ?string}>  $messages
     */
    private function finalizeAssistantContentWithoutToolLoop(
        object $assistantMessage,
        string $userMessage,
        ?TickTickConnection $tickTickConnection,
        array $messages,
        bool $forceListTasks,
        bool $forceCreateTask
    ): string {
        $content = $assistantMessage->content ?? null;

        if ($content !== null && trim((string) $content) !== '') {
            return $content;
        }

        // ツールは実行済みだがモデルが本文を返さない場合は、直近のツール結果を優先（手動 create より先）
        $fallback = $this->formatLastToolResultAsFallback($messages, $userMessage);
        if ($fallback !== null) {
            Log::info('AgentService: モデルが content を返さなかったため、ツール結果をフォールバックとして使用');

            return $fallback;
        }

        if ($forceListTasks && $tickTickConnection) {
            Log::info('AgentService: list_ticktick_tasks をフォールバック実行');

            return $this->runManualListTickTickTasks($userMessage, $tickTickConnection, $messages);
        }

        if ($forceCreateTask && $tickTickConnection) {
            $manual = $this->runManualCreateTickTickTask($userMessage, $tickTickConnection, $messages);
            if ($manual !== null && trim($manual) !== '') {
                return $manual;
            }

            Log::warning('AgentService: タスク追加を解釈できずフォールバックも不可', [
                'user_message_preview' => mb_substr($userMessage, 0, 200),
            ]);

            return 'タスクの内容が分かりませんでした。「〇〇を追加して」や「〇〇という予定を追加して」のように送ってください。';
        }

        Log::warning('AgentService: 空応答のため既定メッセージを返却', [
            'user_message_preview' => mb_substr($userMessage, 0, 200),
        ]);

        return '応答を生成できませんでした。もう一度短い文章で送ってください。';
    }

    /**
     * @param  array<int, array{role: string, content: ?string}>  $messages
     */
    private function runManualCreateTickTickTask(
        string $userMessage,
        TickTickConnection $connection,
        array $messages,
    ): ?string {
        $title = $this->inferTitleFromCreateMessage($userMessage);
        if ($title === null || $title === '') {
            return null;
        }
        $result = $this->executeTool(
            'create_ticktick_task',
            ['title' => $title],
            $connection,
            $userMessage,
            $messages
        );
        Log::info('AgentService: モデルがツールを呼ばなかったため、手動で create_ticktick_task を実行', ['title' => $title]);

        return $this->formatToolResultAsText($result, $userMessage);
    }

    /**
     * @param  array<int, array{role: string, content: ?string}>  $messages
     */
    private function runManualListTickTickTasks(
        string $userMessage,
        TickTickConnection $connection,
        array $messages,
    ): string {
        $today = now();
        $args = ['start_date' => '', 'end_date' => ''];
        if (! preg_match('/(.+?)の(日付|詳細|予定|いつ)/u', $userMessage)) {
            [$args['start_date'], $args['end_date']] = $this->inferDateRangeFromMessage($userMessage, $today);
        }
        $result = $this->executeTool(
            'list_ticktick_tasks',
            $args,
            $connection,
            $userMessage,
            $messages
        );

        return $this->formatToolResultAsText($result, $userMessage);
    }

    /** @return string|array<string, mixed> */
    private function initialToolChoice(int $iteration, bool $forceListTasks, bool $forceCreateTask, bool $forceWeather): string|array
    {
        if ($iteration !== 0) {
            return 'auto';
        }
        if ($forceListTasks) {
            return ['type' => 'function', 'function' => ['name' => 'list_ticktick_tasks']];
        }
        if ($forceCreateTask) {
            return ['type' => 'function', 'function' => ['name' => 'create_ticktick_task']];
        }
        if ($forceWeather) {
            return ['type' => 'function', 'function' => ['name' => 'get_weather_forecast']];
        }

        return 'auto';
    }

    private function buildSystemPrompt(string $memoryContext, bool $tickTickConnected): string
    {
        $today = now()->format('Y-m-d');

        $base = <<<'PROMPT'
あなたはユーザー専用の「最強のAI秘書」です。
丁寧で親しみやすい口調で話してください。

PROMPT;

        if (config('services.weather.enabled', true)) {
            $base .= <<<'PROMPT'

【天気予報（Open-Meteo 無料 API・キー不要）】
- 天気・気温・降水確率など**最新の予報**が必要なときは **get_weather_forecast** ツールを呼び出してください。地名がなければユーザーが言及している都道府県・市区町村を location に入れてください。

PROMPT;
        }

        if ($tickTickConnected) {
            $base .= <<<PROMPT

【TickTick 連携済み - 重要】
- **すでに TickTick は連携済みです。**「連携してください」「LINE で連携と送って」「認証リンクから」など、連携を促す説明は**禁止**です。予定・タスクの追加・一覧は必ずツール（create_ticktick_task / list_ticktick_tasks）で行ってください。
- ユーザーが「予定」「タスク」「やること」について聞いた場合、ユーザーに聞き返してはいけません。必ず list_ticktick_tasks ツールを実行してTickTickからデータを取得し、その結果を伝えてください。
- 「今週の予定教えて」「今日のタスクは？」「やること見せて」など、予定・タスク関連の質問には、まず list_ticktick_tasks を呼び出してください。ユーザーが把握している予定を教えてもらうような返答は禁止です。
- 日付範囲（今週、今日、本日など）が指定された場合は start_date と end_date を YYYY-MM-DD で設定。今日・本日は {$today}
- 「〇〇を追加して」「明日の予定に〇〇を入れて」などタスク追加には create_ticktick_task を使用。「明日の予定に〇〇を入れて」の場合は due_date を明日に必ず設定。「そのまま追加して」など詳細を省略された場合は、直前の会話で言及された日付（明日・今日・来週など）を due_date に反映すること。
- 「〇〇の予定はいつ？」「〇〇の日付と詳細を教えて」と聞かれたら list_ticktick_tasks を search に〇〇を指定して呼び出し、該当タスクの日付・詳細を返す。今週の予定一覧ではなく、該当タスクのみを返すこと。
- タスクの追加、プロジェクト確認も可能です。ツール実行後は結果を分かりやすく要約して伝えてください。

【それ以外の会話（雑談・一般質問）】
- 予定・タスク・TickTick の操作と**無関係**な内容（雑談、豆知識、文章の相談、軽い質問など）は、**通常の AI 秘書として自然に応答**してください。TickTick に誘導する必要はありません。
- **リアルタイムの天気・気温・警報**など、外部データがないと正確に言えないことは、推測で数値を出さず、「最新は気象庁や天気アプリで確認して」と案内しつつ、一般的な説明や季節の話で補っても構いません。

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
        if ($name === 'get_weather_forecast') {
            try {
                return $this->toolGetWeatherForecast($args, $userMessage);
            } catch (\Throwable $e) {
                report($e);

                return 'エラーが発生しました: '.$e->getMessage();
            }
        }

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
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function toolGetWeatherForecast(array $args, string $userMessage): array
    {
        $location = trim((string) ($args['location'] ?? ''));
        if ($location === '') {
            $location = $this->extractLocationFromMessage($userMessage) ?? '';
        }
        if ($location === '') {
            return [
                'success' => false,
                'message' => '地域を特定できませんでした。都道府県や市区町村名を含めて送ってください。',
            ];
        }

        $when = $args['when'] ?? null;
        if (! is_string($when) || $when === '' || ! in_array($when, ['today', 'tomorrow', 'day_after_tomorrow'], true)) {
            $when = $this->inferWeatherWhen($userMessage);
        }

        Log::info('AgentService: get_weather_forecast を実行', ['location' => $location, 'when' => $when]);

        return $this->weatherService->forecastSummary($location, $when);
    }

    private function inferWeatherWhen(string $userMessage): string
    {
        if (preg_match('/明後日|あさって/u', $userMessage)) {
            return 'day_after_tomorrow';
        }
        if (preg_match('/明日/u', $userMessage)) {
            return 'tomorrow';
        }
        if (preg_match('/今日|本日/u', $userMessage)) {
            return 'today';
        }

        return 'tomorrow';
    }

    private function extractLocationFromMessage(string $message): ?string
    {
        if (preg_match('/([\p{Han}]{1,6}(?:都|道|府|県))/u', $message, $m)) {
            return $m[1];
        }
        if (preg_match('/(東京都|大阪府|京都府|北海道)/u', $message, $m)) {
            return $m[1];
        }
        if (preg_match('/(札幌|仙台|横浜|川崎|名古屋|京都|大阪|神戸|広島|北九州|福岡|那覇)市/u', $message, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * 天気ツールを初回に強制するか（雑談と区別するヒューリスティック）
     */
    private function isWeatherQuery(string $message): bool
    {
        return (bool) preg_match(
            '/天気|気温|降水|気象|天候|天気予報|雨量|台風|雷雨|猛暑|寒潮|紫外線|湿度|風速|梅雨|大雪|体感|雨が|雨は|雨か|雨に|雨天|雨模様|雪が|雪は|雪か/u',
            $message
        );
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

    /**
     * 予定・タスク一覧（list_ticktick_tasks）が妥当な質問か。
     * 「今日」「教えて」だけで true になると天気など雑談が誤って一覧強制になるため、キーワードを絞る。
     */
    private function isScheduleRelatedQuery(string $message): bool
    {
        if ($this->isLikelyNonScheduleContextQuery($message)) {
            return false;
        }

        $normalized = mb_strtolower($message);

        $strongKeywords = ['予定', 'タスク', 'やること', 'スケジュール', 'リスト', 'リマインダー', 'ticktick', 'ティックティック'];
        foreach ($strongKeywords as $k) {
            if (str_contains($normalized, $k)) {
                return true;
            }
        }

        $periodKeywords = ['今週', '今月', '来週', '来月'];
        foreach ($periodKeywords as $k) {
            if (str_contains($normalized, $k)) {
                return true;
            }
        }

        $dateHints = ['今日', '本日', '明日', '明後日'];
        $pairedHints = ['予定', 'タスク', 'やること', 'スケジュール', '会議', 'ミーティング', '面談', '空いて', '空き', '埋ま', 'リマインダー'];
        foreach ($dateHints as $d) {
            if (! str_contains($normalized, $d)) {
                continue;
            }
            foreach ($pairedHints as $p) {
                if (str_contains($normalized, $p)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 天気・気象など、TickTick 予定一覧とは無関係な文脈（一覧ツールを強制したくない）
     */
    private function isLikelyNonScheduleContextQuery(string $message): bool
    {
        if (preg_match('/予定|タスク|スケジュール|やること|ticktick|ティックティック/u', $message)) {
            return false;
        }

        return (bool) preg_match(
            '/天気|気温|降水|気象|天候|台風|雨量|雷雨|猛暑|寒潮|警報|注意報|天気予報|紫外線|湿度|風速/u',
            $message
        );
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
     * タスク追加メッセージからタイトルを推定（ツール未実行時のフォールバック用）
     */
    private function inferTitleFromCreateMessage(string $message): ?string
    {
        $message = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $message);
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        if (preg_match('/^(.+?)という予定を/u', $message, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(.+?)をタスクに追加/u', $message, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(.+?)を追加して/u', $message, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(.+?)を追加/u', $message, $m)) {
            return trim($m[1]);
        }

        // 「〇〇追加して」「〇〇 追加」のようにパターン外のとき、末尾の依頼語を除いてタイトル化
        $suffixes = [
            'という予定を追加して', 'という予定を追加', 'をタスクに追加して', 'をタスクに追加',
            'を追加して', 'を追加', 'と追加して', '追加して', '追加',
        ];
        $candidate = $message;
        foreach ($suffixes as $s) {
            if (mb_strlen($candidate) > mb_strlen($s) && str_ends_with($candidate, $s)) {
                $candidate = trim(mb_substr($candidate, 0, mb_strlen($candidate) - mb_strlen($s)));
                break;
            }
        }
        if ($candidate !== '' && $candidate !== $message && mb_strlen($candidate) <= 500) {
            return $candidate;
        }

        return null;
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
