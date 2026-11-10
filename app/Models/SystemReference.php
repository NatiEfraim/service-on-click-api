<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SystemReference extends Model
{
    protected $fillable = [
        'booking_type_id',
        'unit_id',
        'name',
        'title',
        'description',
        'value',
        'field_type_id',
        'details_values',
        'details_display_names',
        'details_validations',
        'details_types',
        'limit',
        'is_exported',
    ];


    protected $hidden = [
        'created_at',
        'updated_at',
        'is_exported',
    ];

    protected $casts = [
        'details_values' => 'array',
        'details_display_names' => 'array',
        'details_validations' => 'array',
        'details_types' => 'array',
        'value' => 'array',
        'is_exported' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];


    /** @return array<string, mixed> Decoded value; the 'array' cast still yields a JSON string for double-encoded rows. */
    public function decodedValue(): array
    {
        $value = $this->value;

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /** Sets is_exported=1 on records that went in the file; a newer updated_at (changed during the build) keeps them waiting. @param array<int, ?string> $versions record id => updated_at snapshot @return int marked */
    public static function markExported(array $versions): int
    {
        $marked = 0;

        $idsByVersion = [];
        foreach ($versions as $id => $updatedAt) {
            $idsByVersion[(string) $updatedAt][] = $id;
        }

        foreach ($idsByVersion as $updatedAt => $ids) {
            foreach (array_chunk($ids, 2000) as $chunk) {
                $marked += static::query()
                    ->whereIn('id', $chunk)
                    ->where('is_exported', false)
                    ->when(
                        (string) $updatedAt === '',
                        fn (Builder $query) => $query->whereNull('updated_at'),
                        fn (Builder $query) => $query->where('updated_at', $updatedAt),
                    )
                    ->toBase()
                    ->update(['is_exported' => true]);
            }
        }

        return $marked;
    }

    public function bookingType()
    {
        return $this->belongsTo(BookingType::class, 'booking_type_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function systemReferenceHasFormField()
    {
        return $this->hasMany(SystemReferenceHasFormField::class);
    }
    public function formFields()
    {
        return $this->belongsToMany(FormField::class, 'system_reference_has_form_fields', 'system_reference_id', 'form_field_id');
    }

    public function fieldType()
    {
        return $this->belongsTo(FieldType::class, 'field_type_id');
    }
}
