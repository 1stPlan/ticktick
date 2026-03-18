<?php

namespace App\Http\Controllers;

use App\Models\TickTickConnection;
use App\Services\AgentService;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function __construct(
        private readonly AgentService $agentService,
        private readonly ChatService $chatService
    ) {}

    /**
     * チャット画面を表示
     */
    public function index(Request $request): View
    {
        $sessionId = $request->session()->getId();
        $tickTickConnection = TickTickConnection::where('session_id', $sessionId)
            ->orWhere('identifier', 'session_'.$sessionId)
            ->first();
        $connectUrl = url('/ticktick/connect');

        return view('chat.index', [
            'tickTickConnected' => $tickTickConnection !== null,
            'connectUrl' => $connectUrl,
        ]);
    }

    /**
     * メッセージを送信して応答を取得
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $userMessage = trim($request->input('message'));
        if ($userMessage === '') {
            return response()->json(['error' => 'メッセージを入力してください'], 422);
        }

        $sessionId = $request->session()->getId();
        $tickTickConnection = TickTickConnection::where('session_id', $sessionId)
            ->orWhere('identifier', 'session_'.$sessionId)
            ->first();

        $cacheIdentifier = $tickTickConnection ? $tickTickConnection->identifier : 'session_'.$sessionId;
        $conversationHistory = Cache::get("conv_{$cacheIdentifier}", []);
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

            $history = Cache::get("conv_{$cacheIdentifier}", []);
            $history[] = ['role' => 'user', 'content' => $userMessage];
            $history[] = ['role' => 'assistant', 'content' => $response];
            $history = array_slice($history, -20);
            Cache::put("conv_{$cacheIdentifier}", $history, now()->addHours(24));

            return response()->json(['message' => $response]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => config('app.debug')
                    ? 'エラー: '.$e->getMessage()
                    : '申し訳ございません。エラーが発生しました。',
            ], 500);
        }
    }
}
