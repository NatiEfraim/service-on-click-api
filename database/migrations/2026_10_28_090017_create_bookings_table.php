<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            // Optimistic locking: a status change or a value edit names the version it saw, and is refused when the booking moved on since.
            $table->unsignedInteger('version')->default(1);
            // The last status seen from the mobile app - tells a decision made here from a change made there (three-way merge on import).
            $table->unsignedTinyInteger('ext_status_id')->nullable();
            // Who set the current status: external (the mobile app), internal (a decision made here) or system.
            $table->string('status_source', 10)->default('external');
            // When the current status was set. updated_at moves with every edit, so it cannot tell how long the booking has waited.
            $table->timestamp('status_changed_at')->nullable();
            $table->boolean('is_exported')->default(false);
            $table->boolean('is_status_exported')->default(true);
            $table->boolean('is_updated_value')->default(false);
            $table->timestamps();

            $table->index(['unit_id', 'booking_type_id', 'status_id'], 'bookings_unit_type_status_index');
            $table->index('status_changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
