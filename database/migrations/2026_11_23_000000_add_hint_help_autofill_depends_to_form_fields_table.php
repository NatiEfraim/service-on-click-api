<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            // A help line under the field ("לדוגמה: ז"י/אט"ל/החטיבה הטכנולוגית").
            $table->string('hint', 255)->nullable()->after('autofill_key');
            // A link beside the label (the infosec guidelines of the user-open declaration).
            $table->string('help_url', 500)->nullable()->after('hint');
            // A field filled by the lookup is locked when the person was found; false = filled but still editable (the mobile phone).
            $table->boolean('autofill_locked')->default(true)->after('help_url');
            // {"field": "<field_name>", "values": ["<option>"]}: shown, and required when is_required, only while that field holds one of the values.
            $table->json('depends_on')->nullable()->after('autofill_locked');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn(['hint', 'help_url', 'autofill_locked', 'depends_on']);
        });
    }
};
