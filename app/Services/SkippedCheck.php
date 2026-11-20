<?php

namespace App\Services;

use RuntimeException;

/**
 * Проверка, която не може да се направи, защото полето, от което зависи, е
 * грешно (грешката вече е показана). Записът пак се отказва, но без второ
 * съобщение.
 */
final class SkippedCheck extends RuntimeException {}
