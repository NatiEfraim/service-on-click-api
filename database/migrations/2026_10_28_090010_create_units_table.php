<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            // The unit's letter (H, N, M, R): the mobile app knows it, and UnitEnum finds the unit by it, so it never changes.
            $table->string('name');
            // What this system shows people instead of the letter; it never leaves the system. Empty means "show the letter".
            $table->string('internal_name', 40)->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();
        });

        // Internal only: the mobile app has no departments, so nothing about them is ever exported.
        // (Made here because users.department_id points at it, and the users table is created right after this one.)
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');
            $table->string('name', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['unit_id', 'name']);
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('units');
    }
};
