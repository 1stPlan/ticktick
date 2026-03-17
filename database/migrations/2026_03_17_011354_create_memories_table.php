<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->vector('embedding', 1536); // text-embedding-3-small の次元数
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // HNSW インデックス（Supabase推奨・高速検索用）
        DB::statement('CREATE INDEX memories_embedding_idx ON memories USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
