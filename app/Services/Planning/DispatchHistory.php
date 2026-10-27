<?php

namespace App\Services\Planning;

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Ce que la campagne a déjà envoyé, vu par le planificateur.
 *
 * Compte comme « parti » tout mail qui a pu partir : envoyé, simulé (dry_run), en cours d'envoi
 * ou incertain. Dans le doute on compte : un envoi incertain pèse sur le quota, l'écart et le
 * cooldown de domaine. Seuls les envoyés et simulés comptent comme jour d'envoi pour la montée
 * progressive.
 */
final class DispatchHistory
{
    /** @var list<array{at: CarbonImmutable, day: int, ramp: bool}> */
    private array $moments = [];

    /** @var array<string, int> domaine => dernier jour d'envoi (numéro de jour) */
    private array $lastDayByDomain = [];

    private function __construct(private readonly string $timezone) {}

    public static function empty(string $timezone): self
    {
        return new self($timezone);
    }

    public static function load(Campaign $campaign, string $timezone): self
    {
        $history = new self($timezone);

        $rows = Recipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', array_map(
                fn (RecipientStatus $status) => $status->value,
                [RecipientStatus::Sent, RecipientStatus::DryRun, RecipientStatus::Sending, RecipientStatus::Uncertain],
            ))
            ->get(['status', 'domain', 'sent_at', 'sending_started_at', 'updated_at']);

        foreach ($rows as $row) {
            $at = $row->sent_at ?? $row->sending_started_at ?? $row->updated_at;

            $history->add(
                CarbonImmutable::instance($at),
                $row->domain,
                in_array($row->status, [RecipientStatus::Sent, RecipientStatus::DryRun], true),
            );
        }

        return $history;
    }

    /** Numéro de jour d'une date locale : sert à compter des écarts en jours du calendrier. */
    public static function dayNumber(CarbonInterface $local): int
    {
        return intdiv(gmmktime(0, 0, 0, (int) $local->format('n'), (int) $local->format('j'), (int) $local->format('Y')), 86400);
    }

    /** Ajoute un envoi (réel ou projeté). */
    public function add(CarbonImmutable $at, string $domain, bool $ramp = true): void
    {
        $day = self::dayNumber($at->setTimezone($this->timezone));

        $this->moments[] = ['at' => $at, 'day' => $day, 'ramp' => $ramp];
        $this->lastDayByDomain[$domain] = max($this->lastDayByDomain[$domain] ?? $day, $day);
    }

    public function total(): int
    {
        return count($this->moments);
    }

    /** Envois partis ce jour-là (jour local). */
    public function countOn(CarbonImmutable $localDay): int
    {
        return count($this->momentsOn($localDay));
    }

    /** @return list<CarbonImmutable> */
    public function momentsOn(CarbonImmutable $localDay): array
    {
        $day = self::dayNumber($localDay);

        return array_values(array_map(
            fn (array $moment) => $moment['at'],
            array_filter($this->moments, fn (array $moment) => $moment['day'] === $day),
        ));
    }

    /** Nombre de jours, avant celui-ci, où au moins un mail est réellement parti. */
    public function activeDaysBefore(CarbonImmutable $localDay): int
    {
        $day = self::dayNumber($localDay);
        $days = [];

        foreach ($this->moments as $moment) {
            if ($moment['ramp'] && $moment['day'] < $day) {
                $days[$moment['day']] = true;
            }
        }

        return count($days);
    }

    public function lastDispatchAt(): ?CarbonImmutable
    {
        $last = null;

        foreach ($this->moments as $moment) {
            if ($last === null || $moment['at'] > $last) {
                $last = $moment['at'];
            }
        }

        return $last;
    }

    public function lastDayFor(string $domain): ?int
    {
        return $this->lastDayByDomain[$domain] ?? null;
    }
}
