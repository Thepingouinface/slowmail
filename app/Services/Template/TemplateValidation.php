<?php

namespace App\Services\Template;

final class TemplateValidation
{
    /**
     * @param  list<string>  $errors  empêchent de rendre le modèle
     * @param  list<string>  $blockers  n'empêchent pas le rendu, mais l'activation (mention de désinscription)
     * @param  list<string>  $warnings
     * @param  list<string>  $unknownVariables  noms tels qu'écrits dans le modèle
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $blockers,
        public readonly array $warnings,
        public readonly array $unknownVariables,
    ) {}

    public function canRender(): bool
    {
        return $this->errors === [];
    }

    public function canActivate(): bool
    {
        return $this->errors === [] && $this->blockers === [];
    }
}
