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
            // The status the booking moved into. With it the log is the booking's timeline: how long each stage took, and who moved it on.
            $table->unsignedTinyInteger('status_id')->nullable();
            // Where the entry came from: external (the mobile app's logs, imported), internal (a decision made here) or system.
            $table->string('source', 10)->default('external');
            // Ties the entry to the audit log row and to the decision that was exported.
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
