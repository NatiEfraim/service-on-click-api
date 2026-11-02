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
        // The files this system took in (b2r), by their name: the json files (SrvOnClick_b2r_...) and the files and images of the bookings
        // (SrvOnClickFiles_...), whether they came from the digital hatch FTP, from the local test folder or from the bucket. The ":ftp" import
        // commands skip a name that is here, so a file is taken once.
        Schema::create('digital_hatch_fetched_files', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('file_name')->unique();
            // export_bookings | application_logs | attachments
            $table->string('stream', 30)->index();
            // ftp | local | bucket
            $table->string('source', 10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digital_hatch_fetched_files');
    }
};
