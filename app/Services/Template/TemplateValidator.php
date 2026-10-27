<?php

namespace App\Services\Template;

use App\Models\Campaign;

/** Vérifie un modèle (objet + corps) avant de l'utiliser, et avant d'activer une campagne. */
final class TemplateValidator
{
    private const DEFAULT_OPTOUT = 'stop';

    public function __construct(private readonly TemplateParser $parser) {}

    public function validate(Campaign $campaign): TemplateValidation
    {
        return $this->check((string) $campaign->subject, (string) $campaign->body, $campaign->columns ?? []);
    }

    /** @param  list<string>  $columns  colonnes du CSV, donc variables utilisables */
    public function check(string $subject, string $body, array $columns): TemplateValidation
    {
        $errors = [];
        $blockers = [];
        $warnings = [];

        $subjectTemplate = $this->parser->parse($subject);
        $bodyTemplate = $this->parser->parse($body);

        if (Variables::trim($subject) === '') {
            $errors[] = 'L\'objet est vide.';
        } elseif (preg_match('/[\r\n]/', $subject) === 1) {
            $errors[] = 'L\'objet doit tenir sur une seule ligne.';
        }

        if (Variables::trim($body) === '') {
            $errors[] = 'Le corps est vide.';
        }

        foreach ($subjectTemplate->errors as $error) {
            $errors[] = "Objet : {$error['message']}.";
        }

        foreach ($bodyTemplate->errors as $error) {
            $errors[] = "Corps, ligne {$error['line']} : {$error['message']}.";
        }

        $unknown = $this->unknownVariables([...$subjectTemplate->variables(), ...$bodyTemplate->variables()], $columns);

        if ($unknown !== []) {
            $errors[] = sprintf(
                'Variables inconnues : %s. Colonnes disponibles : %s.',
                $this->brackets($unknown),
                $this->brackets([...$columns, ...Variables::BUILT_IN]),
            );
        }

        $optOut = Variables::trim((string) config('slowmail.optout_text')) ?: self::DEFAULT_OPTOUT;

        // Seul le texte fixe compte : une valeur par défaut ou une colonne pourrait changer d'une ligne à l'autre.
        if (Variables::trim($body) !== '' && mb_stripos($bodyTemplate->literalText(), $optOut) === false) {
            $blockers[] = sprintf(
                'Le corps doit contenir la mention de désinscription « %s » : sans elle, la campagne ne peut pas être activée.',
                $optOut,
            );
        }

        foreach ($columns as $column) {
            if (Variables::isBuiltIn($column)) {
                $warnings[] = sprintf(
                    'La colonne « %s » du CSV est masquée par la variable [%s] du moteur : renommez-la dans le CSV pour l\'utiliser.',
                    $column,
                    strtoupper(Variables::key($column)),
                );
            }
        }

        return new TemplateValidation($errors, $blockers, $warnings, $unknown);
    }

    /**
     * @param  list<VariableNode>  $variables
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function unknownVariables(array $variables, array $columns): array
    {
        $known = array_flip(array_map(Variables::key(...), $columns));
        $unknown = [];

        foreach ($variables as $variable) {
            if (! Variables::isBuiltIn($variable->name) && ! isset($known[$variable->key()])) {
                $unknown[$variable->key()] ??= $variable->name;
            }
        }

        return array_values($unknown);
    }

    /** @param  list<string>  $names */
    private function brackets(array $names): string
    {
        return implode(', ', array_map(fn (string $name) => "[{$name}]", $names));
    }
}
