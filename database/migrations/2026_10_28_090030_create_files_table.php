<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('name');              // randomFileName (e.g. "abc123_xyz.pdf")
            $table->string('display_name');      // user-friendly display name
            $table->string('path');              // S3/MinIO path (e.g. "files/abc123_xyz.pdf")
            $table->string('file_name');         // original filename from upload
            $table->boolean('is_downloaded')->default(false); // already zipped into the export-files folder of the bucket
            $table->timestamps();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
