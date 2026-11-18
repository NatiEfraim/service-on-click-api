<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\PayloadSectionEnum;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            $table->string('field_name');
            $table->string('field_display_name');
            $table->foreignId('field_type_id')->constrained('field_types')->onDelete('cascade');
            $table->foreignId('form_field_id')
                ->nullable()
                ->constrained('form_fields')
                ->nullOnDelete();
  
            $table->boolean('is_input')->default(false);

            // PayloadSectionEnum: 0 none, 1 inputs, 2 selects, 3 items.
            $table->integer('payload_section_id')
                ->default(PayloadSectionEnum::NONE->value);

            $table->json('field_validations')->nullable();
            $table->boolean('editable')->default(1);
            $table->boolean('is_required')->default(1);
            // Optimistic locking for edits made here.
            $table->unsignedInteger('version')->default(1);
            // Layout order: position, then id (seeded fields all start at 0).
            $table->unsignedInteger('position')->default(0);
            // false = archived; a field that bookings hold values for is never deleted.
            $table->boolean('is_active')->default(true);
            // FormSectionEnum: requester = filled on submission, handler = filled during the handling (route types).
            $table->string('section', 20)->default('requester');
            // Width on the form's 4-column grid (1, 2 or 4).
            $table->unsignedTinyInteger('col_span')->default(1);
            // Set on the field whose value triggers a lookup (only "person" today); autofill_key names the key of the
            // lookup result that fills this field, which is then locked when the person was found.
            $table->string('lookup_source', 30)->nullable();
            $table->string('autofill_key', 50)->nullable();
            $table->timestamps();

            // The seeders' updateOrCreate key, and how the workflow addresses a field.
            $table->unique(['booking_type_id', 'unit_id', 'field_name'], 'form_fields_type_unit_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
