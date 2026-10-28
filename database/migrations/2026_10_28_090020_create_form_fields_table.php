<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\PayloadSectionEnum;

return new class extends Migration
{
    /** Run the migrations. */
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
            $table->timestamps();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
