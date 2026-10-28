<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('full_name')->nullable();
            // The web login key: the personal number (מספר אישי) that ADFS returns.
            $table->string('personal_number')->nullable()->unique();
            // The ID number (ת"ז) - how the mobile app identifies the people who place bookings.
            $table->string('personal_id')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->onDelete('cascade');
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
