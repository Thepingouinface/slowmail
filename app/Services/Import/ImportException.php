<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Fichier inutilisable en entier (illisible, sans colonne e-mail…).
 * Le message est affiché tel quel à l'utilisateur.
 */
class ImportException extends RuntimeException {}
