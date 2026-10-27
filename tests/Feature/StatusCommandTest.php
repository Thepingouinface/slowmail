<?php

use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\Recipient;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->travelTo(paris('2026-10-19 07:00'));
});

function statusCampaign(int $rows = 10, array $settings = [], array $attributes = []): Campaign
{
    $campaign = planningCampaign($settings, $rows, $attributes);
    $campaign->update(['name' => 'Agences']);

    return $campaign;
}

/** @return array{0: int, 1: string} code de sortie et texte affiché */
function runStatus(string $campaign = 'Agences', array $options = []): array
{
    $code = Artisan::call('slowmail:status', ['campagne' => $campaign, ...$options]);

    return [$code, Artisan::output()];
}

it('affiche la campagne, ses réglages en clair et son avancement', function () {
    $campaign = statusCampaign();

    [$code, $sortie] = runStatus();

    expect($code)->toBe(0)
        ->and($sortie)->toContain("Campagne « Agences » (n°{$campaign->id}) · Brouillon")
        ->toContain('4 mails par jour, du lundi au vendredi, entre 9 h 30 et 17 h 30 (Europe/Paris)')
        ->toContain('Avancement : 0 envoyé(s) sur 10')
        ->toContain('En attente : 10');
});

it('désigne la campagne par son identifiant', function () {
    $campaign = statusCampaign();

    expect(runStatus((string) $campaign->id)[0])->toBe(0);
});

it('affiche le pourcentage d\'avancement et le détail par statut', function () {
    $campaign = statusCampaign();

    foreach (Recipient::where('campaign_id', $campaign->id)->orderBy('position')->limit(3)->get() as $row) {
        markSent($row, paris('2026-10-15 10:00'));
    }

    $campaign->recipients()->where('status', 'pending')->orderBy('position')->first()->update(['status' => RecipientStatus::Skipped]);

    [, $sortie] = runStatus();

    expect($sortie)->toContain('Avancement : 3 envoyé(s) sur 10 (30 %)')
        ->toContain('En attente : 6 · Envoyé : 3 · Ignoré : 1');
});

it('affiche le plan des prochains jours et la fin estimée', function () {
    statusCampaign();

    [$code, $sortie] = runStatus();

    expect($code)->toBe(0)
        ->and($sortie)->toContain('Plan d\'envoi')
        ->toContain('lun. 19 oct.')
        ->toContain('mar. 20 oct.')
        ->toContain('mer. 21 oct.')
        ->toContain('p1@domaine1.example')
        ->toContain('p10@domaine10.example')
        ->toContain('Fin estimée : mercredi 21 octobre 2026, au rythme actuel (3 jour(s) d\'envoi, 10 mail(s) à envoyer)');
});

it('affiche les adresses dans l\'ordre d\'envoi', function () {
    statusCampaign();

    [, $sortie] = runStatus();

    $positions = array_map(fn (int $i) => strpos($sortie, "p{$i}@domaine{$i}.example"), range(1, 10));

    expect($positions)->not->toContain(false)->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('affiche les heures exactes du plan déjà tiré', function () {
    $campaign = statusCampaign();
    seededPlanner()->tick($campaign, paris('2026-10-19 07:00'));
    $heures = plannedTimes($campaign);

    [, $sortie] = runStatus();

    foreach ($heures as $heure) {
        expect($sortie)->toContain($heure);
    }

    expect($sortie)->toContain('Planifié : 4');
});

it('marque d\'un tiret les heures qui seront tirées le jour même', function () {
    statusCampaign();

    [, $sortie] = runStatus();

    expect($sortie)->toContain('tirées le jour même')->toContain('—');
});

it('limite les jours affichés avec --days', function () {
    statusCampaign();

    [$code, $sortie] = runStatus(options: ['--days' => 1]);

    expect($code)->toBe(0)
        ->and($sortie)->toContain('p4@domaine4.example')
        ->not->toContain('p5@domaine5.example')
        ->toContain('et 2 autre(s) jour(s) d\'envoi')
        ->toContain('Fin estimée');
});

it('dit qu\'il n\'y a rien à prévoir pour une campagne vide', function () {
    campaign('Agences');

    [$code, $sortie] = runStatus();

    expect($code)->toBe(0)
        ->and($sortie)->toContain('Avancement : 0 envoyé(s) sur 0')->toContain('Aucun envoi à prévoir.');
});

it('annonce que la date de fin arrêtera la campagne', function () {
    statusCampaign(20, attributes: ['end_at' => paris('2026-10-21 12:00')->utc()]);

    [, $sortie] = runStatus();

    expect($sortie)->toContain('la date de fin arrêtera la campagne : 8 ligne(s) ne seront pas contactées');
});

it('annonce une fin au-delà de l\'horizon quand la campagne est très longue', function () {
    $campaign = statusCampaign(1);
    $campaign->recipients()->delete();

    $now = now();
    $lignes = [];

    foreach (range(1, 1700) as $i) {
        $lignes[] = [
            'campaign_id' => $campaign->id, 'position' => $i, 'email' => "m{$i}@d{$i}.example", 'domain' => "d{$i}.example",
            'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    foreach (array_chunk($lignes, 200) as $paquet) {
        Recipient::insert($paquet);
    }

    [$code, $sortie] = runStatus(options: ['--days' => 3]);

    expect($code)->toBe(0)->and($sortie)->toContain('Fin estimée : au-delà de l\'horizon de prévision');
});

it('refuse d\'afficher un plan avec des réglages invalides, et le dit', function () {
    statusCampaign(settings: ['daily_max' => 0, 'timezone' => 'Mars/Olympus']);

    [$code, $sortie] = runStatus();

    expect($code)->toBe(1)->and($sortie)->toContain('entre 1 et 100')->toContain('inconnu')->not->toContain('Plan d\'envoi');
});

it('échoue sur une campagne inconnue', function () {
    [$code, $sortie] = runStatus('Fantôme');

    expect($code)->toBe(1)->and($sortie)->toContain('Campagne introuvable');
});

it('échoue quand deux campagnes portent le même nom', function () {
    campaign('Doublon');
    campaign('Doublon');

    [$code, $sortie] = runStatus('Doublon');

    expect($code)->toBe(1)->and($sortie)->toContain('Plusieurs campagnes');
});

it('n\'écrit rien en base', function () {
    $campaign = statusCampaign();

    runStatus();

    expect($campaign->fresh()->planned_for)->toBeNull()
        ->and(Recipient::where('campaign_id', $campaign->id)->where('status', 'planned')->count())->toBe(0);
});
