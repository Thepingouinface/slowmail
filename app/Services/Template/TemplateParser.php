<?php

namespace App\Services\Template;

/**
 * Lit un modèle : [X], [X|défaut], et \[ \] pour des crochets littéraux.
 *
 * Strict à dessein : un crochet ouvert jamais fermé, imbriqué ou fermé sans être ouvert est une
 * erreur, pour qu'un « [PRENOM » ne parte jamais tel quel chez quelqu'un.
 */
final class TemplateParser
{
    private const HINT_OPEN = '(écrivez \[ pour un crochet littéral)';

    private const HINT_CLOSE = '(écrivez \] pour un crochet littéral)';

    public function parse(string $source): ParsedTemplate
    {
        $nodes = [];
        $errors = [];
        $text = '';
        $length = strlen($source);
        $i = 0;

        // Les crochets et la barre oblique inverse sont de l'ASCII : lire octet par octet est sûr en UTF-8.
        while ($i < $length) {
            $char = $source[$i];

            if ($char === '\\' && $this->isBracket($source[$i + 1] ?? '')) {
                $text .= $source[$i + 1];
                $i += 2;
            } elseif ($char === ']') {
                $errors[] = $this->error($source, $i, 'crochet fermant sans crochet ouvrant '.self::HINT_CLOSE);
                $i++;
            } elseif ($char !== '[') {
                $text .= $char;
                $i++;
            } else {
                [$inner, $next, $problem] = $this->readVariable($source, $i + 1);

                if ($problem !== null) {
                    $errors[] = $this->error($source, $i, "{$problem} ".self::HINT_OPEN);
                } else {
                    [$name, $default] = $this->split($inner);

                    if ($name === '') {
                        $errors[] = $this->error($source, $i, 'variable sans nom');
                    } else {
                        if ($text !== '') {
                            $nodes[] = new TextNode($text);
                            $text = '';
                        }

                        $nodes[] = new VariableNode($name, $default);
                    }
                }

                $i = $next;
            }
        }

        if ($text !== '') {
            $nodes[] = new TextNode($text);
        }

        return new ParsedTemplate($nodes, $errors);
    }

    /**
     * Lit ce qui suit un « [ » jusqu'au « ] ».
     *
     * @return array{0: ?string, 1: int, 2: ?string} contenu, position où reprendre, problème
     */
    private function readVariable(string $source, int $start): array
    {
        $inner = '';
        $length = strlen($source);

        for ($j = $start; $j < $length; $j++) {
            $char = $source[$j];

            if ($char === '\\' && $this->isBracket($source[$j + 1] ?? '')) {
                $inner .= $source[$j + 1];
                $j++;
            } elseif ($char === ']') {
                return [$inner, $j + 1, null];
            } elseif ($char === '[') {
                return [null, $j, 'crochet ouvert à l\'intérieur d\'un autre'];
            } elseif ($char === "\n" || $char === "\r") {
                return [null, $j, 'crochet ouvert jamais fermé'];
            } else {
                $inner .= $char;
            }
        }

        return [null, $length, 'crochet ouvert jamais fermé'];
    }

    /** @return array{0: string, 1: ?string} */
    private function split(string $inner): array
    {
        $parts = explode('|', $inner, 2);

        return [
            Variables::trim($parts[0]),
            isset($parts[1]) ? Variables::trim($parts[1]) : null,
        ];
    }

    private function isBracket(string $char): bool
    {
        return $char === '[' || $char === ']';
    }

    /** @return array{line: int, message: string} */
    private function error(string $source, int $offset, string $message): array
    {
        return ['line' => substr_count($source, "\n", 0, $offset) + 1, 'message' => $message];
    }
}
