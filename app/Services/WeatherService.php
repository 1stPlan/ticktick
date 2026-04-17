<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Open-Meteo（無料・APIキー不要）でジオコーディングと日次予報を取得する。
 *
 * @see https://open-meteo.com/
 */
class WeatherService
{
    /**
     * @return array{success: bool, message: string}
     */
    public function forecastSummary(string $locationQuery, string $when): array
    {
        $locationQuery = trim($locationQuery);
        if ($locationQuery === '') {
            return [
                'success' => false,
                'message' => '地域が指定されていません。',
            ];
        }

        $geo = $this->geocode($locationQuery);
        if ($geo === null) {
            return [
                'success' => false,
                'message' => '場所を特定できませんでした。都道府県や市区町村名で指定してください。',
            ];
        }

        $offset = match ($when) {
            'today' => 0,
            'tomorrow' => 1,
            'day_after_tomorrow' => 2,
            default => 1,
        };

        $daily = $this->fetchDaily($geo['latitude'], $geo['longitude'], max(3, $offset + 1));
        if ($daily === null) {
            return [
                'success' => false,
                'message' => '天気予報の取得に失敗しました。しばらくしてから再度お試しください。',
            ];
        }

        $time = $daily['time'] ?? [];
        $codes = $daily['weathercode'] ?? [];
        $maxT = $daily['temperature_2m_max'] ?? [];
        $minT = $daily['temperature_2m_min'] ?? [];
        $precip = $daily['precipitation_probability_max'] ?? [];

        if (! isset($time[$offset], $codes[$offset])) {
            return [
                'success' => false,
                'message' => '指定した日付の予報を取得できませんでした。',
            ];
        }

        $dateStr = $time[$offset];
        $code = (int) $codes[$offset];
        $max = isset($maxT[$offset]) ? round((float) $maxT[$offset]) : null;
        $min = isset($minT[$offset]) ? round((float) $minT[$offset]) : null;
        $precipP = isset($precip[$offset]) ? (int) round((float) $precip[$offset]) : null;

        $whenLabel = match ($when) {
            'today' => '今日',
            'tomorrow' => '明日',
            'day_after_tomorrow' => '明後日',
            default => '明日',
        };

        $sky = $this->weatherCodeToJapanese($code);
        $place = $geo['display_name'];

        $parts = [
            "{$place}の{$whenLabel}（{$dateStr}）の予報：{$sky}。",
        ];
        if ($max !== null && $min !== null) {
            $parts[] = "最高気温 {$max}℃、最低気温 {$min}℃。";
        }
        if ($precipP !== null) {
            $parts[] = "降水確率の最大 {$precipP}%。";
        }
        $parts[] = '（Open-Meteo 無料 API。実際の天候は気象庁の発表もご確認ください。）';

        return [
            'success' => true,
            'message' => implode('', $parts),
        ];
    }

    /**
     * @return array{latitude: float, longitude: float, display_name: string}|null
     */
    private function geocode(string $query): ?array
    {
        try {
            $response = Http::timeout(12)->get('https://geocoding-api.open-meteo.com/v1/search', [
                'name' => $query,
                'count' => 1,
                'language' => 'ja',
                'format' => 'json',
            ]);
        } catch (\Throwable $e) {
            Log::warning('WeatherService: geocode HTTP failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $results = $data['results'] ?? [];
        if ($results === [] || ! isset($results[0]['latitude'], $results[0]['longitude'])) {
            return null;
        }

        $r = $results[0];
        $name = $r['name'] ?? $query;
        $admin = $r['admin1'] ?? '';

        $display = $admin !== '' && ! str_contains((string) $name, (string) $admin)
            ? "{$name}（{$admin}）"
            : (string) $name;

        return [
            'latitude' => (float) $r['latitude'],
            'longitude' => (float) $r['longitude'],
            'display_name' => $display,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDaily(float $lat, float $lon, int $forecastDays): ?array
    {
        try {
            $response = Http::timeout(15)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $lat,
                'longitude' => $lon,
                'daily' => 'weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
                'timezone' => 'Asia/Tokyo',
                'forecast_days' => min(16, max(1, $forecastDays)),
            ]);
        } catch (\Throwable $e) {
            Log::warning('WeatherService: forecast HTTP failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return $json['daily'] ?? null;
    }

    private function weatherCodeToJapanese(int $code): string
    {
        return match (true) {
            $code === 0 => '快晴',
            $code === 1 => '主に晴れ',
            $code === 2 => 'ときどき曇り',
            $code === 3 => '曇り',
            $code >= 45 && $code <= 48 => '霧',
            $code >= 51 && $code <= 57 => '霧雨',
            $code >= 61 && $code <= 67 => '雨',
            $code >= 71 && $code <= 77 => '雪',
            $code >= 80 && $code <= 82 => 'にわか雨',
            $code >= 85 && $code <= 86 => 'にわか雪',
            $code >= 95 && $code <= 99 => '雷雨の可能性',
            default => '天候コード '.$code,
        };
    }
}
