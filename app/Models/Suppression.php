<?php

namespace App\Models;

use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use Illuminate\Database\Eloquent\Model;

class Suppression extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['value', 'type', 'reason'];

    protected function casts(): array
    {
        return [
            'type' => SuppressionType::class,
            'reason' => SuppressionReason::class,
        ];
    }
}
