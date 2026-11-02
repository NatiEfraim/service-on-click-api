<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files taken from the digital hatch (b2r json files and attachments), by their name there: the stream they belong to
        // (export_bookings, application_logs or attachments) and where they came from (the FTP, or the local test folder).
        Schema::create('digital_hatch_fetched_files', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('file_name')->unique();
            $table->string('stream', 30)->index();
            $table->string('source', 10);
            $table->timestamps();
        });

        // Versions of the r2b files put on the FTP: a file name whose content changed is sent again.
        Schema::create('digital_hatch_sent_files', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->char('sha256', 64);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['file_name', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_hatch_sent_files');
        Schema::dropIfExists('digital_hatch_fetched_files');
    }
};
