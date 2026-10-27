<?php

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;

/** Une ligne dont l'envoi est incertain, comme après un arrêt en plein envoi. */
function uncertainSend(Campaign $campaign, string $email = 'incertain@exemple.test'): Recipient
{
    $row = recipient($campaign, $email, RecipientStatus::Uncertain, ['PRENOM' => 'Inès']);
    $row->forceFill([
        'sending_started_at' => paris('2026-10-19 10:00:13')->utc(),
        'message_id' => 'abc123def456@exemple.test',
        'rendered_subject' => 'Objet du mail incertain',
        'rendered_body' => 'CORPS-SECRET-QUI-NE-DOIT-JAMAIS-APPARAITRE',
        'last_error' => 'Envoi interrompu sans réponse du serveur : à vérifier dans vos envoyés.',
    ])->save();

    return $row->fresh();
}

/** Une entrée du journal, à une heure donnée (UTC en base). */
function logEntry(Campaign $campaign, string $event, string $detail, ?Recipient $recipient = null, ?string $at = null): SendLog
{
    $log = SendLog::create(['campaign_id' => $campaign->id, 'recipient_id' => $recipient?->id, 'event' => $event, 'detail' => $detail]);

    if ($at !== null) {
        SendLog::query()->whereKey($log->id)->update(['created_at' => paris($at)->utc()->format('Y-m-d H:i:s')]);
    }

    return $log;
}

/** Les réglages d'un formulaire valide, que les tests modifient champ par champ. */
function settingsForm(array $overrides = []): array
{
    return array_merge([
        'daily_max' => 4,
        'window_start' => '09:30',
        'window_end' => '17:30',
        'timezone' => 'Europe/Paris',
        'days' => [1, 2, 3, 4, 5],
        'skip_holidays' => '1',
        'min_gap_minutes' => 25,
        'jitter' => '1',
        'domain_cooldown_days' => 7,
        'ramp_start' => 2,
        'ramp_step' => 1,
        'ramp_every_days' => 3,
    ], $overrides);
}

/** Lit le CSV d'un export : en-tête, puis les lignes, avec ou sans le BOM. */
function parseCsv(string $content): array
{
    $content = ltrim($content, "\xEF\xBB\xBF");
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    $rows = [];

    while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}
