<?php

namespace App\Services\Planning;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Les options de planification d'une campagne (§5 de la spec).
 *
 * Stockées dans `campaigns.settings` (JSON, clés en snake_case) ; `start_at` et `end_at` sont
 * des colonnes de la campagne. La lecture est tolérante (une valeur absente prend son défaut),
 * la validation est dans violations() : à appeler avant d'enregistrer.
 */
final class CampaignSettings
{
    public const MAX_DAILY = 100;

    private const DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /** @param  list<int>  $days  jours autorisés, ISO : 1 = lundi … 7 = dimanche */
    public function __construct(
        public readonly int $dailyMax = 4,
        public readonly string $windowStart = '09:30',
        public readonly string $windowEnd = '17:30',
        public readonly string $timezone = Campaign::DEFAULT_TIMEZONE,
        public readonly array $days = [1, 2, 3, 4, 5],
        public readonly bool $skipHolidays = true,
        public readonly int $minGapMinutes = 25,
        public readonly bool $jitter = true,
        public readonly ?CarbonImmutable $startAt = null,
        public readonly ?CarbonImmutable $endAt = null,
        public readonly ?int $maxTotal = null,
        public readonly bool $rampUp = false,
        public readonly int $rampStart = 2,
        public readonly int $rampStep = 1,
        public readonly int $rampEveryDays = 3,
        public readonly int $domainCooldownDays = 7,
        public readonly bool $dryRun = false,
    ) {}

    public static function for(Campaign $campaign): self
    {
        return self::fromArray($campaign->settings ?? [], $campaign->start_at, $campaign->end_at);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data, ?CarbonInterface $startAt = null, ?CarbonInterface $endAt = null): self
    {
        $default = new self;

        return new self(
            dailyMax: self::int($data, 'daily_max', $default->dailyMax),
            windowStart: self::string($data, 'window_start', $default->windowStart),
            windowEnd: self::string($data, 'window_end', $default->windowEnd),
            timezone: self::string($data, 'timezone', $default->timezone),
            days: self::days($data['days'] ?? null, $default->days),
            skipHolidays: self::bool($data, 'skip_holidays', $default->skipHolidays),
            minGapMinutes: self::int($data, 'min_gap_minutes', $default->minGapMinutes),
            jitter: self::bool($data, 'jitter', $default->jitter),
            startAt: $startAt === null ? null : CarbonImmutable::instance($startAt)->utc(),
            endAt: $endAt === null ? null : CarbonImmutable::instance($endAt)->utc(),
            maxTotal: isset($data['max_total']) && is_numeric($data['max_total']) ? (int) $data['max_total'] : null,
            rampUp: self::bool($data, 'ramp_up', $default->rampUp),
            rampStart: self::int($data, 'ramp_start', $default->rampStart),
            rampStep: self::int($data, 'ramp_step', $default->rampStep),
            rampEveryDays: self::int($data, 'ramp_every_days', $default->rampEveryDays),
            domainCooldownDays: self::int($data, 'domain_cooldown_days', $default->domainCooldownDays),
            dryRun: self::bool($data, 'dry_run', $default->dryRun),
        );
    }

    /** Forme stockée dans `campaigns.settings` (sans start_at ni end_at, qui sont des colonnes). */
    public function toArray(): array
    {
        return [
            'daily_max' => $this->dailyMax,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'timezone' => $this->timezone,
            'days' => $this->days,
            'skip_holidays' => $this->skipHolidays,
            'min_gap_minutes' => $this->minGapMinutes,
            'jitter' => $this->jitter,
            'max_total' => $this->maxTotal,
            'ramp_up' => $this->rampUp,
            'ramp_start' => $this->rampStart,
            'ramp_step' => $this->rampStep,
            'ramp_every_days' => $this->rampEveryDays,
            'domain_cooldown_days' => $this->domainCooldownDays,
            'dry_run' => $this->dryRun,
        ];
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public function gapSeconds(): int
    {
        return $this->minGapMinutes * 60;
    }

    /** Minutes depuis minuit de « HH:MM », ou null si le format est invalide. */
    public static function minutes(string $time): ?int
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) === 1 ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    /** Durée de la plage complète, en secondes (0 si elle est invalide). */
    public function windowSeconds(): int
    {
        $start = self::minutes($this->windowStart);
        $end = self::minutes($this->windowEnd);

        return $start === null || $end === null || $end <= $start ? 0 : ($end - $start) * 60;
    }

