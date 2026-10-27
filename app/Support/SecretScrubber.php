<?php

namespace App\Support;

/**
 * Masque les identifiants connus dans un texte destiné à l'interface ou aux journaux.
 * Une erreur SMTP peut citer l'identifiant de connexion : il ne doit jamais s'afficher.
 */
final class SecretScrubber
{
    private const MINIMUM_LENGTH = 4;

    public static function scrub(string $text): string
    {
        $secrets = [];

        foreach ([
            config('mail.mailers.smtp.password'),
            config('mail.mailers.smtp.username'),
            config('mail.mailers.smtp.url'),
            config('slowmail.password'),
            config('app.key'),
        ] as $secret) {
            if (! is_string($secret) || strlen($secret) < self::MINIMUM_LENGTH) {
                continue;
            }

            $secrets[] = $secret;
            $secrets[] = rawurlencode($secret);
            $secrets[] = urlencode($secret);

            if (str_starts_with($secret, 'base64:')) {
                $secrets[] = substr($secret, 7);
            }
        }

        // Les plus longs d'abord : un identifiant ne doit pas être entamé par un plus court.
        $secrets = array_unique($secrets);
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $secrets === [] ? $text : str_replace($secrets, '***', $text);
    }
}
