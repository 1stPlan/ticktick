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
     * GET: 疎通確認用（ブラウザで開いたときなど）
     * POST: LINE からのイベント受信
     */
    public function webhook(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return response('LINE Webhook endpoint (POST only for events)', 200);
        }

        $body = $request->getContent();
        $signature = $request->header('X-Line-Signature', '');

        Log::info('LineWebhook: リクエスト受信', [
            'body_length' => strlen($body),
            'has_signature' => $signature !== '',
        ]);

        if (! $this->lineService->verifySignature($body, $signature)) {
            Log::warning('LineWebhook: 署名検証失敗', [
                'channel_secret_set' => ! empty(config('services.line.channel_secret')),
            ]);

            return response('', 401);
        }

        $data = json_decode($body, true);
        $events = $data['events'] ?? [];

        Log::info('LineWebhook: イベント処理開始', ['event_count' => count($events)]);

        foreach ($events as $event) {
            $this->handleEvent($event);
        }

        return response('OK', 200);
    }

    private function handleEvent(array $event): void
    {
        $type = $event['type'] ?? '';

        Log::info('LineWebhook: イベント種別', ['type' => $type]);

        if ($type === 'follow') {
            $this->handleFollow($event);
        } elseif ($type === 'message') {
            $this->handleMessage($event);
        } else {
            Log::info('LineWebhook: 未処理のイベント種別', ['type' => $type]);
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

        $user = $this->resolveLineUserForMessagingUserId($userId);
        $profile = $this->lineService->getProfile($userId);
        $name = $profile['displayName'] ?? 'LINE User';
        if (in_array($user->name, ['LINE User', ''], true)) {
            $user->update(['name' => $name]);
        }

        $replyToken = $event['replyToken'] ?? '';
        if ($replyToken !== '') {
            if (! $this->lineService->reply($replyToken, '友だち追加ありがとうございます！予定の確認やタスクの追加ができます。TickTick と連携するには「連携」と送ってください。')) {
                Log::error('LineWebhook: Follow 時の Reply API 失敗', [
                    'line_user_id' => $user->line_user_id,
                    'channel_token_set' => ! empty(config('services.line.channel_access_token')),
                ]);
            }
        }
    }

    private function handleMessage(array $event): void
    {
        $message = $event['message'] ?? [];
        $messageType = $message['type'] ?? '';
        if ($messageType !== 'text') {
            Log::info('LineWebhook: テキスト以外のメッセージはスキップ', ['type' => $messageType]);
            $this->logTickTickLine([
                'skipped' => 'not_text',
                'message_type' => $messageType,
            ]);

            return;
        }

        $replyToken = $event['replyToken'] ?? '';
        $userMessage = trim($message['text'] ?? '');
        $userId = $event['source']['userId'] ?? '';

        if ($userMessage === '') {
            Log::info('LineWebhook: 空メッセージのためスキップ');
            $this->logTickTickLine(['skipped' => 'empty_text']);

            return;
        }

        if ($userId === '') {
            Log::warning('LineWebhook: source.userId なしのためスキップ');
            $this->logTickTickLine(['skipped' => 'no_line_user_id']);

            return;
        }

        $lineUserId = 'line_'.$userId;
        $user = $this->resolveLineUserForMessagingUserId($userId);

        $tickTickConnection = TickTickConnection::where('user_id', $user->id)->first()
            ?? TickTickConnection::where('identifier', $lineUserId)->first();

        if ($tickTickConnection && $tickTickConnection->user_id === null) {
            $tickTickConnection->update(['user_id' => $user->id]);
        }

        $this->logTickTickLine([
            'ticktick_connected' => $tickTickConnection !== null,
            'users_id' => $user->id,
            'ticktick_connection_id' => $tickTickConnection?->id,
        ]);

        // 未連携時は LLM（ChatService）を使わない。文言が毎回変わったり「ログイン」と言い換えたりするのを防ぐ。
        if (! $tickTickConnection) {
            $cacheIdentifier = $lineUserId;
            $url = url('/ticktick/connect?for='.urlencode($lineUserId));
            $response = "TickTick と連携していません。予定・タスクの確認や追加には、下のリンクから認証を完了してください。\n\n{$url}\n\n完了後に同じ内容を送り直してください。";

            try {
                $this->chatService->rememberConversation($userMessage, $response, $user->id);
                $this->saveConversationHistory($cacheIdentifier, $userMessage, $response);
            } catch (\Throwable $e) {
                report($e);
            }

            $replyOk = $this->lineService->reply($replyToken, $response);

            if (! $replyOk) {
                Log::error('LineWebhook: Reply API 失敗（未連携・定型文）', [
                    'user_id' => $userId,
                ]);
            }

            return;
        }

        $cacheIdentifier = $tickTickConnection->identifier;
        $conversationHistory = $this->getConversationHistory($cacheIdentifier);
        $conversationHistory = collect($conversationHistory)->take(-10)->values()->all();

        try {
            $response = $this->agentService->run(
                $userMessage,
                $conversationHistory,
                $tickTickConnection,
                $user->id
            );

            $this->saveConversationHistory($cacheIdentifier, $userMessage, $response);
        } catch (\Throwable $e) {
            report($e);
            $response = config('app.debug')
                ? 'エラー: '.$e->getMessage()
                : '申し訳ございません。エラーが発生しました。しばらくしてから再度お試しください。';
        }

        Log::info('LineWebhook: reply_preview', [
            'ticktick_connection_id' => $tickTickConnection->id,
            'preview' => mb_substr($response, 0, 200),
        ]);

        $replyOk = $this->lineService->reply($replyToken, $response);

        if (! $replyOk) {
            Log::error('LineWebhook: Reply API 失敗', [
                'user_id' => $userId,
                'channel_token_set' => ! empty(config('services.line.channel_access_token')),
            ]);
        }
    }

    /**
     * TickTick 連携の有無（またはスキップ理由）を1行で記録する。
     *
     * @param  array<string, mixed>  $payload
     */
    private function logTickTickLine(array $payload): void
    {
        Log::info('LineWebhook: TickTick', $payload);
    }

    /**
     * Messaging API の userId（例: U から始まる文字列）に対応する User を返す。
     * DB に line_ 無しのレガシー値だけある場合は line_ 付きに正規化し、別ユーザーを増やさない。
     */
    private function resolveLineUserForMessagingUserId(string $messagingUserId): User
    {
        $canonical = 'line_'.$messagingUserId;

        $user = User::where('line_user_id', $canonical)->first();
        if ($user !== null) {
            return $user;
        }

        $legacy = User::where('line_user_id', $messagingUserId)->first();
        if ($legacy !== null) {
            $legacy->update(['line_user_id' => $canonical]);
            Log::info('LineWebhook: line_user_id を line_ 形式に正規化', [
                'users_id' => $legacy->id,
                'from' => $messagingUserId,
                'to' => $canonical,
            ]);

            return $legacy->fresh();
        }

        return User::firstOrCreate(
            ['line_user_id' => $canonical],
            ['name' => 'LINE User']
        );
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

}
