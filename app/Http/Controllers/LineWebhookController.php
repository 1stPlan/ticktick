<?php

namespace App\Http\Controllers;

use App\Models\TickTickConnection;
use App\Models\User;
use App\Services\AgentService;
use App\Services\ChatService;
use App\Services\LineService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LineWebhookController extends Controller
{
    public function __construct(
        private readonly LineService $lineService,
        private readonly AgentService $agentService,
        private readonly ChatService $chatService
    ) {}

    /**
     * LINE Webhook 受信
     */
    public function webhook(Request $request): Response
    {
        $body = $request->getContent();
        $signature = $request->header('X-Line-Signature', '');

        if (! $this->lineService->verifySignature($body, $signature)) {
            Log::warning('LineWebhook: 署名検証失敗');

            return response('', 401);
        }

        $data = json_decode($body, true);
        $events = $data['events'] ?? [];

        foreach ($events as $event) {
            $this->handleEvent($event);
        }

        return response('OK', 200);
    }

    private function handleEvent(array $event): void
    {
        $type = $event['type'] ?? '';

        if ($type === 'follow') {
            $this->handleFollow($event);
        } elseif ($type === 'message') {
            $this->handleMessage($event);
        }
    }

    /**
     * 友だち追加時に User を登録
     */
    private function handleFollow(array $event): void
    {
        $userId = $event['source']['userId'] ?? '';
        if ($userId === '') {
            return;
        }

        $lineUserId = 'line_'.$userId;
        $profile = $this->lineService->getProfile($userId);
        $name = $profile['displayName'] ?? 'LINE User';

        User::firstOrCreate(
            ['line_user_id' => $lineUserId],
            ['name' => $name]
        );

        $replyToken = $event['replyToken'] ?? '';
        if ($replyToken !== '') {
            $this->lineService->reply($replyToken, '友だち追加ありがとうございます！予定の確認やタスクの追加ができます。TickTick と連携するには「連携」と送ってください。');
        }
    }

    private function handleMessage(array $event): void
    {
        $message = $event['message'] ?? [];
        if (($message['type'] ?? '') !== 'text') {
            return;
        }

        $replyToken = $event['replyToken'] ?? '';
        $userMessage = trim($message['text'] ?? '');
        $userId = $event['source']['userId'] ?? '';

        if ($userMessage === '') {
            return;
        }

        $lineUserId = 'line_'.$userId;
        $user = User::where('line_user_id', $lineUserId)->first();
        $tickTickConnection = $user
            ? TickTickConnection::where('user_id', $user->id)->first()
            : null;

        if (! $tickTickConnection && $this->isTickTickConnectRequest($userMessage)) {
            $url = url('/ticktick/connect?for='.urlencode($lineUserId));
            $response = "TickTick と連携するには、以下のリンクを開いて認証を完了してください。\n\n{$url}";
            $this->lineService->reply($replyToken, $response);

            return;
        }

        $cacheIdentifier = $tickTickConnection ? $tickTickConnection->identifier : $lineUserId;
        $conversationHistory = $this->getConversationHistory($cacheIdentifier);
        $conversationHistory = collect($conversationHistory)->take(-10)->values()->all();

        try {
            if ($tickTickConnection) {
                $response = $this->agentService->run(
                    $userMessage,
                    $conversationHistory,
                    $tickTickConnection,
                    null
                );
            } else {
                $response = $this->chatService->chat($userMessage, $conversationHistory, null);
                $this->chatService->rememberConversation($userMessage, $response, null);
            }

            $this->saveConversationHistory($cacheIdentifier, $userMessage, $response);
        } catch (\Throwable $e) {
            report($e);
            $response = config('app.debug')
                ? 'エラー: '.$e->getMessage()
                : '申し訳ございません。エラーが発生しました。しばらくしてから再度お試しください。';
        }

        $this->lineService->reply($replyToken, $response);
    }

    private function getConversationHistory(string $identifier): array
    {
        return Cache::get("conv_{$identifier}", []);
    }

    private function saveConversationHistory(string $identifier, string $userMessage, string $assistantResponse): void
    {
        $history = $this->getConversationHistory($identifier);
        $history[] = ['role' => 'user', 'content' => $userMessage];
        $history[] = ['role' => 'assistant', 'content' => $assistantResponse];
        $history = array_slice($history, -20);

        Cache::put("conv_{$identifier}", $history, now()->addHours(24));
    }

    private function isTickTickConnectRequest(string $message): bool
    {
        $keywords = ['連携', 'ticktick', 'ティックティック', '認証', '設定'];
        $normalized = mb_strtolower($message);

        return collect($keywords)->contains(fn ($k) => str_contains($normalized, $k));
    }
}
