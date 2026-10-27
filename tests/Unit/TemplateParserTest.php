<?php

use App\Services\Template\ParsedTemplate;
use App\Services\Template\TemplateParser;
use App\Services\Template\TextNode;
use App\Services\Template\VariableNode;

function parseTemplate(string $source): ParsedTemplate
{
    return (new TemplateParser)->parse($source);
}

it('sépare le texte fixe et les variables', function () {
    $template = parseTemplate('Bonjour [PRENOM], bienvenue chez [STRUCTURE|nous].');

    expect($template->isValid())->toBeTrue()
        ->and($template->nodes)->toHaveCount(5)
        ->and($template->nodes[0])->toEqual(new TextNode('Bonjour '))
        ->and($template->nodes[1])->toEqual(new VariableNode('PRENOM', null))
        ->and($template->nodes[3])->toEqual(new VariableNode('STRUCTURE', 'nous'));
});

it('retire les espaces autour du nom et de la valeur par défaut', function () {
    $node = parseTemplate('[  STRUCTURE  |  votre structure  ]')->nodes[0];

    expect($node->name)->toBe('STRUCTURE')->and($node->default)->toBe('votre structure');
});

it('garde les espaces à l\'intérieur d\'un nom', function () {
    expect(parseTemplate('[NOM DE FAMILLE]')->nodes[0]->name)->toBe('NOM DE FAMILLE');
});

it('distingue l\'absence de valeur par défaut d\'une valeur par défaut vide', function () {
    $nodes = parseTemplate('[A] [B|]')->nodes;

    expect($nodes[0]->default)->toBeNull()->and($nodes[2]->default)->toBe('');
});

it('ne coupe la valeur par défaut qu\'à la première barre', function () {
    expect(parseTemplate('[X|a|b]')->nodes[0]->default)->toBe('a|b');
});

it('compare les noms sans tenir compte de la casse ni des espaces autour', function () {
    expect(parseTemplate('[ Prénom ]')->nodes[0]->key())->toBe('prénom');
});

it('lit \[ et \] comme des crochets littéraux', function () {
    $template = parseTemplate('Voir \[PRENOM\] ci-dessous');

    expect($template->isValid())->toBeTrue()
        ->and($template->variables())->toBe([])
        ->and($template->literalText())->toBe('Voir [PRENOM] ci-dessous');
});

it('accepte un crochet échappé dans une valeur par défaut', function () {
    expect(parseTemplate('[X|a \] b]')->nodes[0]->default)->toBe('a ] b');
});

it('laisse une barre oblique inverse qui n\'échappe pas un crochet', function () {
    expect(parseTemplate('C:\dossier\fichier')->literalText())->toBe('C:\dossier\fichier');
});

it('reste correct avec des accents et des emojis', function () {
    $template = parseTemplate('Été ☀️ [PRÉNOM|ami·e] — à bientôt');

    expect($template->isValid())->toBeTrue()
        ->and($template->nodes[0]->text)->toBe('Été ☀️ ')
        ->and($template->nodes[1]->name)->toBe('PRÉNOM')
        ->and($template->nodes[2]->text)->toBe(' — à bientôt');
});

it('liste les variables dans l\'ordre, sans compter les valeurs par défaut comme du texte fixe', function () {
    $template = parseTemplate('[A] et [B|ceci] puis [A]');

    expect(array_map(fn ($v) => $v->name, $template->variables()))->toBe(['A', 'B', 'A'])
        ->and($template->literalText())->toBe(' et  puis ');
});

it('refuse un crochet ouvert jamais fermé', function () {
    $template = parseTemplate("Bonjour\n\nBonjour [PRENOM");

    expect($template->isValid())->toBeFalse()
        ->and($template->errors)->toHaveCount(1)
        ->and($template->errors[0]['line'])->toBe(3)
        ->and($template->errors[0]['message'])->toContain('jamais fermé')->toContain('\[');
});

it('refuse un crochet qui s\'étend sur plusieurs lignes', function () {
    $template = parseTemplate("Bonjour [PRENOM\nsuite]");

    expect($template->errors[0])->toMatchArray(['line' => 1])
        ->and($template->errors[0]['message'])->toContain('jamais fermé');
});

it('refuse un crochet fermant sans crochet ouvrant', function () {
    $template = parseTemplate("ligne 1\nPRENOM] fin");

    expect($template->errors)->toHaveCount(1)
        ->and($template->errors[0]['line'])->toBe(2)
        ->and($template->errors[0]['message'])->toContain('fermant')->toContain('\]');
});

it('refuse des crochets imbriqués', function () {
    $template = parseTemplate('[[PRENOM]]');

    expect($template->isValid())->toBeFalse()
        ->and($template->errors[0]['message'])->toContain('à l\'intérieur d\'un autre');
});

it('refuse une variable sans nom', function () {
    foreach (['[]', '[ ]', '[|défaut]'] as $source) {
        expect(parseTemplate($source)->errors[0]['message'])->toBe('variable sans nom');
    }
});

it('signale toutes les erreurs, avec leur ligne', function () {
    $template = parseTemplate("[A\nok\n[B] ]\n[C");

    expect(array_column($template->errors, 'line'))->toBe([1, 3, 4]);
});

it('lit un modèle vide', function () {
    $template = parseTemplate('');

    expect($template->isValid())->toBeTrue()->and($template->nodes)->toBe([]);
});
