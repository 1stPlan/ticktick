<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TickTickConnection extends Model
{
    protected $table = 'ticktick_connections';

    protected $fillable = [
        'user_id',
        'identifier',
        'access_token',
        'refresh_token',
        'expires_at',
        'default_project_id',
        'session_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taskCache(): HasMany
    {
        return $this->hasMany(TickTickTaskCache::class, 'ticktick_connection_id');
    }

    public function needsRefresh(): bool
    {
        if (! $this->expires_at) {
            return false;
        }

        return $this->expires_at->subMinutes(5)->isPast();
    }
}
