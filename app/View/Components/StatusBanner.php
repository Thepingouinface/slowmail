<?php

namespace App\View\Components;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Services\Sending\Journal;
use App\Services\Sending\SchedulerSnapshot;
use App\Services\Sending\SchedulerStatus;
use App\Support\LocalTime;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Le bandeau d'état de toutes les pages (addendum §6) : le scheduler tourne-t-il, qu'y a-t-il à vérifier.
 * Il ne fait que lire SchedulerStatus::snapshot().
 */
class StatusBanner extends Component
{
    public SchedulerSnapshot $snapshot;

    /** « il y a 3 min », « il y a 2 h 10 min », ou null si le scheduler n'a jamais tourné. */
    public ?string $ago;

    public ?string $lastTick;

    public ?string $nextSend;

    /** Où mène le lien « à vérifier » : la campagne concernée si elle est seule, sinon la liste. */
    public string $uncertainUrl;

    public function __construct(SchedulerStatus $status)
    {
        $this->snapshot = $status->snapshot();

        $minutes = $this->snapshot->minutesSinceLastTick;
        $this->ago = $minutes === null ? null : 'il y a '.Journal::duration($minutes);
        $this->lastTick = $this->snapshot->lastTickAt === null ? null : LocalTime::dateTimeSeconds($this->snapshot->lastTickAt);
        $this->nextSend = $this->snapshot->nextSendAt === null ? null : LocalTime::dateTime($this->snapshot->nextSendAt);
        $this->uncertainUrl = $this->uncertainUrl();
    }

    public function render(): View
    {
        return view('components.status-banner');
    }

    private function uncertainUrl(): string
    {
        if ($this->snapshot->uncertainCount === 0) {
            return route('campaigns.index');
        }

        $ids = Campaign::query()
            ->whereHas('recipients', fn ($query) => $query->where('status', RecipientStatus::Uncertain))
            ->pluck('id');

        return $ids->count() === 1
            ? route('campaigns.show', ['campaign' => $ids->first(), 'status' => RecipientStatus::Uncertain->value])
            : route('campaigns.index');
    }
}
