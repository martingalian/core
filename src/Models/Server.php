<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Martingalian\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $hostname
 * @property string $ip_address
 * @property string $type
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
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
