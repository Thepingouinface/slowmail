<?php

namespace App\Services\Sending;

use App\Enums\RecipientStatus;
use App\Models\Recipient;
use App\Support\Utc;
use Carbon\CarbonImmutable;

/**
 * Une ligne `sending` depuis trop longtemps (la machine s'est arrêtée en plein envoi) passe en `uncertain` :
 * on ne sait pas si le mail est parti, donc on ne le renvoie jamais seul. L'utilisateur vérifie dans ses envoyés.
 */
final class SendingRecovery
{
    /** @return int nombre de lignes passées en `uncertain` */
    public function sweep(CarbonImmutable $now): int
    {
        $limit = Utc::sql($now->subMinutes((int) config('slowmail.sending.sending_timeout_minutes')));

        $stuck = Recipient::query()
            ->where('status', RecipientStatus::Sending->value)
            ->where(fn ($query) => $query->whereNull('sending_started_at')->orWhere('sending_started_at', '<', $limit))
            ->get(['id', 'campaign_id']);

        $swept = 0;

        foreach ($stuck as $row) {
            $changed = Recipient::query()
                ->whereKey($row->id)
                ->where('status', RecipientStatus::Sending->value)
                ->update([
                    'status' => RecipientStatus::Uncertain->value,
                    'last_error' => 'Envoi interrompu : on ne sait pas si le mail est parti. À vérifier dans vos envoyés.',
                ]);

            if ($changed > 0) {
                $swept++;
                Journal::record($row->campaign_id, 'uncertain', 'Envoi interrompu depuis plus de '.config('slowmail.sending.sending_timeout_minutes').' min : à vérifier dans vos envoyés.', $row->id);
            }
        }

        return $swept;
    }
}
