<?php

namespace App\Support;

use App\Models\Campaign;
use App\Services\Planning\CampaignSettings;
use Carbon\CarbonInterface;

/**
 * Les dates se stockent en UTC ; l'interface les montre en heure locale, en français.
 * Une campagne affiche ses heures dans son fuseau, le reste de l'interface dans SLOWMAIL_TIMEZONE.
 */
final class LocalTime
{
    public static function defaultTimezone(): string
    {
        $configured = (string) config('slowmail.timezone');

        return CampaignSettings::isValidTimezone($configured) ? $configured : Campaign::DEFAULT_TIMEZONE;
    }

    /** « jeu. 22 oct. à 10:12 » */
    public static function dateTime(?CarbonInterface $at, ?string $timezone = null, string $empty = '—'): string
    {
        return self::format($at, $timezone, 'ddd D MMM [à] HH:mm', $empty);
    }

    /** « jeu. 22 oct. à 10:12:05 » */
    public static function dateTimeSeconds(?CarbonInterface $at, ?string $timezone = null, string $empty = '—'): string
    {
        return self::format($at, $timezone, 'ddd D MMM [à] HH:mm:ss', $empty);
    }

    /** « 10:12 » */
    public static function clock(?CarbonInterface $at, ?string $timezone = null, string $empty = '—'): string
    {
        return self::format($at, $timezone, 'HH:mm', $empty);
    }

    /** « 10:12:05 » */
    public static function time(?CarbonInterface $at, ?string $timezone = null, string $empty = '—'): string
    {
        return self::format($at, $timezone, 'HH:mm:ss', $empty);
    }

    /** « jeu. 22 oct. » */
    public static function day(?CarbonInterface $at, ?string $timezone = null, string $empty = '—'): string
    {
        return self::format($at, $timezone, 'ddd D MMM', $empty);
    }

    /** « 22/10/2026 10:12 », pour les listes denses où chaque ligne a sa date. */
    public static function compact(?CarbonInterface $at, ?string $timezone = null, string $empty = '—'): string
    {
        return self::format($at, $timezone, 'DD/MM/YYYY HH:mm', $empty);
    }

    /** Valeur d'un champ `datetime-local` : « 2026-10-22T10:12 ». */
    public static function input(?CarbonInterface $at, string $timezone): string
    {
        return $at === null ? '' : $at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i');
    }

    private static function format(?CarbonInterface $at, ?string $timezone, string $format, string $empty): string
    {
        if ($at === null) {
            return $empty;
        }

        return $at->copy()->setTimezone($timezone ?? self::defaultTimezone())->locale('fr')->isoFormat($format);
    }
}
