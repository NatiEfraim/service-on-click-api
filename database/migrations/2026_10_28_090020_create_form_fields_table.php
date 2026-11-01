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

            // Store-payload section for this field (PayloadSectionEnum: 0 none, 1 inputs, 2 selects, 3 items).
            $table->integer('payload_section_id')
                ->default(PayloadSectionEnum::NONE->value);

            $table->json('field_validations')->nullable();
            $table->boolean('editable')->default(1);
            $table->boolean('is_required')->default(1);
            // Optimistic locking for what this system changes about a field (its name, required, editable, type, place, removal).
            $table->unsignedInteger('version')->default(1);
            // The place of the field in its form: the fields are laid out by position, then id (the seeded ones all start at 0, so id order).
            $table->unsignedInteger('position')->default(0);
            // false = archived: out of the form and the settings while the values bookings hold for it stay - a field with values is never deleted.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
