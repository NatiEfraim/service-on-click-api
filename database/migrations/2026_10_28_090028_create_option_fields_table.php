<?php

use App\Enums\GenderEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\PayloadSectionEnum;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            $table->string('name');
            $table->string('category_name')->nullable();
            $table->foreignId('form_field_id')->constrained('form_fields')->onDelete('cascade');
            $table->foreignId('field_type_id')->constrained('field_types')->onDelete('cascade');
            $table->boolean('has_category')->default(false);
            $table->integer('gender')->default(GenderEnum::NONE->value);
            $table->boolean('is_input')->default(false);

            // Store-payload section for this option (PayloadSectionEnum: 0 none, 1 inputs, 2 selects, 3 items).
            $table->integer('payload_section_id')
                ->default(PayloadSectionEnum::NONE->value);

            $table->foreignId('option_field_id')
            ->nullable()
            ->constrained('option_fields')
            ->nullOnDelete();
            // false = archived: out of the lists while the bookings that chose it keep it (deleting an option would delete their values with it).
            $table->boolean('is_active')->default(true);
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_fields');
    }
};
