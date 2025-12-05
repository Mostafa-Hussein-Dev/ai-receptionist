<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'call_id',
        'date',
        'start_time',
        'end_time',
        'slot_count',
        'status',
        'type',
        'reason',
        'notes',
        'cancelled_at',
        'cancellation_reason',
        'reminder_sent',
        'confirmation_sent',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'slot_count' => 'integer',
        'cancelled_at' => 'datetime',
        'reminder_sent' => 'boolean',
        'confirmation_sent' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get the patient for this appointment
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the doctor for this appointment
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the call that created this appointment
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /**
     * Get all slots for this appointment
     */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }

    /**
     * Check if appointment is scheduled
     */
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /**
     * Check if appointment is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Check if appointment is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Get duration in minutes
     */
    public function getDurationAttribute(): int
    {
        return $this->slot_count * 15;
    }

    /**
     * Scope: Get upcoming appointments
     */
    public function scopeUpcoming($query)
    {
        return $query->where('date', '>=', now()->toDateString())
            ->whereIn('status', ['scheduled', 'confirmed']);
    }

    /**
     * Scope: Get appointments for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\AppointmentFactory::new();
    }
}
