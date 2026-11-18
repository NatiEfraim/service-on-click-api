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
            // Shown by both apps; the import matches bookings by it, so it must be unique.
            $table->bigInteger('serial_number')->nullable()->unique();
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('status_id')->default(1)->constrained('statuses')->onDelete('cascade');
            // Optimistic locking: edits name the version they saw and are refused if it moved on.
            $table->unsignedInteger('version')->default(1);
            // Last status seen from the mobile app, for the three-way merge on import.
            $table->unsignedTinyInteger('ext_status_id')->nullable();
            // TRANSPORTATION holds (8, 9, 10) never move a booking: the status it was put on hold from, which still decides whose turn it is.
            // Null = not on hold, or a hold with nowhere recorded (it stays at the supplier stage).
            $table->unsignedTinyInteger('parked_status_id')->nullable();
            // Who set the current status: external (mobile app), internal (decided here) or system.
            $table->string('status_source', 10)->default('external');
            // When the status was set; updated_at moves on every edit, so it cannot measure waiting time.
            $table->timestamp('status_changed_at')->nullable();
            $table->boolean('is_exported')->default(false);
            $table->boolean('is_status_exported')->default(true);
            $table->boolean('is_updated_value')->default(false);
            // Route types (e.g. the clearance grant): the assigned handler (מטפל) and when they were assigned.
            $table->foreignId('handler_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handler_assigned_at')->nullable();
            // When the next AUTOMATIC transition of a route type is due (UTC), e.g. the interview moment; null = none.
            $table->timestamp('stage_due_at')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'booking_type_id', 'status_id'], 'bookings_unit_type_status_index');
            $table->index('status_changed_at');
            $table->index(['status_id', 'stage_due_at'], 'bookings_status_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
