<?php

namespace App\Models;

use App\Enums\SystemDomainEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OptionField extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'category_name',
        'form_field_id',
        'field_type_id',
        'has_category',
        'payload_section_id',
        'option_field_id',
        'is_input',
        'gender',
        'is_active',
        // Stable machine key (e.g. a clearance level), independent of the Hebrew name.
        'code',
    ];


    protected $casts = [
        'has_category' => 'boolean',
        'is_input' => 'boolean',
        'is_active' => 'boolean',
        'payload_section_id' => 'integer',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    // ------------------------------------------------------------------------------------------------------
    // The id contract of the three systems (SystemDomainEnum), banded like FormField's:
    //   1..MOBILE_SHARED_MAX    - מפקדת מחנה: the mobile-shared options, each at its exact mobile id (incl. 351-358).
    //   10001-29999/30001-49999 - ביטחון מידע / תקשוב: each web-only system's own block (SystemDomainEnum::optionRange).
    //   WEB_CREATED_FLOOR..     - options created at runtime (the option editors).
    // SystemDomainContractSeeder enforces the bands on every seeding run.
    // ------------------------------------------------------------------------------------------------------

    public const MOBILE_SHARED_MAX = 10000;
    public const WEB_CREATED_FLOOR = 50001;

    /** The next free id inside the given system's option block; its seeders pin every row they create with it. */
    public static function nextSeededIdFor(SystemDomainEnum $domain): int
    {
        [$floor, $ceiling] = $domain->optionRange();

        $next = max($floor, 1 + (int) self::query()->whereBetween('id', [$floor, $ceiling])->max('id'));

        if ($next > $ceiling) {
            throw new \RuntimeException("OptionField: the {$domain->value} option block {$floor}-{$ceiling} is full.");
        }

        return $next;
    }

    /** The next id for an option created at runtime - never auto-increment, which MariaDB resets to max(id)+1 on restart. */
    public static function nextWebCreatedId(): int
    {
        return max(self::WEB_CREATED_FLOOR, 1 + (int) self::query()->max('id'));
    }

    protected static function booted()
    {
        parent::boot();
        static::creating(function (OptionField $optionField) {
            if (empty($optionField->uuid)) {
                $optionField->uuid = (string) Str::uuid();
            }
        });
    }


    public function formField()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    public function fieldType()
    {
        return $this->belongsTo(FieldType::class, 'field_type_id');
    }

    public function parentOptionField()
    {
        return $this->belongsTo(OptionField::class, 'option_field_id');
    }

    /** Active child options. */
    public function childOptionField()
    {
        return $this->hasMany(OptionField::class, 'option_field_id')->where('option_fields.is_active', true);
    }
    public function bookingValue()
    {
        return $this->hasMany(BookingValue::class);
    }

}
