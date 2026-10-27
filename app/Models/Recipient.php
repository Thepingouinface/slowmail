<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use App\Enums\ReplyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipient extends Model
{
    protected $fillable = [
        'campaign_id', 'position', 'email', 'domain', 'data', 'status', 'planned_at',
        'sending_started_at', 'sent_at', 'attempts', 'last_error', 'message_id',
        'reply_status', 'notes', 'rendered_subject', 'rendered_body',
    ];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'status' => RecipientStatus::class,
            'reply_status' => ReplyStatus::class,
            'planned_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SendLog::class);
    }
}
