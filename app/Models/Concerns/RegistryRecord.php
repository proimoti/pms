<?php

namespace App\Models\Concerns;

/**
 * Общите правила за всяка таблица на регистъра (и заглавията на договорите):
 * правилата за четене и запис (RegistryRules) и архив вместо триене.
 */
trait RegistryRecord
{
    use Archivable, RegistryRules;
}
