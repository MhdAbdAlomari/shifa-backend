<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of every delay request a surgeon makes, regardless of outcome.
 * Separate from ScheduleSuggestion, which drives the live approval workflow.
 */
class DelayRequest extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'surgery_id',
        'requested_by',
        'new_expected_end',
        'reason',
        'auto_approved',
    ];

    protected $casts = [
        'new_expected_end' => 'datetime',
        'auto_approved' => 'boolean',
    ];

    public function surgery(): BelongsTo
    {
        return $this->belongsTo(Surgery::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
