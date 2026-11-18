<?php

namespace App\Enums;

/** Who fills a form field: the requester on submission, or the handlers during the handling (route types such as the clearance grant). */
enum FormSectionEnum: string
{
    case REQUESTER = 'requester';
    case HANDLER = 'handler';

    public function label(): string
    {
        return match ($this) {
            self::REQUESTER => 'פרטים נוספים',
            self::HANDLER => 'למילוי על ידי גורמים רלוונטיים',
        };
    }

    /** @return array<int, array{id: string, label: string}> */
    public static function rows(): array
    {
        return array_map(fn (self $section): array => ['id' => $section->value, 'label' => $section->label()], self::cases());
    }
}
