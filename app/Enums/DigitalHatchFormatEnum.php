<?php

namespace App\Enums;

/** The format of the files exchanged through the digital hatch (HATCH_FORMAT). Every importer and exporter here reads and writes json only. */
enum DigitalHatchFormatEnum: string
{
    case JSON = 'json';

    /** The configured format; an unknown value fails loudly instead of silently reading nothing. */
    public static function current(): self
    {
        return self::from(strtolower((string) config('services.digital-hatch.format', self::JSON->value)));
    }

    /** File extension without the dot. */
    public function extension(): string
    {
        return match ($this) {
            self::JSON => 'json',
        };
    }
}
