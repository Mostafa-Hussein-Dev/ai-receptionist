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
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 100)->unique()->comment('Twilio CallSid or Telegram chat_id');
            $table->foreignId('patient_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('channel', ['telegram', 'twilio', 'cli']);
            $table->string('from_number', 50)->nullable();
            $table->string('to_number', 50)->nullable();
            $table->enum('status', ['initiated', 'in_progress', 'completed', 'failed', 'abandoned'])->default('initiated');
            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->enum('outcome', [
                'appointment_booked',
                'appointment_cancelled',
                'appointment_rescheduled',
                'inquiry_answered',
                'transferred',
                'abandoned',
                'failed'
            ])->nullable();
            $table->string('intent_detected', 50)->nullable();
            $table->decimal('sentiment_score', 3, 2)->nullable();
            $table->string('recording_path', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('patient_id');
            $table->index(['channel', 'status']);
            $table->index('created_at');
            $table->index('outcome');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
