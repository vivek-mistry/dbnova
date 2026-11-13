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
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('upload_id')->nullable()->constrained()->nullOnDelete();
            $table->string('database_name');
            $table->string('status', 16);
            $table->json('options')->nullable();
            $table->json('preflight')->nullable();
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->unsignedBigInteger('bytes_scanned')->default(0);
            $table->unsignedBigInteger('bytes_streamed')->default(0);
            $table->unsignedBigInteger('statements_seen')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('error_line')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->unsignedInteger('worker_pid')->nullable();
            // MySQL credentials for the worker, encrypted; wiped when the import finishes (ADR 0008).
            $table->text('credentials')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
