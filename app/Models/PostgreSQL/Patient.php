<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_record_number',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'phone',
        'email',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'blood_type',
        'allergies',
        'medical_notes',
        'metadata',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'medical_notes',
        'allergies',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate MRN on creation
        static::creating(function ($patient) {
            if (empty($patient->medical_record_number)) {
                $patient->medical_record_number = self::generateMRN();
            }
        });
    }

    /**
     * Generate a unique Medical Record Number
     */
    private static function generateMRN(): string
    {
        do {
            $mrn = 'MRN' . date('Y') . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (self::where('medical_record_number', $mrn)->exists());

        return $mrn;
    }

    /**
     * Get all appointments for this patient
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get all calls from this patient
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /**
     * Get patient's full name
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get patient's age
     */
    public function getAgeAttribute(): int
    {
        return $this->date_of_birth->age;
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\PatientFactory::new();
    }
}
