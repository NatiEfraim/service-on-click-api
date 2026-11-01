<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            // Letter (H, N, M, R) the mobile app uses; UnitEnum resolves units by it.
            $table->string('name');
            // Display name used in this system only; empty means "show the letter".
            $table->string('internal_name', 40)->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();
        });

        // Internal only: never exported to the mobile app.
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
            $table->string('name', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['unit_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('units');
    }
};
