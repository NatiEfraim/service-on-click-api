<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations; maps booking types to their relevant roles (read by RoleAndPermissionSeeder). */
    public function up(): void
    {
        Schema::create('booking_type_has_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_type_id')->constrained('booking_types')->onDelete('cascade');
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['booking_type_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_type_has_roles');
    }
};
