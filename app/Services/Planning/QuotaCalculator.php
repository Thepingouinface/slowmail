<?php

namespace App\Services\Planning;

final class QuotaCalculator
{
    /**
     * Le nombre de mails permis ce jour-là.
     *
     * La montée progressive compte les jours où des mails sont réellement partis, pas les jours
     * du calendrier : une semaine d'absence ne fait sauter aucun palier.
     *
     * @param  int  $activeDaysBefore  jours (avant celui-ci) où au moins un mail est parti
     */
    public function quotaFor(CampaignSettings $settings, int $activeDaysBefore): int
    {
        if (! $settings->rampUp) {
            return $settings->dailyMax;
        }

        $steps = intdiv($activeDaysBefore, max(1, $settings->rampEveryDays));

        return min($settings->dailyMax, $settings->rampStart + $steps * $settings->rampStep);
    }
}
