<?php

namespace App\Enums;

/**
 * Петте типа договор със собственик (PROJECT.md, т. 3.6). Фиксирани в кода,
 * не се настройват по клиент. Различават се по начин на изчисление (три)
 * и при експорта към счетоводството.
 */
enum OwnerContractType: string
{
    case RepresentativePercent = 'representative_percent'; // Представител, %
    case RepresentativeFixed = 'representative_fixed';     // Представител, фиксиран
    case SubleaseIndividual = 'sublease_individual';       // Преотдаване, собственик физическо лице
    case SubleaseLegal = 'sublease_legal';                 // Преотдаване, собственик юридическо лице
    case Own = 'own';                                      // Собствен

    public function calculation(): CalculationMethod
    {
        return match ($this) {
            self::RepresentativePercent => CalculationMethod::Percent,
            self::RepresentativeFixed, self::SubleaseIndividual, self::SubleaseLegal => CalculationMethod::Fixed,
            self::Own => CalculationMethod::Own,
        };
    }

    /** Какъв трябва да е собственикът при преотдаване (т. 9.1). Null = без изискване. */
    public function requiredOwnerKind(): ?PartyKind
    {
        return match ($this) {
            self::SubleaseIndividual => PartyKind::Individual,
            self::SubleaseLegal => PartyKind::Legal,
            default => null,
        };
    }
}
