<?php

use App\Models\Campaign;
use App\Services\Template\ComposedMessage;
use App\Services\Template\MessageComposer;
use Carbon\CarbonImmutable;

function composeFor(Campaign $campaign, array $data = [], string $email = 'marie@lumiere.example', ?CarbonImmutable $at = null): ComposedMessage
{
    return app(MessageComposer::class)->compose($campaign, recipient($campaign, $email, data: $data), $at);
}

function templated(string $subject, string $body, array $settings = []): Campaign
{
    $campaign = campaign();
    $campaign->update(['subject' => $subject, 'body' => $body, 'settings' => $settings ?: null]);

    return $campaign;
}

it('rend l\'objet et le corps pour un destinataire', function () {
    $campaign = templated('Pour [STRUCTURE|votre structure]', "Bonjour [PRENOM],\n\nÉcrit à [EMAIL].");

    $message = composeFor($campaign, ['PRENOM' => 'Marie', 'STRUCTURE' => 'Agence Lumière']);

    expect($message->subject)->toBe('Pour Agence Lumière')
        ->and($message->body)->toBe("Bonjour Marie,\n\nÉcrit à marie@lumiere.example.")
        ->and($message->isReady())->toBeTrue();
});

it('utilise le jour de l\'envoi donné, dans le fuseau de la campagne', function () {
    $at = CarbonImmutable::parse('2026-10-21 23:30', 'UTC');

    expect(composeFor(templated('[DATE]', 'x'), at: $at)->subject)->toBe('22 octobre 2026')
        ->and(composeFor(templated('[DATE]', 'x', ['timezone' => 'UTC']), at: $at)->subject)->toBe('21 octobre 2026')
        ->and(composeFor(templated('[DATE]', 'x', ['timezone' => 'Pacific/Auckland']), at: CarbonImmutable::parse('2026-10-21 12:00', 'UTC'))->subject)->toBe('22 octobre 2026');
});

it('prend la date du jour quand aucune n\'est donnée', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 08:00', 'Europe/Paris'));

    expect(composeFor(templated('[DATE]', 'x'))->subject)->toBe('4 mars 2026');
});

it('prend Europe/Paris quand la campagne n\'a pas de fuseau', function () {
    expect(campaign()->timezone())->toBe('Europe/Paris')
        ->and(templated('x', 'x', ['timezone' => 'Asia/Tokyo'])->timezone())->toBe('Asia/Tokyo');
});

it('signale les variables vides sans valeur par défaut, dans l\'objet comme dans le corps', function () {
    $message = composeFor(templated('Pour [STRUCTURE]', 'Bonjour [PRENOM] de [structure] à [VILLE|Paris]'), ['PRENOM' => '']);

    expect($message->isReady())->toBeFalse()
        ->and($message->missing)->toBe(['STRUCTURE', 'PRENOM'])
        ->and($message->problems)->toBe([]);
});

it('ne plante pas pour un destinataire sans données', function () {
    $campaign = templated('Pour [STRUCTURE|vous]', 'Bonjour');
    $recipient = recipient($campaign, 'x@y.example');
    $recipient->update(['data' => null]);

    expect(app(MessageComposer::class)->compose($campaign, $recipient->fresh())->subject)->toBe('Pour vous');
});

it('refuse proprement un modèle invalide ou vide', function () {
    foreach ([['Pour [X', 'Bonjour'], ['Objet', 'Bonjour [X'], ['', 'Bonjour'], ['Objet', '  ']] as [$subject, $body]) {
        $message = composeFor(templated($subject, $body), ['X' => '1']);

        expect($message->isReady())->toBeFalse()
            ->and($message->problems)->toHaveCount(1)
            ->and($message->subject)->toBe('')
            ->and($message->body)->toBe('');
    }
});

it('empêche une cellule du CSV d\'injecter un en-tête dans l\'objet', function () {
    $message = composeFor(templated('Pour [NOM]', 'Bonjour'), ['NOM' => "Durand\r\nBcc: autre@x.example\n\tsuite\x00fin"]);

    expect($message->subject)->toBe('Pour Durand Bcc: autre@x.example suite fin')
        ->and($message->subject)->not->toMatch('/[\r\n\x00]/');
});

it('normalise les fins de ligne du corps en « \n »', function () {
    $message = composeFor(templated('Objet', "Ligne 1\r\n[NOTE]\rfin"), ['NOTE' => "a\r\nb"]);

    expect($message->body)->toBe("Ligne 1\na\nb\nfin");
});

it('ne relit pas une cellule comme du modèle', function () {
    $message = composeFor(templated('[NOTE]', '[NOTE]'), ['NOTE' => '[EMAIL] [PRENOM]'], 'vrai@x.example');

    expect($message->subject)->toBe('[EMAIL] [PRENOM]')->and($message->body)->toBe('[EMAIL] [PRENOM]');
});
