<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('booking_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->nullable()->constrained('units')->onDelete('cascade');
            $table->foreignId('booking_type_id')->nullable()->constrained('booking_types')->onDelete('cascade');
            $table->foreignId('form_field_id')->nullable()->constrained('form_fields')->onDelete('cascade');
            $table->string('operator');
            $table->timestamps();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('booking_filters');
    }
};
