<?php

namespace App\Services;

/**
 * Знак „полето от формата е грешно“ (например дата, която не е дата). Формата
 * вече показва грешката за полето; проверките, които зависят от него, се
 * прескачат без второ съобщение, а другите проверки се пускат (PROJECT.md,
 * „Дати в интерфейса“: всички грешки наведнъж).
 */
final class InvalidInput
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    /** Има ли знак някъде в данните. */
    public static function isIn(mixed $data): bool
    {
        if ($data instanceof self) {
            return true;
        }

        if (is_array($data)) {
            foreach ($data as $value) {
                if (self::isIn($value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
