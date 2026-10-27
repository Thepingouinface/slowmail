<?php

use App\Services\Template\RenderedText;
use App\Services\Template\TemplateParser;
use App\Services\Template\TemplateRenderer;
use Carbon\CarbonImmutable;

function renderTemplate(string $source, array $data = [], string $email = 'destinataire@x.example', ?CarbonImmutable $at = null, string $timezone = 'Europe/Paris'): RenderedText
{
    $at ??= CarbonImmutable::parse('2026-10-21 10:00', 'UTC');

    return (new TemplateRenderer)->render((new TemplateParser)->parse($source), $data, $email, $at, $timezone);
}

it('remplace une variable par la colonne du destinataire', function () {
    expect(renderTemplate('Bonjour [PRENOM],', ['PRENOM' => 'Marie'])->text)->toBe('Bonjour Marie,');
});

it('ne tient pas compte de la casse, des deux côtés', function () {
    expect(renderTemplate('[prenom] [Prenom] [PRENOM]', ['PRENOM' => 'Marie'])->text)->toBe('Marie Marie Marie')
        ->and(renderTemplate('[PRENOM]', ['prenom' => 'Marie'])->text)->toBe('Marie');
});

it('ne tient pas compte des espaces autour du nom', function () {
    expect(renderTemplate('[ PRENOM ]|[PRENOM  ]', ['PRENOM' => 'Marie'])->text)->toBe('Marie|Marie');
});

it('lit les colonnes dont le nom contient des espaces ou des accents', function () {
    $data = ['NOM DE FAMILLE' => 'Durand', 'Prénom' => 'Élodie'];

    expect(renderTemplate('[Nom de famille], [PRÉNOM]', $data)->text)->toBe('Durand, Élodie');
});

it('remplit plusieurs variables sur la même ligne et garde les sauts de ligne', function () {
    $text = renderTemplate("[A][B]\n\n[A] et [B]", ['A' => '1', 'B' => '2'])->text;

    expect($text)->toBe("12\n\n1 et 2");
});

it('utilise la valeur par défaut quand la colonne est vide', function () {
    $rendered = renderTemplate('Chez [STRUCTURE|votre structure]', ['STRUCTURE' => '']);

    expect($rendered->text)->toBe('Chez votre structure')->and($rendered->isComplete())->toBeTrue();
});

it('utilise la valeur par défaut quand la colonne est absente, ou ne contient que des espaces', function () {
    expect(renderTemplate('[X|défaut]', [])->text)->toBe('défaut')
        ->and(renderTemplate('[X|défaut]', ['X' => '   '])->text)->toBe('défaut')
        ->and(renderTemplate('[X|défaut]', ['X' => "\u{00A0}"])->text)->toBe('défaut');
});

it('préfère la valeur de la colonne à la valeur par défaut', function () {
    expect(renderTemplate('[X|défaut]', ['X' => 'réel'])->text)->toBe('réel');
});

it('accepte une valeur par défaut explicitement vide', function () {
    $rendered = renderTemplate('Bonjour[TITRE|]!', ['TITRE' => '']);

    expect($rendered->text)->toBe('Bonjour!')->and($rendered->isComplete())->toBeTrue();
});

it('signale une variable vide sans valeur par défaut', function () {
    $rendered = renderTemplate('Chez [STRUCTURE], à [ville]', ['STRUCTURE' => '', 'VILLE' => 'Lyon']);

    expect($rendered->missing)->toBe(['STRUCTURE'])->and($rendered->isComplete())->toBeFalse();
});

it('signale une colonne absente de la ligne comme vide', function () {
    expect(renderTemplate('[STRUCTURE]', ['NOM' => 'Durand'])->missing)->toBe(['STRUCTURE']);
});

it('signale chaque variable manquante une seule fois, sous le nom écrit dans le modèle', function () {
    $rendered = renderTemplate('[Ville] [VILLE] [ville] [STRUCTURE]', []);

    expect($rendered->missing)->toBe(['Ville', 'STRUCTURE']);
});

it('traite une donnée qui n\'est pas un texte comme vide', function () {
    expect(renderTemplate('[X]', ['X' => ['a']])->missing)->toBe(['X']);
});

