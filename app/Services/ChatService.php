<?php

namespace App\Services;

use Illuminate\Support\Collection;
use OpenAI;

/**
 * AI秘書との会話を処理するサービス
 *
 * RAG（過去の記憶）をコンテキストに注入し、GPTで応答を生成します。
 */
class ChatService
{
    private readonly string $chatModel;

    public function __construct(
        private readonly MemoryService $memoryService,
        ?string $chatModel = null
    ) {
        $this->chatModel = $chatModel ?? config('services.openai.chat_model', 'gpt-4o-mini');
    }

    /**
     * ユーザーのメッセージに対してAI秘書の応答を生成
     *
     * @param  string  $userMessage  ユーザーのメッセージ
     * @param  array<int, array{role: string, content: string}>  $conversationHistory  会話履歴
     * @param  int|null  $userId  ユーザーID
     */
    public function chat(string $userMessage, array $conversationHistory = [], ?int $userId = null): string
    {
        $memories = $this->memoryService->recall($userMessage, $userId, limit: 5);
        $memoryContext = $this->memoryService->formatMemoriesForPrompt($memories);

        $systemPrompt = $this->buildSystemPrompt($memoryContext);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$conversationHistory,
            ['role' => 'user', 'content' => $userMessage],
        ];

        $response = $this->callOpenAI($messages);

        return $response;
    }

    /**
     * 会話を記憶に保存する
     */
    public function rememberConversation(string $userMessage, string $assistantResponse, ?int $userId = null): void
    {
        $this->memoryService->remember(
            "ユーザー: {$userMessage}\n秘書: {$assistantResponse}",
            $userId,
            ['type' => 'conversation', 'timestamp' => now()->toIso8601String()]
        );
    }

    private function buildSystemPrompt(string $memoryContext): string
    {
        $basePrompt = <<<'PROMPT'
あなたはユーザー専用の「最強のAI秘書」です。
以下の役割を果たしてください：

- 丁寧で親しみやすい口調で話す
- ユーザーの好みや過去の会話を覚えて、パーソナライズされた対応をする
- タスクの管理、スケジュールの提案、リマインダーなど秘書業務をサポートする
- 曖昧な質問には確認しながら、ユーザーにとって最適な提案をする

【予定・タスクについて】
- ユーザーが「予定」「タスク」「今週の予定」などについて聞いた場合、「TickTick と連携すると、あなたの予定を確認してお伝えできます。画面上部の「TickTick と連携」から設定してみてください」と案内してください。ユーザーに予定を教えてもらうような返答は避けてください。

PROMPT;

        if ($memoryContext !== '') {
            $basePrompt .= "\n\n{$memoryContext}\n\n上記の記憶を踏まえて、ユーザーに応答してください。";
        }

        return $basePrompt;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function callOpenAI(array $messages): string
    {
        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            return '申し訳ございません。AIサービスが設定されていないため、お応えできません。';
        }

        $client = OpenAI::client($apiKey, config('services.openai.organization'));

        try {
            $response = $client->chat()->create([
                'model' => $this->chatModel,
                'messages' => $messages,
                'max_tokens' => 1000,
            ]);

            $content = $response->choices[0]->message->content;

            return $content ?? '応答を生成できませんでした。';
        } catch (\Throwable $e) {
            report($e);

            return '申し訳ございません。一時的なエラーが発生しました。しばらくしてから再度お試しください。';
        }
    }
}
