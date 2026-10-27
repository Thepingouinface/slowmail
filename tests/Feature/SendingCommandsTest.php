<?php

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\SendLog;
use App\Services\Sending\Purger;
use App\Services\Sending\SchedulerState;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => sendingEnvironment());

/** @return array{0: int, 1: string} */
function runCommand(string $command, array $arguments = []): array
{
    $code = Artisan::call($command, $arguments);

    return [$code, Artisan::output()];
}

// --- Le scheduler -------------------------------------------------------------------------------

it('planifie slowmail:tick chaque minute, sans chevauchement', function () {
    [, $output] = runCommand('schedule:list');

    expect($output)->toContain('* * * * *')->toContain('slowmail:tick');

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'slowmail:tick'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

// --- slowmail:tick ------------------------------------------------------------------------------

it('slowmail:tick envoie le mail dû et le dit sans afficher d\'adresse', function () {
    $campaign = sendingCampaign();
    $row = dueRow($campaign);
    test()->travelTo(paris('2026-10-19 10:01:00'));

    [$code, $output] = runCommand('slowmail:tick');

    expect($code)->toBe(0)
        ->and($output)->toContain("Campagne {$campaign->id}, ligne {$row->id} : envoyé.")
        ->not->toContain('@')
        ->and(sentMail())->toHaveCount(1);
});

it('slowmail:tick dit quand le kill switch est coupé', function () {
    config(['slowmail.enabled' => false]);
    sendingCampaign();

    [$code, $output] = runCommand('slowmail:tick');

    expect($code)->toBe(0)->and($output)->toContain('Envois désactivés (SLOWMAIL_ENABLED=false)');
});

it('slowmail:tick dit quand un autre passage est en cours', function () {
    $lock = Cache::lock('slowmail:tick', 60);
    $lock->get();

    [$code, $output] = runCommand('slowmail:tick');

    expect($code)->toBe(0)->and($output)->toContain('Un autre passage est déjà en cours');

    $lock->release();
});

it('slowmail:tick signale une interruption et les envois interrompus', function () {
    $campaign = sendingCampaign();
    Recipient::where('campaign_id', $campaign->id)->first()->forceFill(['status' => RecipientStatus::Sending, 'sending_started_at' => paris('2026-10-19 09:00')->utc()])->save();
    app(SchedulerState::class)->recordTick(paris('2026-10-19 08:00'));
    test()->travelTo(paris('2026-10-19 14:00'));

    [, $output] = runCommand('slowmail:tick');

    expect($output)->toContain('Interruption détectée (6 h)')->toContain('1 envoi(s) interrompu(s) en plein vol');
});

it('slowmail:tick signale la fin et la pause d\'une campagne', function () {
    $finished = sendingCampaign(rows: 1);
    markSent(Recipient::where('campaign_id', $finished->id)->first(), paris('2026-10-16 10:00'));
    $broken = sendingCampaign(['daily_max' => 0]);
    test()->travelTo(paris('2026-10-19 07:00'));

    [, $output] = runCommand('slowmail:tick');

    expect($output)->toContain("Campagne {$finished->id} terminée")->toContain("Campagne {$broken->id} mise en pause");
});

it('slowmail:tick ne fait pas échouer le scheduler, même quand l\'envoi échoue', function () {
    $campaign = sendingCampaign();
    dueRow($campaign);
    failWith(smtpReply(554, '5.7.1 Relay access denied', 'MAIL FROM:<moi@exemple.test>'));
    test()->travelTo(paris('2026-10-19 10:01:00'));

    [$code, $output] = runCommand('slowmail:tick');

    expect($code)->toBe(0)->and($output)->toContain('échec');
});

// --- slowmail:campaign --------------------------------------------------------------------------

it('slowmail:campaign active une campagne prête', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft', 'name' => 'Agences']);

    [$code, $output] = runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'activate']);

    expect($code)->toBe(0)->and($output)->toContain('Campagne « Agences » activée.')
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('slowmail:campaign refuse d\'activer sans la mention de désinscription, et dit pourquoi', function () {
    $campaign = sendingCampaign(attributes: ['status' => 'draft', 'name' => 'Agences', 'body' => 'Bonjour [PRENOM|vous]']);

    [$code, $output] = runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'activate']);

    expect($code)->toBe(1)
        ->and($output)->toContain('La campagne ne peut pas démarrer')->toContain('mention de désinscription')
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('slowmail:campaign prévient quand le kill switch est coupé', function () {
    config(['slowmail.enabled' => false]);
    sendingCampaign(attributes: ['status' => 'draft', 'name' => 'Agences']);

    [, $output] = runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'activate']);

    expect($output)->toContain('aucun mail ne partira tant que le kill switch est coupé');
});

