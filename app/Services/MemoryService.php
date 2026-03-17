<?php

namespace App\Services;

use App\Models\Memory;
use Illuminate\Support\Collection;
use OpenAI;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;

/**
 * 記憶の保存・検索を行うサービス（RAG機能のコア）
 *
 * OpenAI Embedding API でテキストをベクトル化し、
 * Supabase (PostgreSQL + pgvector) に保存・検索します。
 */
class MemoryService
{
    private const EMBEDDING_DIMENSIONS = 1536;

    private readonly string $embeddingModel;

    public function __construct(
        ?string $embeddingModel = null
    ) {
        $this->embeddingModel = $embeddingModel ?? config('services.openai.embedding_model', 'text-embedding-3-small');
    }

    /**
     * テキストをベクトル化して記憶として保存する
     *
     * @param  string  $content  記憶する内容（会話、メモ、事実など）
     * @param  int|null  $userId  ユーザーID（マルチテナント対応）
     * @param  array<string, mixed>  $metadata  追加のメタデータ（日付、ソース、タイプなど）
     */
    public function remember(string $content, ?int $userId = null, array $metadata = []): Memory
    {
        $embedding = $this->createEmbedding($content);

        return Memory::create([
            'user_id' => $userId,
            'content' => $content,
            'embedding' => new Vector($embedding),
            'metadata' => $metadata,
        ]);
    }

    /**
     * 質問に関連する過去の記憶を検索する（RAG用）
     *
     * @param  string  $query  検索クエリ（ユーザーの質問など）
     * @param  int|null  $userId  ユーザーID
     * @param  int  $limit  取得件数
     * @return Collection<int, Memory>
     */
    public function recall(string $query, ?int $userId = null, int $limit = 5): Collection
    {
        $embedding = $this->createEmbedding($query);

        $queryBuilder = Memory::query()
            ->nearestNeighbors('embedding', $embedding, Distance::Cosine)
            ->limit($limit);

        if ($userId !== null) {
            $queryBuilder->where('user_id', $userId);
        }

        return $queryBuilder->get();
    }

    /**
     * 関連する記憶をプロンプト用の文字列にフォーマットする
     *
     * @param  Collection<int, Memory>  $memories
     */
    public function formatMemoriesForPrompt(Collection $memories): string
    {
        if ($memories->isEmpty()) {
            return '';
        }

        $lines = $memories->map(function (Memory $memory, int $i) {
            $meta = $memory->metadata ? ' [' . json_encode($memory->metadata, JSON_UNESCAPED_UNICODE) . ']' : '';

            return ($i + 1) . '. ' . $memory->content . $meta;
        });

        return "【過去の関連する記憶】\n" . $lines->implode("\n");
    }

    /**
     * OpenAI Embedding API でテキストをベクトル化
     *
     * @return array<int, float>
     */
    private function createEmbedding(string $text): array
    {
        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY が設定されていません。.env を確認してください。');
        }

        $client = OpenAI::client($apiKey, config('services.openai.organization'));

        $response = $client->embeddings()->create([
            'model' => $this->embeddingModel,
            'input' => mb_substr($text, 0, 8000), // API制限対策
        ]);

        $embedding = $response->embeddings[0]->embedding;

        return is_array($embedding) ? $embedding : iterator_to_array($embedding);
    }
}
