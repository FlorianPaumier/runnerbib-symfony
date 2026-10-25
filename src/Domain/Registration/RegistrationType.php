<?php

declare(strict_types=1);

namespace App\Domain\Registration;

enum RegistrationType: string
{
    case Text = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
}
