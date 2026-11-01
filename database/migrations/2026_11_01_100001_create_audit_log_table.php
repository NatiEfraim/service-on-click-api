<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only log written only by AuditService; each row hashes the row before it (php artisan audit:verify).
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 6);
            // user | system | external
            $table->string('actor_type', 10);
            // Personal number, ID number, or component name.
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            // internal | external | automation
            $table->string('source', 12);
            $table->string('action', 80);
            $table->string('entity_type', 40);
            $table->string('entity_id')->nullable();
            $table->unsignedBigInteger('booking_serial')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('booking_type_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            // success | denied | failed
            $table->string('result', 10);
            $table->uuid('correlation_id')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->index(['booking_serial', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
        });

        // Single row (id 1), locked on append so two requests never chain to the same previous row;
        // remembering the last row also exposes removal of the newest rows.
        Schema::create('audit_log_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->char('last_hash', 64)->nullable();
        });

        DB::table('audit_log_head')->insert(['id' => 1]);

        $this->protectAuditLog();
    }

    /** Throws in production: audit history is dropped by an approved procedure, not a rollback. */
    public function down(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The audit_log table is not dropped by a rollback on a production database.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_delete');

        Schema::dropIfExists('audit_log_head');
        Schema::dropIfExists('audit_log');
    }

    /** Append-only at the DB level: UPDATE and DELETE raise (needs the TRIGGER privilege). */
    private function protectAuditLog(): void
    {
        $driver = DB::getDriverName();

        try {
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;");
                DB::unprepared("CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;");
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'");
                DB::unprepared("CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'");
            }
        } catch (\Throwable $e) {
            Log::warning('audit_log: the append-only triggers were not created: ' . $e->getMessage());
        }
    }
};
