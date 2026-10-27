<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Services\Planning\CampaignSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    public const DEFAULT_TIMEZONE = 'Europe/Paris';

    protected $fillable = [
        'name', 'subject', 'body', 'settings', 'columns', 'status', 'start_at', 'end_at', 'planned_for',
        'consecutive_failures', 'pause_reason', 'finished_at',
    ];

    protected $attributes = [
        'status' => 'draft',
        'consecutive_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'settings' => 'array',
            'columns' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'planned_for' => 'date:Y-m-d',
            'finished_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SendLog::class);
    }

    /** Fuseau de la plage horaire et de [DATE] ; Europe/Paris si celui des réglages est inconnu. */
    public function timezone(): string
    {
        $timezone = CampaignSettings::for($this)->timezone;

        return CampaignSettings::isValidTimezone($timezone) ? $timezone : self::DEFAULT_TIMEZONE;
    }
}