it('slowmail:campaign met en pause puis reprend', function () {
    $campaign = sendingCampaign(attributes: ['name' => 'Agences']);

    [$code, $output] = runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'pause']);
    expect($code)->toBe(0)->and($output)->toContain('mise en pause')->and($campaign->fresh()->status)->toBe(CampaignStatus::Paused);

    [$code, $output] = runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'resume']);
    expect($code)->toBe(0)->and($output)->toContain('reprise')->and($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

it('slowmail:campaign refuse une pause ou une reprise hors de propos, une action ou une campagne inconnue', function () {
    sendingCampaign(attributes: ['status' => 'draft', 'name' => 'Agences']);

    expect(runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'pause']))->toMatchArray([1])
        ->and(runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'resume'])[0])->toBe(1)
        ->and(runCommand('slowmail:campaign', ['campagne' => 'Agences', 'action' => 'detruire'])[1])->toContain('Action inconnue')
        ->and(runCommand('slowmail:campaign', ['campagne' => 'Fantôme', 'action' => 'activate'])[1])->toContain('Campagne introuvable');
});

// --- slowmail:purge -----------------------------------------------------------------------------

function finishedCampaign(string $finishedAt): Campaign
{
    $campaign = sendingCampaign(attributes: ['status' => 'finished', 'finished_at' => paris($finishedAt)->utc()]);

    foreach (Recipient::where('campaign_id', $campaign->id)->get() as $row) {
        $row->forceFill([
            'status' => RecipientStatus::Sent,
            'sent_at' => paris($finishedAt)->utc(),
            'rendered_subject' => 'Objet',
            'rendered_body' => 'Corps',
            'notes' => 'à garder',
            'reply_status' => 'replied',
        ])->save();
    }

    return $campaign;
}

it('purge les rendus et les données des campagnes terminées depuis plus de N jours, et rien d\'autre', function () {
    test()->travelTo(paris('2026-10-19 10:00'));
    $old = finishedCampaign('2026-05-01 10:00');
    $recent = finishedCampaign('2026-10-01 10:00');
    $active = sendingCampaign();

    $result = app(Purger::class)->purge(90);

    expect($result)->toBe(['campaigns' => 1, 'recipients' => 5])
        ->and(Recipient::where('campaign_id', $old->id)->whereNotNull('data')->count())->toBe(0)
        ->and(Recipient::where('campaign_id', $old->id)->whereNotNull('rendered_body')->count())->toBe(0)
        ->and(Recipient::where('campaign_id', $recent->id)->whereNotNull('data')->count())->toBe(5)
        ->and(Recipient::where('campaign_id', $recent->id)->whereNotNull('rendered_body')->count())->toBe(5)
        ->and(Recipient::where('campaign_id', $active->id)->whereNotNull('data')->count())->toBe(5)
        ->and(journalOf($old))->toContain('purge');
});

it('garde l\'adresse, le statut, les dates et les notes d\'une campagne purgée', function () {
    test()->travelTo(paris('2026-10-19 10:00'));
    $old = finishedCampaign('2026-05-01 10:00');

    app(Purger::class)->purge(90);

    $row = Recipient::where('campaign_id', $old->id)->orderBy('position')->first();

    expect($row->email)->toBe('p1@domaine1.example')
        ->and($row->status)->toBe(RecipientStatus::Sent)
        ->and($row->sent_at)->not->toBeNull()
        ->and($row->notes)->toBe('à garder')
        ->and($row->reply_status->value)->toBe('replied')
        ->and($row->data)->toBeNull();
});

it('ne purge pas une campagne qui n\'est pas terminée, même ancienne', function () {
    test()->travelTo(paris('2026-10-19 10:00'));
    $paused = sendingCampaign(attributes: ['status' => 'paused']);
    $paused->forceFill(['finished_at' => paris('2026-01-01')->utc()])->save();

    expect(app(Purger::class)->purge(90))->toBe(['campaigns' => 0, 'recipients' => 0]);
});

it('purge sans rien refaire la deuxième fois', function () {
    test()->travelTo(paris('2026-10-19 10:00'));
    finishedCampaign('2026-05-01 10:00');

    app(Purger::class)->purge(90);
    $again = app(Purger::class)->purge(90);

    expect($again)->toBe(['campaigns' => 0, 'recipients' => 0])->and(SendLog::where('event', 'purge')->count())->toBe(1);
});

it('slowmail:purge utilise 90 jours par défaut et --days quand on le donne', function () {
    test()->travelTo(paris('2026-10-19 10:00'));
    finishedCampaign('2026-09-01 10:00');

    [$code, $output] = runCommand('slowmail:purge');
    expect($code)->toBe(0)->and($output)->toContain('0 ligne(s) purgée(s) dans 0 campagne(s)')->toContain('90 jour(s)');

    [$code, $output] = runCommand('slowmail:purge', ['--days' => 30]);
    expect($code)->toBe(0)->and($output)->toContain('5 ligne(s) purgée(s) dans 1 campagne(s)')->toContain('30 jour(s)');
});

it('slowmail:purge refuse un nombre de jours invalide', function () {
    foreach (['abc', '-3'] as $days) {
        [$code, $output] = runCommand('slowmail:purge', ['--days' => $days]);

        expect($code)->toBe(1)->and($output)->toContain('--days doit être un nombre de jours');
    }
});
