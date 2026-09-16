<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Exceptions;

use RuntimeException;

class FormatterNotFoundException extends RuntimeException
{
    /**
     * Create a new exception for a missing formatter executable.
     */
    public static function forBinary(string $binaryPath): self
    {
        return new self(
            "Laravel Pint binary not found at [{$binaryPath}].\n\n".
            "<comment>How to fix:</comment>\n".
            "1. Install Laravel Pint: composer require laravel/pint --dev\n".
            "2. Or configure a custom binary: ->formatWithPint(binary: '/path/to/pint')\n".
            '3. Or disable strict formatting checks: ->formatWithPint(strict: false)'
        );
    }
}
