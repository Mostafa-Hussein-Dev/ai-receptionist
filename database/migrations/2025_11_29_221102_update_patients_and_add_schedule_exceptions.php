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
        // Remove is_active from patients table
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        // Create doctor_schedule_exceptions table
        Schema::create('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->enum('type', ['day_off', 'custom_hours'])->default('day_off');
            $table->time('start_time')->nullable()->comment('For custom_hours type');
            $table->time('end_time')->nullable()->comment('For custom_hours type');
            $table->string('reason')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['doctor_id', 'date']);
            $table->unique(['doctor_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_schedule_exceptions');

        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }
};
