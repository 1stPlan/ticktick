<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('line_user_id')->nullable()->unique()->comment('LINE  userId');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->vector('embedding', 1536);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE INDEX memories_embedding_idx ON memories USING hnsw (embedding vector_cosine_ops)');

        Schema::create('ticktick_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete()->comment('LINE の場合は users.id（users.line_user_id で紐づく）');
            $table->string('identifier')->unique()->comment('line_xxx（LINE）または session_xxx（Web）');
            $table->string('session_id')->nullable()->comment('Web セッションID');
            $table->string('default_project_id')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticktick_task_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticktick_connection_id')->constrained()->cascadeOnDelete();
            $table->string('ticktick_task_id');
            $table->string('title');
            $table->string('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->string('due_date')->nullable();
            $table->string('start_date')->nullable();
            $table->text('content')->nullable();
            $table->string('status')->default('未完了');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['ticktick_connection_id', 'ticktick_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticktick_task_cache');
        Schema::dropIfExists('ticktick_connections');
        Schema::dropIfExists('memories');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('users');
    }
};
