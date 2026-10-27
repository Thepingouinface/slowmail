<?php

namespace App\Services\Template;

final class Variables
{
    /** Toujours disponibles, en plus des colonnes du CSV. Elles masquent une colonne du même nom. */
    public const BUILT_IN = ['DATE', 'EMAIL'];

    /** Forme comparable d'un nom de variable : insensible à la casse et aux espaces autour. */
    public static function key(string $name): string
    {
        return mb_strtolower(self::trim($name));
    }

    public static function isBuiltIn(string $name): bool
    {
        return in_array(strtoupper(self::key($name)), self::BUILT_IN, true);
    }

    /** Retire les espaces, y compris l'espace insécable que les copier-coller glissent parfois. */
    public static function trim(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? '';
    }
}
