<?php

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Sending\CampaignLifecycle;

beforeEach(function () {
    sendingEnvironment();
    $this->travelTo(paris('2026-10-19 09:00'));
});

function lifecycle(): CampaignLifecycle
{
    return app(CampaignLifecycle::class);
}

function draftCampaign(array $attributes = [], array $settings = [], int $rows = 3): Campaign
{
    return sendingCampaign($settings, $rows, ['status' => 'draft', ...$attributes]);
}

// --- Activer ------------------------------------------------------------------------------------

it('active une campagne en brouillon prête à partir', function () {
    $campaign = draftCampaign();
    $campaign->update(['consecutive_failures' => 2, 'pause_reason' => 'ancienne pause']);

    expect(lifecycle()->activate($campaign))->toBe([]);

    $campaign = $campaign->fresh();

    expect($campaign->status)->toBe(CampaignStatus::Active)
        ->and($campaign->consecutive_failures)->toBe(0)
        ->and($campaign->pause_reason)->toBeNull()
        ->and($campaign->planned_for)->toBeNull()
        ->and(journalOf($campaign))->toBe(['activated']);
});

it('refuse d\'activer une campagne dont le corps ne contient pas la mention de désinscription', function () {
    $campaign = draftCampaign(['body' => "Bonjour [PRENOM|vous],\n\nÀ bientôt."]);

    $problems = lifecycle()->activate($campaign);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('mention de désinscription')->toContain('« stop »')
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Draft)
        ->and(journalOf($campaign))->toBe([]);
});

it('utilise la mention de désinscription configurée', function () {
    config(['slowmail.optout_text' => 'désinscription']);
    $campaign = draftCampaign();

    expect(lifecycle()->activate($campaign)[0])->toContain('« désinscription »');
});

it('refuse d\'activer avec un modèle vide ou une variable inconnue, et les dit toutes', function () {
    $campaign = draftCampaign(['subject' => '', 'body' => 'Bonjour [VILLE]']);

    $problems = implode(' ', lifecycle()->activate($campaign));

    expect($problems)->toContain('L\'objet est vide')->toContain('[VILLE]')->toContain('désinscription')
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('refuse d\'activer avec des réglages invalides', function () {
    $campaign = draftCampaign(settings: ['daily_max' => 0, 'timezone' => 'Mars/Olympus']);

    $problems = implode(' ', lifecycle()->activate($campaign));

    expect($problems)->toContain('entre 1 et 100')->toContain('inconnu')
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('refuse d\'activer une campagne sans destinataire à contacter', function () {
    $campaign = draftCampaign(rows: 0);

    expect(lifecycle()->activate($campaign))->toContain('Aucun destinataire à contacter : importez un CSV.');

    $withSent = draftCampaign(rows: 1);
    markSent(Recipient::where('campaign_id', $withSent->id)->first(), paris('2026-10-16 10:00'));

    expect(lifecycle()->activate($withSent))->toContain('Aucun destinataire à contacter : importez un CSV.');
});

it('refuse d\'activer sans adresse d\'expédition', function () {
    config(['mail.from.address' => null]);

    expect(implode(' ', lifecycle()->activate(draftCampaign())))->toContain('MAIL_FROM_ADDRESS');
});

it('active même quand le kill switch est coupé : c\'est lui qui retient les envois', function () {
    config(['slowmail.enabled' => false]);

    expect(lifecycle()->activate(draftCampaign()))->toBe([]);
});

it('n\'active que les brouillons', function () {
    expect(lifecycle()->activate(draftCampaign(['status' => 'active'])))->toBe(['La campagne est déjà active.'])
        ->and(lifecycle()->activate(draftCampaign(['status' => 'paused'])))->toBe(['La campagne est en pause : utilisez « Reprendre ».'])
        ->and(lifecycle()->activate(draftCampaign(['status' => 'finished'])))->toBe(['La campagne est terminée.']);
});

// --- Pause et reprise ---------------------------------------------------------------------------

it('met une campagne active en pause, avec la raison', function () {
    $campaign = sendingCampaign();

    expect(lifecycle()->pause($campaign, 'Pour vérifier le modèle.'))->toBeTrue();

    $campaign = $campaign->fresh();

    expect($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->pause_reason)->toBe('Pour vérifier le modèle.')
        ->and(journalOf($campaign))->toBe(['paused']);
});

it('ne met en pause qu\'une campagne active', function () {
    foreach (['draft', 'paused', 'finished'] as $status) {
        $campaign = sendingCampaign(attributes: ['status' => $status]);

        expect(lifecycle()->pause($campaign, 'x'))->toBeFalse()
            ->and($campaign->fresh()->status->value)->toBe($status)
            ->and(journalOf($campaign))->toBe([]);
    }
});

it('garde les créneaux planifiés d\'une campagne en pause', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign, '2026-10-19 10:00:13');

    lifecycle()->pause($campaign);

    expect($row->fresh()->status->value)->toBe('planned');
});

it('reprend une campagne en pause et remet le disjoncteur à zéro', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'paused', 'consecutive_failures' => 3, 'pause_reason' => 'Disjoncteur']);
    Campaign::query()->whereKey($campaign->id)->update(['planned_for' => '2026-10-19']);

    expect(lifecycle()->resume($campaign->fresh()))->toBe([]);

    $campaign = $campaign->fresh();

    expect($campaign->status)->toBe(CampaignStatus::Active)
        ->and($campaign->consecutive_failures)->toBe(0)
        ->and($campaign->pause_reason)->toBeNull()
        ->and($campaign->planned_for)->toBeNull()
        ->and(journalOf($campaign))->toBe(['resumed']);
});

it('ne reprend qu\'une campagne en pause', function () {
    foreach (['draft', 'active', 'finished'] as $status) {
        expect(lifecycle()->resume(sendingCampaign(attributes: ['status' => $status])))->toBe(['Seule une campagne en pause peut être reprise.']);
    }
});

it('refuse de reprendre une campagne dont le modèle n\'est plus valide', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'paused', 'body' => 'Bonjour sans mention']);

    $problems = lifecycle()->resume($campaign);

    expect(implode(' ', $problems))->toContain('désinscription')
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Paused);
});

// --- Terminer -----------------------------------------------------------------------------------

it('termine une campagne active ou en pause', function () {
    foreach (['active', 'paused'] as $status) {
        $campaign = sendingCampaign(attributes: ['status' => $status, 'pause_reason' => 'x']);

        expect(lifecycle()->finish($campaign, 'Plus aucun destinataire à contacter.'))->toBeTrue();

        $campaign = $campaign->fresh();

        expect($campaign->status)->toBe(CampaignStatus::Finished)
            ->and($campaign->finished_at->equalTo(paris('2026-10-19 09:00')))->toBeTrue()
            ->and($campaign->pause_reason)->toBeNull()
            ->and(SendLogDetail($campaign, 'finished'))->toBe('Plus aucun destinataire à contacter.');
    }
});

it('ne termine pas un brouillon', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft']);

    expect(lifecycle()->finish($campaign, 'x'))->toBeFalse()->and($campaign->fresh()->finished_at)->toBeNull();
});

function SendLogDetail(Campaign $campaign, string $event): ?string
{
    return SendLog::where('campaign_id', $campaign->id)->where('event', $event)->value('detail');
}
