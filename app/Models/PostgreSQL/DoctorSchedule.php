<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_available',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_available' => 'boolean',
        'start_time' => 'datetime:H:i:s',
        'end_time' => 'datetime:H:i:s',
    ];

    /**
     * Get the doctor for this schedule
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Get the day name
     */
    public function getDayNameAttribute(): string
    {
        $days = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];

        return $days[$this->day_of_week] ?? 'Unknown';
    }

    /**
     * Check if this is a weekday schedule
     */
    public function isWeekday(): bool
    {
        return $this->day_of_week >= 1 && $this->day_of_week <= 5;
    }

    /**
     * Check if this is a weekend schedule
     */
    public function isWeekend(): bool
    {
        return $this->day_of_week === 0 || $this->day_of_week === 6;
    }

    /**
     * Scope: Get schedules for a specific day
     */
    public function scopeForDay($query, int $dayOfWeek)
    {
        return $query->where('day_of_week', $dayOfWeek);
    }

    /**
     * Scope: Get only available schedules
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope: Get weekday schedules
     */
    public function scopeWeekdays($query)
    {
        return $query->whereBetween('day_of_week', [1, 5]);
    }

    /**
     * Scope: Get weekend schedules
     */
    public function scopeWeekends($query)
    {
        return $query->whereIn('day_of_week', [0, 6]);
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\DOctorScheduleFactory::new();
    }
}
