<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files sent back to the mobile app (r2b files): decisions, corrected values and config changes.
        Schema::create('sync_files', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10)->default('outbound');
            // booking_status | booking_values | system_config
            $table->string('kind', 40);
            $table->string('file_name');
            $table->char('sha256', 64);
            // queued | sent | failed | superseded | blocked (internal name found) | awaiting_external_support
            $table->string('state', 30);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            // Kept so a failed file is resent unchanged.
            $table->longText('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['direction', 'sha256']);
            $table->index(['state', 'next_attempt_at']);
        });

        // Mobile app changed a status while a local decision was pending; a person picks which stands.
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            // Last mobile-seen status, the status here, and the incoming one.
            $table->unsignedTinyInteger('base_status_id')->nullable();
            $table->unsignedTinyInteger('internal_status_id');
            $table->unsignedTinyInteger('external_status_id');
            $table->timestamp('external_changed_at')->nullable();
            // open | resolved
            $table->string('state', 20)->default('open');
            // accept_external | keep_internal (a person), confirmed_by_mobile | superseded_by_mobile (the import, on its own)
            $table->string('resolution', 30)->nullable();
            $table->string('resolved_by')->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
        Schema::dropIfExists('sync_files');
    }
};
