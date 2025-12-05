<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Slot extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'date',
        'slot_number',
        'start_time',
        'end_time',
        'status',
        'appointment_id',
        'blocked_reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Get the doctor this slot belongs to
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the appointment for this slot (if booked)
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Check if slot is available
     */
    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    /**
     * Check if slot is booked
     */
    public function isBooked(): bool
    {
        return $this->status === 'booked';
    }

    /**
     * Check if slot is blocked
     */
    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    /**
     * Scope: Get available slots
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    /**
     * Scope: Get slots for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    /**
     * Scope: Get slots for a specific doctor
     */
    public function scopeForDoctor($query, $doctorId)
    {
        return $query->where('doctor_id', $doctorId);
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\SlotFactory::new();
    }

}
