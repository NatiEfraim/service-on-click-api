<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('action_type_id')->constrained('action_types')->onDelete('cascade');
            // Status the booking moved into; makes the log a per-stage timeline.
            $table->unsignedTinyInteger('status_id')->nullable();
            // external (imported from the mobile app), internal (decided here) or system.
            $table->string('source', 10)->default('external');
            // Links the entry to its audit log row and exported decision.
            $table->uuid('correlation_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_exported')->default(false);
            $table->timestamps();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_logs');
    }
};
