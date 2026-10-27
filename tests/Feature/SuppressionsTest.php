<?php

use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Services\Sending\Suppressions;
use Illuminate\Support\Facades\Artisan;

function suppressions(): Suppressions
{
    return app(Suppressions::class);
}

// --- Vérification -------------------------------------------------------------------------------

it('reconnaît une adresse supprimée, sans tenir compte de la casse ni des espaces', function () {
    Suppression::create(['value' => 'marie@agence.example', 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Bounce]);

    expect(suppressions()->matches('marie@agence.example'))->toBe(SuppressionReason::Bounce)
        ->and(suppressions()->matches('  Marie@Agence.Example '))->toBe(SuppressionReason::Bounce)
        ->and(suppressions()->matches('paul@agence.example'))->toBeNull();
});

it('reconnaît un domaine entier supprimé, mais pas ses sous-domaines ni un domaine voisin', function () {
    Suppression::create(['value' => 'agence.example', 'type' => SuppressionType::Domain, 'reason' => SuppressionReason::Manual]);

    expect(suppressions()->matches('marie@agence.example'))->toBe(SuppressionReason::Manual)
        ->and(suppressions()->matches('PAUL@AGENCE.EXAMPLE'))->toBe(SuppressionReason::Manual)
        ->and(suppressions()->matches('marie@mail.agence.example'))->toBeNull()
        ->and(suppressions()->matches('marie@autre-agence.example'))->toBeNull()
        ->and(suppressions()->matches('marie@agence.example.org'))->toBeNull();
});

it('ne confond pas une adresse et un domaine de même valeur', function () {
    Suppression::create(['value' => 'agence.example', 'type' => SuppressionType::Email, 'reason' => SuppressionReason::Stop]);

    expect(suppressions()->matches('marie@agence.example'))->toBeNull();
});

// --- Ajout --------------------------------------------------------------------------------------

it('ajoute une adresse en la normalisant', function () {
    $result = suppressions()->add('  Marie@Agence.Example ', SuppressionReason::Stop);

    expect($result->created)->toBeTrue()
        ->and($result->suppression->value)->toBe('marie@agence.example')
        ->and($result->suppression->type)->toBe(SuppressionType::Email)
        ->and($result->suppression->reason)->toBe(SuppressionReason::Stop);
});

it('ajoute un domaine entier avec @', function () {
    $result = suppressions()->add('@Agence.Example', SuppressionReason::Manual);

    expect($result->suppression->type)->toBe(SuppressionType::Domain)
        ->and($result->suppression->value)->toBe('agence.example');
});

it('ne duplique pas et garde le premier motif', function () {
    suppressions()->add('marie@agence.example', SuppressionReason::Bounce);
    $second = suppressions()->add('MARIE@agence.example', SuppressionReason::Stop);

    expect($second->created)->toBeFalse()
        ->and($second->suppression->reason)->toBe(SuppressionReason::Bounce)
        ->and(Suppression::count())->toBe(1);
});

it('refuse ce qui n\'est ni une adresse ni un domaine', function () {
    foreach (['jean', '@', '@exemple', '@-exemple.fr', 'jean@@exemple.fr', 'élodie@exemple.fr', 'jean@', '', 'a b@exemple.fr'] as $invalide) {
        expect(fn () => suppressions()->add($invalide))->toThrow(InvalidArgumentException::class);
    }

    expect(Suppression::count())->toBe(0);
});

// --- Effet immédiat sur les lignes --------------------------------------------------------------

