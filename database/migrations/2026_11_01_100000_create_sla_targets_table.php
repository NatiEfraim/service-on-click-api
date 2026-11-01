<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One total handling target per booking type; a booking is overdue once older.
        Schema::create('sla_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            // Only 'total' is used; leaves room for per-stage targets.
            $table->string('stage', 30)->default('total');
            $table->decimal('target_hours', 8, 2);
            // Share of the target after which a booking is "near" it (0.80 = 80%).
            $table->decimal('warn_ratio', 3, 2)->default(0.80);
            // Personal number of the admin who set it.
            $table->string('set_by')->nullable();
            $table->timestamps();

            $table->unique(['booking_type_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_targets');
    }
};
