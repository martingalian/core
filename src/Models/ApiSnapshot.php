<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property array $api_response
 * @property string $canonical
 */
class ApiSnapshot extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

    protected $casts = [
        'api_response' => 'array',
    ];

    public function responsable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeWithCanonical($query, string $canonical)
    {
        return $query->where('canonical', $canonical);
    }

    /**
     * Store or update the API snapshot for a given model and canonical key.
     *
     * @param  \Martingalian\Core\Models\Position|\Martingalian\Core\Models\Order|\Martingalian\Core\Models\Account  $model
     */
    public static function storeFor(Model $model, string $canonical, array $payload): self
    {
        /** @var self $snapshot */
        $snapshot = $model->apiSnapshots()->updateOrCreate(
            ['canonical' => $canonical],
            ['api_response' => $payload]
        );

        return $snapshot;
    }

    /**
     * Retrieve the API snapshot response for a given model and canonical key.
     *
     * @param  \Martingalian\Core\Models\Position|\Martingalian\Core\Models\Order|\Martingalian\Core\Models\Account  $model
     */
    public static function getFrom(Model $model, string $canonical): ?array
    {
        /** @var self|null $snapshot */
        $snapshot = $model->apiSnapshots()
            ->where('canonical', $canonical)
            ->latest('id')
            ->first();

        return $snapshot?->api_response;
    }
}
