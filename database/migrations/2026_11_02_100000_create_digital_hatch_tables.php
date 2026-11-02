<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files taken from the digital hatch FTP (b2r json files and attachments), by their name there.
        Schema::create('digital_hatch_fetched_files', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('file_name')->unique();
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
