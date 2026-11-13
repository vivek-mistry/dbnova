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
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('database_name');
            // Null means the whole database.
            $table->json('tables')->nullable();
            $table->string('format', 16);
            $table->json('options')->nullable();
            $table->string('status', 16);
            $table->string('file_name');
            $table->unsignedBigInteger('estimated_bytes')->default(0);
            $table->unsignedBigInteger('bytes_written')->default(0);
            $table->unsignedBigInteger('file_bytes')->default(0);
            $table->text('last_error')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->unsignedInteger('worker_pid')->nullable();
            // MySQL credentials for the worker, encrypted; wiped once the dump tool has them (ADR 0008).
            $table->text('credentials')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
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
        Schema::dropIfExists('export_jobs');
    }
};
