<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        // A double click or a network retry of a status change sends the same Idempotency-Key: the second request gets the first one's answer.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('key', 80);
            // What the key was first used for: the same key with another request is refused instead of answered with the wrong response.
            $table->char('request_hash', 64);
            $table->json('response');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index('created_at');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
