<?php

namespace App\Services\Template;

final class ParsedTemplate
{
    /**
     * @param  list<TextNode|VariableNode>  $nodes
     * @param  list<array{line: int, message: string}>  $errors
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<VariableNode> */
    public function variables(): array
    {
        return array_values(array_filter($this->nodes, fn ($node) => $node instanceof VariableNode));
    }

    /** Le texte fixe du modèle, sans les variables ni leurs valeurs par défaut. */
    public function literalText(): string
    {
        return implode('', array_map(
            fn ($node) => $node instanceof TextNode ? $node->text : '',
            $this->nodes,
        ));
    }
}