it('rend les crochets échappés tels quels, sans les remplacer', function () {
    $rendered = renderTemplate('\[PRENOM\] vaut [PRENOM]', ['PRENOM' => 'Marie']);

    expect($rendered->text)->toBe('[PRENOM] vaut Marie');
});

it('rend [DATE] au format « 21 octobre 2026 »', function () {
    expect(renderTemplate('[DATE]')->text)->toBe('21 octobre 2026')
        ->and(renderTemplate('[date]')->text)->toBe('21 octobre 2026');
});

it('écrit les mois en français, accents compris, et le premier du mois sans « er »', function () {
    $mois = [
        '2026-01-05' => '5 janvier 2026', '2026-02-01' => '1 février 2026', '2026-03-10' => '10 mars 2026',
        '2026-04-30' => '30 avril 2026', '2026-05-12' => '12 mai 2026', '2026-06-18' => '18 juin 2026',
        '2026-07-14' => '14 juillet 2026', '2026-08-03' => '3 août 2026', '2026-09-09' => '9 septembre 2026',
        '2026-11-11' => '11 novembre 2026', '2026-12-25' => '25 décembre 2026',
    ];

    foreach ($mois as $jour => $attendu) {
        expect(renderTemplate('[DATE]', at: CarbonImmutable::parse("{$jour} 12:00", 'Europe/Paris'))->text)->toBe($attendu);
    }
});

it('calcule [DATE] dans le fuseau de la campagne', function () {
    $at = CarbonImmutable::parse('2026-10-21 23:30', 'UTC');

    expect(renderTemplate('[DATE]', at: $at, timezone: 'Europe/Paris')->text)->toBe('22 octobre 2026')
        ->and(renderTemplate('[DATE]', at: $at, timezone: 'UTC')->text)->toBe('21 octobre 2026')
        ->and(renderTemplate('[DATE]', at: $at, timezone: 'America/New_York')->text)->toBe('21 octobre 2026');
});

it('ne modifie pas la date reçue', function () {
    $at = CarbonImmutable::parse('2026-10-21 23:30', 'UTC');

    renderTemplate('[DATE]', at: $at, timezone: 'Europe/Paris');

    expect($at->timezoneName)->toBe('UTC')->and($at->format('Y-m-d H:i'))->toBe('2026-10-21 23:30');
});

it('rend [EMAIL] avec l\'adresse du destinataire', function () {
    expect(renderTemplate('Écrit à [EMAIL] ([email])', email: 'marie@x.example')->text)
        ->toBe('Écrit à marie@x.example (marie@x.example)');
});

it('laisse [DATE] et [EMAIL] l\'emporter sur des colonnes du même nom', function () {
    $rendered = renderTemplate('[DATE] / [EMAIL]', ['DATE' => 'hier', 'EMAIL' => 'autre@x.example'], 'vrai@x.example');

    expect($rendered->text)->toBe('21 octobre 2026 / vrai@x.example');
});

it('ne relit jamais une valeur comme du modèle', function () {
    $rendered = renderTemplate('[NOTE]', ['NOTE' => 'voir [EMAIL] et [DATE] et [X'], 'vrai@x.example');

    expect($rendered->text)->toBe('voir [EMAIL] et [DATE] et [X')->and($rendered->missing)->toBe([]);
});

it('insère les valeurs sans toucher aux caractères spéciaux', function () {
    $valeur = 'Tarif : 5 $1 \1 ${X} <b>gras</b> & "guillemets"';

    expect(renderTemplate('[X]', ['X' => $valeur])->text)->toBe($valeur);
});

it('ne transforme pas le HTML : le mail est du texte brut', function () {
    expect(renderTemplate('<p>[X]</p>', ['X' => 'a & b'])->text)->toBe('<p>a & b</p>');
});

it('conserve accents et emojis des valeurs', function () {
    expect(renderTemplate('[X]', ['X' => 'Élodie Garçon ☀️'])->text)->toBe('Élodie Garçon ☀️');
});

it('refuse de rendre un modèle invalide', function () {
    renderTemplate('Bonjour [PRENOM');
})->throws(InvalidArgumentException::class);
