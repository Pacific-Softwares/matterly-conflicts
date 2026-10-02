<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People and companies connected to a matter who aren't the firm's client.
        Schema::create('matterly_conflicts_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->string('name', 160);
            $table->string('aliases', 255)->nullable();
            $table->string('role', 24)->default('adverse');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['firm_id', 'name']);
        });

        // The firm's conflict log: every recorded check and its outcome.
        Schema::create('matterly_conflicts_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('query', 160);
            $table->string('outcome', 16)->default('pending');
            $table->string('source', 16)->default('manual');
            $table->text('note')->nullable();
            $table->json('hits')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['firm_id', 'outcome']);
            $table->index(['firm_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matterly_conflicts_checks');
        Schema::dropIfExists('matterly_conflicts_parties');
    }
};
