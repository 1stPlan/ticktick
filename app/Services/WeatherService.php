<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Open-Meteo（無料・APIキー不要）でジオコーディングと日次予報を取得する。
 *
 * 注意: ジオコーディング API は「山口県」など日本語の県名全文でヒットしないことがあるため、
 * 都道府県は英語名で検索する（Open-Meteo の仕様）。
 *
 * @see https://open-meteo.com/
 */
class WeatherService
{
    /**
     * 日本の都道府県（正式名）→ Open-Meteo 検索用英語名
     *
     * @var array<string, string>
     */
    private const PREFECTURE_JA_TO_EN = [
        '北海道' => 'Hokkaido',
        '青森県' => 'Aomori',
        '岩手県' => 'Iwate',
        '宮城県' => 'Miyagi',
        '秋田県' => 'Akita',
        '山形県' => 'Yamagata',
        '福島県' => 'Fukushima',
        '茨城県' => 'Ibaraki',
        '栃木県' => 'Tochigi',
        '群馬県' => 'Gunma',
        '埼玉県' => 'Saitama',
        '千葉県' => 'Chiba',
        '東京都' => 'Tokyo',
        '神奈川県' => 'Kanagawa',
        '新潟県' => 'Niigata',
        '富山県' => 'Toyama',
        '石川県' => 'Ishikawa',
        '福井県' => 'Fukui',
        '山梨県' => 'Yamanashi',
        '長野県' => 'Nagano',
        '岐阜県' => 'Gifu',
        '静岡県' => 'Shizuoka',
        '愛知県' => 'Aichi',
        '三重県' => 'Mie',
        '滋賀県' => 'Shiga',
        '京都府' => 'Kyoto',
        '大阪府' => 'Osaka',
        '兵庫県' => 'Hyogo',
        '奈良県' => 'Nara',
        '和歌山県' => 'Wakayama',
        '鳥取県' => 'Tottori',
        '島根県' => 'Shimane',
        '岡山県' => 'Okayama',
        '広島県' => 'Hiroshima',
        '山口県' => 'Yamaguchi',
        '徳島県' => 'Tokushima',
        '香川県' => 'Kagawa',
        '愛媛県' => 'Ehime',
        '高知県' => 'Kochi',
        '福岡県' => 'Fukuoka',
        '佐賀県' => 'Saga',
        '長崎県' => 'Nagasaki',
        '熊本県' => 'Kumamoto',
        '大分県' => 'Oita',
        '宮崎県' => 'Miyazaki',
        '鹿児島県' => 'Kagoshima',
        '沖縄県' => 'Okinawa',
    ];

    /**
     * 略称・県名なし → 正式キー（PREFECTURE_JA_TO_EN のキー）
     *
     * @var array<string, string>
     */
    private const PREFECTURE_ALIAS_TO_JA = [
        '北海道' => '北海道',
        '東京' => '東京都',
        '大阪' => '大阪府',
        '京都' => '京都府',
        '山口' => '山口県',
        '広島' => '広島県',
        '福岡' => '福岡県',
    ];

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
        $prefJa = $this->resolvePrefectureJapaneseKey($query);
        $searchNames = [];
        $displayJa = null;

        if ($prefJa !== null && isset(self::PREFECTURE_JA_TO_EN[$prefJa])) {
            $searchNames[] = self::PREFECTURE_JA_TO_EN[$prefJa];
            $displayJa = $prefJa;
            Log::info('WeatherService: 都道府県を英語名でジオコーディング', ['ja' => $prefJa, 'en' => $searchNames[0]]);
        }

        $searchNames[] = $query;

        foreach (array_unique($searchNames) as $name) {
            if ($name === '') {
                continue;
            }
            $row = $this->geocodeSearch($name);
            if ($row !== null) {
                if ($displayJa !== null) {
                    $row['display_name'] = $displayJa;
                }

                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{latitude: float, longitude: float, display_name: string}|null
     */
    private function geocodeSearch(string $name): ?array
    {
        try {
            $response = Http::timeout(12)->get('https://geocoding-api.open-meteo.com/v1/search', [
                'name' => $name,
                'count' => 8,
                'language' => 'ja',
                'format' => 'json',
                'country' => 'JP',
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
        if ($results === []) {
            return null;
        }

        $picked = $this->pickBestGeocodeResult($results);

        return $this->formatGeocodeRow($picked);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function pickBestGeocodeResult(array $results): array
    {
        foreach ($results as $r) {
            $fc = $r['feature_code'] ?? '';
            if (in_array($fc, ['PPLA', 'PPLC', 'PPLA2', 'PPLA3', 'PPLA4'], true)) {
                return $r;
            }
        }

        foreach ($results as $r) {
            $fc = $r['feature_code'] ?? '';
            if ($fc === 'PPL') {
                return $r;
            }
        }

        return $results[0];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{latitude: float, longitude: float, display_name: string}
     */
    private function formatGeocodeRow(array $r): array
    {
        $name = (string) ($r['name'] ?? '');
        $admin = (string) ($r['admin1'] ?? '');

        $display = $admin !== '' && $name !== '' && ! str_contains($name, $admin)
            ? "{$name}（{$admin}）"
            : ($admin !== '' ? $admin : $name);

        return [
            'latitude' => (float) $r['latitude'],
            'longitude' => (float) $r['longitude'],
            'display_name' => $display,
        ];
    }

    private function resolvePrefectureJapaneseKey(string $query): ?string
    {
        $q = trim($query);
        if (isset(self::PREFECTURE_JA_TO_EN[$q])) {
            return $q;
        }
        if (isset(self::PREFECTURE_ALIAS_TO_JA[$q])) {
            return self::PREFECTURE_ALIAS_TO_JA[$q];
        }
        if (! preg_match('/[都道府県]$/u', $q)) {
            $withKen = $q.'県';
            if (isset(self::PREFECTURE_JA_TO_EN[$withKen])) {
                return $withKen;
            }
        }

        return null;
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
