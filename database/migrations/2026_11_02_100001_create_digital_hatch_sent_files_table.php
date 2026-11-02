<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The r2b files this system wrote for the mobile side (users, booking status), by name and content: the day's file keeps its name while its
        // content grows, so a version is remembered once per place it went to. A file that was sent is not sent again unless its content changed.
        Schema::create('digital_hatch_sent_files', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('file_name');
            $table->char('sha256', 64);
            // bucket (written for the mobile side to read) | ftp | local (the local test folder)
            $table->string('destination', 10);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['file_name', 'sha256', 'destination']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digital_hatch_sent_files');
    }
};
