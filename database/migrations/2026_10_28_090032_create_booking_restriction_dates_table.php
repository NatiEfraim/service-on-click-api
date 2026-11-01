<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_restriction_dates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['booking_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_restriction_dates');
    }
};
