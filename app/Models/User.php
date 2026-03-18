<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public function tickTickConnections(): HasMany
    {
        return $this->hasMany(TickTickConnection::class);
    }

    protected $fillable = [
        'name',
        'line_user_id',
    ];

    public function getAuthPassword(): string
    {
        return '';
    }
}
