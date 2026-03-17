<?php

namespace App\Models;

use App\Services\TickTickService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TickTickTaskCache extends Model
{
    protected $table = 'ticktick_task_cache';

    protected $fillable = [
        'ticktick_connection_id',
        'ticktick_task_id',
        'title',
        'project_id',
        'project_name',
        'due_date',
        'start_date',
        'content',
        'status',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TickTickConnection::class, 'ticktick_connection_id');
    }

    /**
     * キャッシュを配列形式（getTasks と同形式）に変換
     */
    public function toTaskArray(): array
    {
        return [
            'id' => $this->ticktick_task_id,
            'title' => $this->title,
            'content' => $this->content,
            'projectId' => $this->project_id ?? '',
            'projectName' => $this->project_name ?? TickTickService::INBOX_DISPLAY_NAME,
            'dueDate' => $this->due_date,
            'startDate' => $this->start_date,
            'status' => $this->status,
        ];
    }
}
