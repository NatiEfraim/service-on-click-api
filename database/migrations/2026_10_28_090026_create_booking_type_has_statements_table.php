<?php

use App\Enums\StatementShowTimeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_type_has_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->nullable()->constrained('units')->onDelete('cascade');
            $table->foreignId('booking_type_id')->nullable()->constrained('booking_types')->onDelete('cascade');
            $table->text('statement')->nullable();
            $table->boolean('is_active')->default(false);
            // StatementShowTimeEnum: 0 = when the form opens, 1 = on submit.
            $table->unsignedTinyInteger('show_time')->default(StatementShowTimeEnum::ON_SUBMIT->value);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_type_has_statements');
    }
};
