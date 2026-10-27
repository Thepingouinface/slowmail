<?php

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Planning\CampaignSettings;

/**
 * « Aucun identifiant SMTP dans le code, les logs ou l'interface » (§14) et « jamais de corps de mail dans
 * l'interface ou les journaux » : une erreur ancienne qui aurait gardé un identifiant, ou un rendu stocké, ne doit
 * apparaître sur aucun écran.
 */
beforeEach(function () {
    sendingEnvironment();
    config([
        'mail.mailers.smtp.username' => 'compte-ovh-secret',
        'mail.mailers.smtp.password' => 'mot-de-passe-smtp-secret',
    ]);
    $this->travelTo(paris('2026-10-21 10:00:00'));
});

function secretyCampaign(): Campaign
{
    $campaign = sendingCampaign(attributes: ['status' => 'paused', 'pause_reason' => 'Disjoncteur : compte-ovh-secret / mot-de-passe-smtp-secret']);

    foreach ([RecipientStatus::Failed, RecipientStatus::Skipped, RecipientStatus::Bounced, RecipientStatus::Uncertain, RecipientStatus::Sent] as $i => $status) {
        recipient($campaign, "secret{$i}@exemple.test", $status)->forceFill([
            'last_error' => 'Authentification refusée pour compte-ovh-secret avec mot-de-passe-smtp-secret',
            'rendered_subject' => 'Un objet',
            'rendered_body' => 'CORPS-SECRET-QUI-NE-DOIT-JAMAIS-APPARAITRE',
            'sending_started_at' => now(),
            'sent_at' => now(),
        ])->save();
    }

    logEntry($campaign, 'failed', 'Échec avec compte-ovh-secret et mot-de-passe-smtp-secret');

    return $campaign;
}

it('n\'affiche l\'identifiant SMTP ni le corps d\'un mail sur aucun écran', function () {
    $campaign = secretyCampaign();

    $pages = [
        '/campaigns',
        "/campaigns/{$campaign->id}",
        "/campaigns/{$campaign->id}?status=uncertain",
        "/campaigns/{$campaign->id}/template",
        "/campaigns/{$campaign->id}/settings",
        "/campaigns/{$campaign->id}/journal",
        '/suppressions',
    ];

    foreach ($pages as $page) {
        $response = $this->withSession(authenticated())->get($page)->assertOk();

        $response->assertDontSee('compte-ovh-secret')->assertDontSee('mot-de-passe-smtp-secret')->assertDontSee('CORPS-SECRET-QUI-NE-DOIT-JAMAIS-APPARAITRE');
    }
});

it('n\'écrit jamais l\'identifiant SMTP dans le journal en utilisant les écrans', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    $this->withSession(authenticated())->post("/campaigns/{$campaign->id}/activate");
    $this->put("/campaigns/{$campaign->id}/settings", settingsForm(['daily_max' => 2]));
    $this->post("/campaigns/{$campaign->id}/pause");

    foreach (SendLog::query()->pluck('detail') as $detail) {
        expect($detail)->not->toContain('compte-ovh-secret')->not->toContain('mot-de-passe-smtp-secret');
    }
});

it('ne laisse pas le mot de passe de l\'interface s\'afficher ni s\'enregistrer', function () {
    $campaign = sendingCampaign();

    $this->withSession(authenticated())->get("/campaigns/{$campaign->id}/settings")->assertDontSee(config('slowmail.password'));
    expect(CampaignSettings::for($campaign)->toArray())->not->toContain(config('slowmail.password'));
});

it('a besoin d\'une ligne pour qu\'on parle de rendu : le test ne passe pas à vide', function () {
    $campaign = secretyCampaign();

    expect(Recipient::where('campaign_id', $campaign->id)->whereNotNull('rendered_body')->count())->toBe(5);
});
