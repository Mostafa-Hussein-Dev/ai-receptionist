<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorScheduleException extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'date',
        'type',
        'start_time',
        'end_time',
        'reason',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Get the doctor for this exception
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Check if this is a day off
     */
    public function isDayOff(): bool
    {
        return $this->type === 'day_off';
    }

    /**
     * Check if this is custom hours
     */
    public function isCustomHours(): bool
    {
        return $this->type === 'custom_hours';
    }

    /**
     * Scope: Get exceptions for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    /**
     * Scope: Get day off exceptions
     */
    public function scopeDaysOff($query)
    {
        return $query->where('type', 'day_off');
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\DoctorScheduleExceptionFactory::new();
    }
}
