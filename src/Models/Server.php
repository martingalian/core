<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;

final class Server extends BaseModel
{
    use HasFactory;

    protected $table = 'servers';

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
}
