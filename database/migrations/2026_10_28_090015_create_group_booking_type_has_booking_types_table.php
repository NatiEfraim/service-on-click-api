<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_booking_type_has_booking_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_booking_id')->constrained('group_booking_types')->onDelete('cascade');
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_booking_type_has_booking_types');
    }
};