it('retire tout de suite de l\'envoi les lignes en attente ou planifiées de cette adresse, dans toutes les campagnes', function () {
    $a = sendingCampaign();
    $b = sendingCampaign();
    $planned = dueRow($a);
    $pendingElsewhere = Recipient::where('campaign_id', $b->id)->where('email', 'p1@domaine1.example')->sole();

    $result = suppressions()->add('p1@domaine1.example', SuppressionReason::Stop);

    expect($result->affected)->toBe(2)
        ->and($planned->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and($planned->fresh()->planned_at)->toBeNull()
        ->and($pendingElsewhere->fresh()->status)->toBe(RecipientStatus::Suppressed)
        ->and(journalOf($a))->toContain('suppressed')
        ->and(Recipient::where('email', 'p2@domaine2.example')->where('status', 'pending')->count())->toBe(2);
});

it('retire de l\'envoi tout un domaine', function () {
    $campaign = campaign();
    recipient($campaign, 'a@agence.example');
    recipient($campaign, 'b@agence.example');
    recipient($campaign, 'c@autre.example');

    $result = suppressions()->add('@agence.example');

    expect($result->affected)->toBe(2)
        ->and(Recipient::where('campaign_id', $campaign->id)->where('status', 'suppressed')->count())->toBe(2)
        ->and(Recipient::where('email', 'c@autre.example')->value('status'))->toBe(RecipientStatus::Pending);
});

it('ne touche pas aux lignes déjà parties, échouées ou en cours', function () {
    $campaign = campaign();

    foreach ([RecipientStatus::Sent, RecipientStatus::Failed, RecipientStatus::Sending, RecipientStatus::Uncertain, RecipientStatus::Bounced, RecipientStatus::Skipped, RecipientStatus::DryRun] as $i => $status) {
        recipient($campaign, "x{$i}@agence.example", $status);
    }

    $result = suppressions()->add('@agence.example');

    expect($result->affected)->toBe(0)->and(Recipient::where('status', 'suppressed')->count())->toBe(0);
});

it('applique aussi une adresse déjà dans la liste : relancer la commande rattrape les lignes arrivées depuis', function () {
    suppressions()->add('marie@agence.example');
    $campaign = campaign();
    $late = recipient($campaign, 'marie@agence.example');

    $again = suppressions()->add('marie@agence.example');

    expect($again->created)->toBeFalse()->and($again->affected)->toBe(1)->and($late->fresh()->status)->toBe(RecipientStatus::Suppressed);
});

// --- Commande -----------------------------------------------------------------------------------

function runSuppress(string $value, array $options = []): array
{
    $code = Artisan::call('slowmail:suppress', ['valeur' => $value, ...$options]);

    return [$code, Artisan::output()];
}

it('slowmail:suppress ajoute une adresse avec le motif par défaut « stop »', function () {
    [$code, $output] = runSuppress('marie@agence.example');

    expect($code)->toBe(0)
        ->and($output)->toContain('marie@agence.example ajouté à la liste de suppression (stop)')
        ->and(Suppression::sole()->reason)->toBe(SuppressionReason::Stop);
});

it('slowmail:suppress ajoute un domaine avec un motif', function () {
    [$code, $output] = runSuppress('@agence.example', ['--reason' => 'manuel']);

    expect($code)->toBe(0)
        ->and($output)->toContain('@agence.example ajouté')
        ->and(Suppression::sole())->type->toBe(SuppressionType::Domain)->reason->toBe(SuppressionReason::Manual);
});

it('slowmail:suppress dit combien de lignes sortent de l\'envoi', function () {
    $campaign = campaign();
    recipient($campaign, 'a@agence.example');
    recipient($campaign, 'b@agence.example');

    [, $output] = runSuppress('@agence.example');

    expect($output)->toContain('2 ligne(s) en attente ou planifiée(s) retirée(s) de l\'envoi');
});

it('slowmail:suppress dit quand la valeur y est déjà', function () {
    runSuppress('marie@agence.example');

    [$code, $output] = runSuppress('marie@agence.example');

    expect($code)->toBe(0)->and($output)->toContain('est déjà dans la liste de suppression')->and(Suppression::count())->toBe(1);
});

it('slowmail:suppress refuse une valeur invalide ou un motif inconnu', function () {
    [$code, $output] = runSuppress('pas une adresse');
    expect($code)->toBe(1)->and($output)->toContain('ni une adresse valide ni un domaine');

    [$code, $output] = runSuppress('marie@agence.example', ['--reason' => 'caprice']);
    expect($code)->toBe(1)->and($output)->toContain('Motif inconnu')->and(Suppression::count())->toBe(0);
});
