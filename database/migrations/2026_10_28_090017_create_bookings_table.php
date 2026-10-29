<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid();
            // The number both apps show. The import finds a booking by it, so two bookings may never share one.
            $table->bigInteger('serial_number')->nullable()->unique();
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('status_id')->default(1)->constrained('statuses')->onDelete('cascade');
            $table->boolean('is_exported')->default(false);
            $table->boolean('is_status_exported')->default(true);
            $table->boolean('is_updated_value')->default(false);
            $table->timestamps();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
