<?php

namespace App\Enums;

/** Права: само четене или четене и запис. */
enum AccessLevel: string
{
    case Read = 'read';
    case Write = 'write';
}
