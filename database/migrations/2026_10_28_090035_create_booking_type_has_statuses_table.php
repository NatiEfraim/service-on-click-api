<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_type_has_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            $table->foreignId('status_id')->constrained('statuses')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['booking_type_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_type_has_statuses');
    }
};
