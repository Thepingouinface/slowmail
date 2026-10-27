<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;

/** Quels jours, et dans quelle plage, une campagne peut envoyer. Tout en heure locale de la campagne. */
final class SendCalendar
{
    public function __construct(private readonly FrenchHolidays $holidays) {}

    /** @param  CarbonImmutable  $localDay  un jour dans le fuseau de la campagne */
    public function isSendingDay(CampaignSettings $settings, CarbonImmutable $localDay): bool
    {
        if (! in_array($localDay->dayOfWeekIso, $settings->days, true)) {
            return false;
        }

        return ! ($settings->skipHolidays && $this->holidays->isHoliday($localDay));
    }

    /**
     * La plage d'envoi de ce jour, resserrée par la date de début et la date de fin.
     * Null : pas d'envoi ce jour-là.
     *
     * @return ?array{0: CarbonImmutable, 1: CarbonImmutable} début et fin, dans le fuseau de la campagne
     */
    public function window(CampaignSettings $settings, CarbonImmutable $localDay): ?array
    {
        if (! $this->isSendingDay($settings, $localDay)) {
            return null;
        }

        $from = CampaignSettings::minutes($settings->windowStart);
        $to = CampaignSettings::minutes($settings->windowEnd);

        if ($from === null || $to === null) {
            return null;
        }

        $start = $localDay->setTime(intdiv($from, 60), $from % 60);
        $end = $localDay->setTime(intdiv($to, 60), $to % 60);

        if ($settings->startAt !== null && $settings->startAt > $start) {
            $start = $settings->startAt->setTimezone($settings->timezone);
        }

        if ($settings->endAt !== null && $settings->endAt < $end) {
            $end = $settings->endAt->setTimezone($settings->timezone);
        }

        return $end > $start ? [$start, $end] : null;
    }

    /** Le prochain début de plage strictement après $after, ou null s'il n'y en a plus (fin de campagne). */
    public function nextWindowStart(CampaignSettings $settings, CarbonImmutable $after, int $limitDays = 400): ?CarbonImmutable
    {
        $day = $after->setTimezone($settings->timezone)->startOfDay();

        for ($i = 0; $i <= $limitDays; $i++) {
            $window = $this->window($settings, $day);

            if ($window !== null && $window[0] > $after) {
                return $window[0];
            }

            $day = $day->addDay()->startOfDay();
        }

        return null;
    }
}
