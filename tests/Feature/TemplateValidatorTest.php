<?php

use App\Services\Template\TemplateValidation;
use App\Services\Template\TemplateValidator;

function checkTemplate(string $subject, string $body, array $columns = ['PRENOM', 'STRUCTURE']): TemplateValidation
{
    return app(TemplateValidator::class)->check($subject, $body, $columns);
}

const VALID_BODY = "Bonjour [PRENOM],\n\nCordialement.\nPour ne plus recevoir de mails, répondez STOP.";

it('valide un modèle correct, activable', function () {
    $validation = checkTemplate('Pour [STRUCTURE|votre structure]', VALID_BODY);

    expect($validation->errors)->toBe([])
        ->and($validation->blockers)->toBe([])
        ->and($validation->canRender())->toBeTrue()
        ->and($validation->canActivate())->toBeTrue();
});

it('liste les variables inconnues avec les colonnes disponibles', function () {
    $validation = checkTemplate('Pour [Ville]', "Bonjour [PRENOM] [VILLE] [ SIRET ]\nSTOP");

    expect($validation->unknownVariables)->toBe(['Ville', 'SIRET'])
        ->and($validation->canRender())->toBeFalse()
        ->and($validation->errors)->toBe([
            'Variables inconnues : [Ville], [SIRET]. Colonnes disponibles : [PRENOM], [STRUCTURE], [DATE], [EMAIL].',
        ]);
});

it('connaît les variables du moteur et les colonnes sans tenir compte de la casse', function () {
    $validation = checkTemplate('[date] [Email]', "[prenom] [ Structure ]\nstop");

    expect($validation->errors)->toBe([])->and($validation->unknownVariables)->toBe([]);
});

it('ne connaît aucune colonne tant que rien n\'est importé', function () {
    $validation = checkTemplate('Pour [PRENOM]', "Bonjour\nstop", []);

    expect($validation->unknownVariables)->toBe(['PRENOM'])
        ->and($validation->errors[0])->toContain('Colonnes disponibles : [DATE], [EMAIL].');
});

it('bloque l\'activation, sans empêcher le rendu, quand la mention de désinscription manque', function () {
    $validation = checkTemplate('Bonjour', 'Bonjour [PRENOM], à bientôt.');

    expect($validation->canRender())->toBeTrue()
        ->and($validation->canActivate())->toBeFalse()
        ->and($validation->blockers)->toHaveCount(1)
        ->and($validation->blockers[0])->toContain('« stop »')->toContain('ne peut pas être activée');
});

it('cherche la mention de désinscription sans tenir compte de la casse', function () {
    foreach (['Répondez STOP', 'répondez Stop svp', 'stop'] as $mention) {
        expect(checkTemplate('Objet', "Bonjour\n{$mention}")->canActivate())->toBeTrue();
    }
});

it('ne compte pas une valeur par défaut ni un nom de variable comme mention de désinscription', function () {
    expect(checkTemplate('Objet', 'Bonjour [PRENOM|stop]')->canActivate())->toBeFalse()
        ->and(checkTemplate('Objet', 'Bonjour [STOP]', ['STOP'])->canActivate())->toBeFalse();
});

it('compte un crochet échappé comme du texte fixe', function () {
    expect(checkTemplate('Objet', 'Bonjour \[stop\]')->canActivate())->toBeTrue();
});

it('utilise la mention configurée', function () {
    config(['slowmail.optout_text' => 'désinscription']);

    expect(checkTemplate('Objet', "Bonjour\nstop")->canActivate())->toBeFalse()
        ->and(checkTemplate('Objet', "Bonjour\nPour votre DÉSINSCRIPTION, répondez.")->canActivate())->toBeTrue()
        ->and(checkTemplate('Objet', "Bonjour\nstop")->blockers[0])->toContain('« désinscription »');
});

it('retombe sur « stop » quand la mention configurée est vide', function () {
    config(['slowmail.optout_text' => '  ']);

    expect(checkTemplate('Objet', 'Bonjour')->canActivate())->toBeFalse()
        ->and(checkTemplate('Objet', "Bonjour\nstop")->canActivate())->toBeTrue();
});

it('signale un objet ou un corps vide, sans réclamer en plus la désinscription', function () {
    $validation = checkTemplate('  ', '');

    expect($validation->errors)->toBe(['L\'objet est vide.', 'Le corps est vide.'])
        ->and($validation->blockers)->toBe([]);
});

it('refuse un objet sur plusieurs lignes', function () {
    expect(checkTemplate("Premier\nSecond", VALID_BODY)->errors)->toBe(['L\'objet doit tenir sur une seule ligne.']);
});

it('rapporte les erreurs de syntaxe avec leur ligne', function () {
    $validation = checkTemplate('Pour [PRENOM', "Bonjour\n[STRUCTURE\nstop");

    expect($validation->errors)->toHaveCount(2)
        ->and($validation->errors[0])->toStartWith('Objet : crochet ouvert jamais fermé')
        ->and($validation->errors[1])->toStartWith('Corps, ligne 2 : crochet ouvert jamais fermé');
});

it('prévient quand une colonne du CSV est masquée par [DATE] ou [EMAIL]', function () {
    $validation = checkTemplate('Objet', VALID_BODY, ['PRENOM', 'Date', 'EMAIL']);

    expect($validation->warnings)->toHaveCount(2)
        ->and($validation->warnings[0])->toContain('« Date »')->toContain('[DATE]')
        ->and($validation->warnings[1])->toContain('« EMAIL »')
        ->and($validation->canActivate())->toBeTrue();
});

it('valide le modèle enregistré d\'une campagne avec ses colonnes mémorisées', function () {
    $campaign = campaign();
    $campaign->update(['subject' => 'Pour [STRUCTURE]', 'body' => "Bonjour [PRENOM] [VILLE]\nstop", 'columns' => ['PRENOM', 'STRUCTURE']]);

    $validation = app(TemplateValidator::class)->validate($campaign);

    expect($validation->unknownVariables)->toBe(['VILLE']);
});

it('valide une campagne vide sans planter', function () {
    expect(app(TemplateValidator::class)->validate(campaign())->canRender())->toBeFalse();
});
