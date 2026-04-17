<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticktick_connections', function (Blueprint $table) {
            $table->dropColumn('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('ticktick_connections', function (Blueprint $table) {
            $table->string('session_id')->nullable()->after('identifier')->comment('Web セッションID（廃止）');
        });
    }
};
