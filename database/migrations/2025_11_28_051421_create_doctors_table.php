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
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->onDelete('cascade');
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('email', 100)->unique()->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('specialization', 100)->nullable();
            $table->integer('slots_per_appointment')->default(2)->comment('Number of 15-min slots per appointment (1-4)');
            $table->integer('max_appointments_per_day')->default(12);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('department_id');
            $table->index('is_active');
            $table->index(['last_name', 'first_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
