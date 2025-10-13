<?php

namespace Martingalian\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Martingalian\Core\Concerns\User\NotifiesViaPushover;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasDebuggable;
    use HasLoggable;
    use Notifiable;
    use NotifiesViaPushover;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_logged_in_at' => 'datetime',
        'previous_logged_in_at' => 'datetime',

        'can_trade' => 'boolean',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',

        'password' => 'hashed',
    ];

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function positions()
    {
        return $this->hasManyThrough(Position::class, Account::class);
    }

    public function scopeAdmin(Builder $query)
    {
        $query->where('users.is_admin', true);
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }
}
