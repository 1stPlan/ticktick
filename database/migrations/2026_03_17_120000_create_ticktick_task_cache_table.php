<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ticktick_task_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticktick_connection_id')->constrained()->cascadeOnDelete();
            $table->string('ticktick_task_id')->comment('TickTick API の task id');
            $table->string('title');
            $table->string('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->string('due_date')->nullable();
            $table->string('start_date')->nullable();
            $table->text('content')->nullable();
            $table->string('status')->default('未完了'); // 未完了 or 完了
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['ticktick_connection_id', 'ticktick_task_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticktick_task_cache');
    }
};
