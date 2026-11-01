<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_reference_has_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_reference_id')->constrained('system_references')->onDelete('cascade');
            $table->foreignId('form_field_id')->constrained('form_fields')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_reference_has_form_fields');
    }
};
