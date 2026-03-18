<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LINE Messaging API クライアント
 *
 * @see https://developers.line.biz/ja/reference/messaging-api/
 */
class LineService
{
    private const REPLY_URL = 'https://api.line.me/v2/bot/message/reply';

    private const PROFILE_URL = 'https://api.line.me/v2/bot/profile/%s';

    /**
     * ユーザープロフィールを取得（displayName, pictureUrl, statusMessage）
     * 取得できない場合は null
     *
     * @return array{displayName: string, pictureUrl?: string, statusMessage?: string}|null
     */
    public function getProfile(string $userId): ?array
    {
        $token = config('services.line.channel_access_token');
        if (empty($token)) {
            return null;
        }

        $response = Http::withToken($token)->get(sprintf(self::PROFILE_URL, $userId));

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return [
            'displayName' => $data['displayName'] ?? '',
            'pictureUrl' => $data['pictureUrl'] ?? null,
            'statusMessage' => $data['statusMessage'] ?? null,
        ];
    }

    public function verifySignature(string $body, string $signature): bool
    {
        $channelSecret = config('services.line.channel_secret');
        if (empty($channelSecret)) {
            return false;
        }

        $hash = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));

        return hash_equals($hash, $signature);
    }

    /**
     * テキストメッセージを返信
     */
    public function reply(string $replyToken, string $text): bool
    {
        $token = config('services.line.channel_access_token');
        if (empty($token)) {
            return false;
        }

        $response = Http::withToken($token)
            ->post(self::REPLY_URL, [
                'replyToken' => $replyToken,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $this->truncateForLine($text),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('LineService: Reply API 失敗', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->successful();
    }

    /**
     * LINE のテキスト上限（5000文字）に収める
     */
    private function truncateForLine(string $text): string
    {
        if (mb_strlen($text) <= 5000) {
            return $text;
        }

        return mb_substr($text, 0, 4997).'...';
    }
}