    /**
     * Ce qui rend ces réglages inutilisables, en phrases. Vide : ils sont valides.
     * Le planificateur refuse de rien envoyer tant que cette liste n'est pas vide.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $problems = [];

        if ($this->dailyMax < 1 || $this->dailyMax > self::MAX_DAILY) {
            $problems[] = 'Le nombre maximum de mails par jour doit être compris entre 1 et '.self::MAX_DAILY.'.';
        }

        $start = self::minutes($this->windowStart);
        $end = self::minutes($this->windowEnd);

        if ($start === null || $end === null) {
            $problems[] = 'La plage horaire doit être écrite au format HH:MM (par exemple 09:30).';
        } elseif ($end <= $start) {
            $problems[] = 'La fin de la plage horaire doit être après son début.';
        }

        if (! self::isValidTimezone($this->timezone)) {
            $problems[] = "Le fuseau horaire « {$this->timezone} » est inconnu.";
        }

        if ($this->days === []) {
            $problems[] = 'Choisissez au moins un jour d\'envoi.';
        }

        if ($this->minGapMinutes < 0 || $this->minGapMinutes > 720) {
            $problems[] = 'L\'écart minimum entre deux mails doit être compris entre 0 et 720 minutes.';
        }

        if ($this->maxTotal !== null && $this->maxTotal < 1) {
            $problems[] = 'Le nombre total de mails doit être d\'au moins 1.';
        }

        if ($this->rampUp && ($this->rampStart < 1 || $this->rampStep < 0 || $this->rampEveryDays < 1)) {
            $problems[] = 'La montée progressive demande un départ d\'au moins 1 mail, un palier positif ou nul, et un intervalle d\'au moins 1 jour.';
        }

        if ($this->domainCooldownDays < 0 || $this->domainCooldownDays > 365) {
            $problems[] = 'Le délai entre deux mails à un même domaine doit être compris entre 0 et 365 jours.';
        }

        if ($this->startAt !== null && $this->endAt !== null && $this->endAt <= $this->startAt) {
            $problems[] = 'La date de fin doit être après la date de début.';
        }

        $window = $this->windowSeconds();

        if ($window > 0 && $this->dailyMax >= 1 && $this->dailyMax <= self::MAX_DAILY && SlotDrawer::capacity($window, $this->gapSeconds()) < $this->dailyMax) {
            $problems[] = sprintf(
                'La plage de %s ne permet que %d mail(s) espacés de %d minutes : baissez le maximum par jour ou l\'écart.',
                $this->hours($window),
                SlotDrawer::capacity($window, $this->gapSeconds()),
                $this->minGapMinutes,
            );
        }

        return $problems;
    }

    /** Le récapitulatif en clair des réglages. */
    public function summary(): string
    {
        $parts = [
            $this->dailyMax === 1 ? '1 mail par jour' : "{$this->dailyMax} mails par jour",
            $this->daysLabel(),
            sprintf('entre %s et %s (%s)', $this->clock($this->windowStart), $this->clock($this->windowEnd), $this->timezone),
        ];

        if ($this->skipHolidays) {
            $parts[] = 'jours fériés exclus';
        }

        if ($this->minGapMinutes > 0) {
            $parts[] = "au moins {$this->minGapMinutes} min entre deux mails";
        }

        if ($this->rampUp) {
            $parts[] = "montée progressive de {$this->rampStart} mail(s) par jour, +{$this->rampStep} tous les {$this->rampEveryDays} jours d'envoi, jusqu'à {$this->dailyMax}";
        }

        if ($this->domainCooldownDays > 0) {
            $parts[] = "jamais deux mails au même domaine à moins de {$this->domainCooldownDays} jours d'écart";
        }

        if ($this->maxTotal !== null) {
            $parts[] = "arrêt après {$this->maxTotal} mails";
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    private function daysLabel(): string
    {
        $days = $this->days;
        sort($days);

        if (count($days) === 7) {
            return 'tous les jours';
        }

        $names = array_map(fn (int $day) => self::DAY_NAMES[$day] ?? (string) $day, $days);

        if (count($days) >= 2 && end($days) - $days[0] + 1 === count($days)) {
            return 'du '.$names[0].' au '.end($names);
        }

        if (count($names) === 1) {
            return 'le '.$names[0];
        }

        return 'les '.implode(', ', array_slice($names, 0, -1)).' et '.end($names);
    }

    /** « 09:30 » devient « 9 h 30 ». */
    private function clock(string $time): string
    {
        $minutes = self::minutes($time);

        if ($minutes === null) {
            return $time;
        }

        return $minutes % 60 === 0 ? intdiv($minutes, 60).' h' : sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function hours(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        return $minutes % 60 === 0 ? intdiv($minutes, 60).' h' : sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @param  array<string, mixed>  $data */
    private static function int(array $data, string $key, int $default): int
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : $default;
    }

    /** @param  array<string, mixed>  $data */
    private static function string(array $data, string $key, string $default): string
    {
        return isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : $default;
    }

    /** @param  array<string, mixed>  $data */
    private static function bool(array $data, string $key, bool $default): bool
    {
        return isset($data[$key]) ? (filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default) : $default;
    }

    /**
     * @param  list<int>  $default
     * @return list<int>
     */
    private static function days(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $days = array_values(array_unique(array_filter(
            array_map(fn ($day) => is_numeric($day) ? (int) $day : 0, $value),
            fn (int $day) => $day >= 1 && $day <= 7,
        )));
        sort($days);

        return $days;
    }
}
