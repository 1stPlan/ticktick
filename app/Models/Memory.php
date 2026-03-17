<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * ユーザーの記憶を保存するモデル
 *
 * 会話ログ、メモ、事実などをベクトル化して保存し、
 * 意味検索（RAG）で過去のコンテキストを召喚します。
 */
class Memory extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'user_id',
        'content',
        'embedding',
        'metadata',
    ];

    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
