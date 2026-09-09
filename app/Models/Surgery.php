<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Surgery extends Model
{
    use HasFactory;

    protected $table = 'surgeries';

    protected $fillable = [
        'patient_id',
        'surgeon_id',
        'room_id',
        'surgery_type_id',
        'created_by',
        'priority',
        'scheduled_start',
        'estimated_duration_min',
        'delayed_end_at',
        'delay_reason',
        'actual_start',
        'actual_end',
        'status',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'delayed_end_at' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'estimated_duration_min' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surgeon_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(OperatingRoom::class, 'room_id');
    }

    public function surgeryType(): BelongsTo
    {
        return $this->belongsTo(SurgeryType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scheduleSuggestions(): HasMany
    {
        return $this->hasMany(ScheduleSuggestion::class);
    }

    public function delayRequests(): HasMany
    {
        return $this->hasMany(DelayRequest::class);
    }

    /**
     * Effective end of the scheduled window, used for ALL overlap/availability
     * calculations and exposed via SurgeryResource as `scheduled_end`.
     *
     * If an auto-approved delay request set `delayed_end_at`, that overrides
     * the computed (scheduled_start + estimated_duration_min) value — the
     * original estimated_duration_min is left untouched as an audit trail of
     * the original plan.
     */
    public function getScheduledEndAttribute(): ?\Illuminate\Support\Carbon
    {
        if ($this->delayed_end_at) {
            return $this->delayed_end_at->copy();
        }

        if (! $this->scheduled_start || ! $this->estimated_duration_min) {
            return null;
        }
        return $this->scheduled_start->copy()->addMinutes($this->estimated_duration_min);
    }
}
