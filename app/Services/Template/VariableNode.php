<?php

namespace App\Services\Template;

final class VariableNode
{
    /**
     * @param  string  $name  nom tel qu'écrit dans le modèle, sans espaces autour
     * @param  ?string  $default  null : pas de valeur par défaut ; '' : défaut explicitement vide, [X|]
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $default,
    ) {}

    public function key(): string
    {
        return Variables::key($this->name);
    }
}
