<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        // One handling target per booking type: the total time from the booking's opening to its closing. A booking is overdue once it is older.
        Schema::create('sla_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            // 'total' is the only stage in use; the column leaves room for per-stage targets.
            $table->string('stage', 30)->default('total');
            $table->decimal('target_hours', 8, 2);
            // The share of the target after which a booking counts as "near the target" (0.80 = 80%).
            $table->decimal('warn_ratio', 3, 2)->default(0.80);
            // Who set it: the personal number of the admin or super admin.
            $table->string('set_by')->nullable();
            $table->timestamps();

            $table->unique(['booking_type_id', 'stage']);
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('sla_targets');
    }
};
