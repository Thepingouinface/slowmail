<?php

namespace App\Services\Template;

use Carbon\CarbonInterface;
use InvalidArgumentException;

final class TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $data  colonnes du destinataire, clés = en-têtes du CSV
     * @param  string  $timezone  fuseau de la campagne : décide du jour affiché par [DATE]
     */
    public function render(ParsedTemplate $template, array $data, string $email, CarbonInterface $at, string $timezone): RenderedText
    {
        if (! $template->isValid()) {
            throw new InvalidArgumentException('Le modèle contient des erreurs de syntaxe : à valider avant de le rendre.');
        }

        $values = [];

        foreach ($data as $name => $value) {
            $values[Variables::key((string) $name)] ??= is_scalar($value) ? Variables::trim((string) $value) : '';
        }

        // Après les colonnes : une colonne DATE ou EMAIL du CSV ne masque jamais celles du moteur.
        $values['date'] = $at->copy()->setTimezone($timezone)->locale('fr')->translatedFormat('j F Y');
        $values['email'] = $email;

        $text = '';
        $missing = [];

        // Les valeurs sont insérées telles quelles, jamais relues comme du modèle :
        // une cellule qui contient « [EMAIL] » reste littéralement « [EMAIL] ».
        foreach ($template->nodes as $node) {
            if ($node instanceof TextNode) {
                $text .= $node->text;

                continue;
            }

            $value = $values[$node->key()] ?? '';

            if ($value !== '') {
                $text .= $value;
            } elseif ($node->default !== null) {
                $text .= $node->default;
            } else {
                $missing[$node->key()] ??= $node->name;
            }
        }

        return new RenderedText($text, array_values($missing));
    }
}
