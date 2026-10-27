<?php

namespace App\Services\Template;

final class RenderedText
{
    /** @param  list<string>  $missing  variables vides, sans valeur par défaut, telles qu'écrites dans le modèle */
    public function __construct(
        public readonly string $text,
        public readonly array $missing,
    ) {}

    public function isComplete(): bool
    {
        return $this->missing === [];
    }
}
