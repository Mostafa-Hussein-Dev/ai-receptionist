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
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->integer('slot_number')->comment('1-24 for 8am-2pm');
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('status', ['available', 'booked', 'blocked'])->default('available');
            $table->foreignId('appointment_id')->nullable()->constrained()->onDelete('set null');
            $table->string('blocked_reason', 255)->nullable();
            $table->timestamps();

            // Unique constraint
            $table->unique(['doctor_id', 'date', 'slot_number']);

            // Indexes
            $table->index(['doctor_id', 'date', 'status']);
            $table->index('date');
            $table->index('appointment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
