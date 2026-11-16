<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('export_jobs', function (Blueprint $table) {
            // A CSV export of a SQL editor result: the read-only statement to run again in the worker.
            $table->text('query')->nullable()->after('tables');
            $table->unsignedBigInteger('rows_written')->default(0)->after('file_bytes');
            // The worker's MySQL connection id while a CSV query runs, so Cancel can stop it with KILL QUERY.
            $table->unsignedBigInteger('mysql_connection_id')->nullable()->after('worker_pid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_jobs', function (Blueprint $table) {
            $table->dropColumn(['query', 'rows_written', 'mysql_connection_id']);
        });
    }
};
