<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        // Who did what to what, and what it looked like before and after. Written only by App\Services\AuditService; each row carries the hash of the
        // row before it, so a row that was changed or removed afterwards breaks the chain (php artisan audit:verify).
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 6);
            // user | system | external
            $table->string('actor_type', 10);
            // The user's personal number (or ID number), or the name of the component.
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

        // One row, always id 1: it is locked while a row is appended, so two requests can never chain to the same previous row - and it
        // remembers the last row, so removing the newest rows shows up too.
        Schema::create('audit_log_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->char('last_hash', 64)->nullable();
        });

        DB::table('audit_log_head')->insert(['id' => 1]);

        $this->protectAuditLog();
    }

    /** Reverse the migrations. Throws on a production database: the audit history is dropped by an approved procedure, not by a rollback. */
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

    /**
     * Append-only at the database too: an UPDATE or DELETE raises. The model refuses them as well, and the hash chain shows a change made behind
     * its back, so a database account that may not create triggers (MySQL asks for the TRIGGER privilege, and SUPER while binary logging is on)
     * only loses this extra layer.
     */
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
