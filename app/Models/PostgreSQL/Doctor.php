<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'specialization',
        'slots_per_appointment',
        'max_appointments_per_day',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'slots_per_appointment' => 'integer',
        'max_appointments_per_day' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get the department this doctor belongs to
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get all schedules for this doctor
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    /**
     * Get all schedule exceptions for this doctor
     */
    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(DoctorScheduleException::class);
    }

    /**
     * Get all slots for this doctor
     */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }

    /**
     * Get all appointments for this doctor
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get doctor's full name
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get appointment duration in minutes
     */
    public function getAppointmentDurationAttribute(): int
    {
        return $this->slots_per_appointment * 15;
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\DoctorFactory::new();
    }
}
