<?php

namespace App\Http\Controllers;

use App\Models\TickTickConnection;
use App\Services\TickTickService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TickTickController extends Controller
{
    public function __construct(
        private readonly TickTickService $tickTickService
    ) {}

    /**
     * TickTick OAuth 認証へリダイレクト
     * ?for=line_{userId} で LINE ユーザー向け連携
     */
    public function redirect(Request $request): RedirectResponse
    {
        $for = $request->query('for');
        $nonce = Str::random(40);

        if ($for && str_starts_with($for, 'line_')) {
            Cache::put("ticktick_oauth_{$nonce}", $for, now()->addMinutes(10));
            $state = base64_encode(json_encode(['for' => $for, 'n' => $nonce]));
        } else {
            $request->session()->put('ticktick_oauth_state', $nonce);
            $state = $nonce;
        }

        return redirect()->away($this->tickTickService->getAuthorizationUrl($state));
    }

    /**
     * OAuth コールバック
     */
    public function callback(Request $request): RedirectResponse
    {
        $stateParam = $request->query('state');
        $identifier = null;

        if (str_contains($stateParam ?? '', 'eyJ')) {
            $decoded = json_decode(base64_decode($stateParam), true);
            if (is_array($decoded) && isset($decoded['for'], $decoded['n'])) {
                $nonce = $decoded['n'];
                $for = Cache::pull("ticktick_oauth_{$nonce}");
                if ($for === $decoded['for']) {
                    $identifier = $for;
                }
            }
        } else {
            $sessionState = $request->session()->pull('ticktick_oauth_state');
            if ($sessionState && $sessionState === $stateParam) {
                $identifier = $this->getIdentifier($request);
            }
        }

        if (! $identifier) {
            return redirect('/')->with('error', 'Invalid state parameter');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect('/')->with('error', 'Authorization code missing');
        }

        try {
            $tokens = $this->tickTickService->exchangeCode($code);
        } catch (\Throwable $e) {
            report($e);

            return redirect('/')->with('error', 'TickTick 連携に失敗しました: '.$e->getMessage());
        }

        TickTickConnection::updateOrCreate(
            ['identifier' => $identifier],
            [
                'user_id' => null,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at' => $tokens['expires_at'] ?? null,
            ]
        );

        $successMessage = 'TickTick と連携しました！タスクの追加や確認ができます。';
        if (str_starts_with($identifier, 'line_')) {
            return redirect()->route('ticktick.line-success')->with('success', $successMessage);
        }

        return redirect('/')->with('success', $successMessage);
    }

    /**
     * 連携を解除
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $identifier = $this->getIdentifier($request);
        TickTickConnection::where('identifier', $identifier)->delete();

        return redirect('/')->with('success', 'TickTick の連携を解除しました');
    }

    private function getIdentifier(Request $request): string
    {
        return 'session_'.$request->session()->getId();
    }
}
