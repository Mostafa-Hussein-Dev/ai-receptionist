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
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->integer('day_of_week')->comment('0=Sunday, 1=Monday, ..., 6=Saturday');
            $table->time('start_time')->comment('e.g., 08:00:00');
            $table->time('end_time')->comment('e.g., 14:00:00');
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            // Unique constraint
            $table->unique(['doctor_id', 'day_of_week']);

            // Indexes
            $table->index('doctor_id');
            $table->index('day_of_week');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};
