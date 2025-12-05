<?php

namespace App\Models\PostgreSQL;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Call extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'patient_id',
        'channel',
        'from_number',
        'to_number',
        'status',
        'direction',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'outcome',
        'intent_detected',
        'sentiment_score',
        'recording_path',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'sentiment_score' => 'decimal:2',
        'metadata' => 'array',
    ];

    /**
     * Get the patient for this call
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get appointments created during this call
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Check if call is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Check if call is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Get call duration formatted
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) {
            return '0:00';
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Scope: Get calls by channel
     */
    public function scopeByChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope: Get completed calls
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
    protected static function newFactory()
    {
        return \Database\Factories\PostgreSQL\CallFactory::new();
    }
}
