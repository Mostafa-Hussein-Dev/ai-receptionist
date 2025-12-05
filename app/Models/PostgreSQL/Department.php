<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get all doctors in this department
     */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }

    /**
     * Get active doctors in this department
     */
    public function activeDoctors(): HasMany
    {
        return $this->hasMany(Doctor::class)->where('is_active', true);
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\DepartmentFactory::new();
    }
}
