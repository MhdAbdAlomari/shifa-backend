<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'surgery_id',
        'suggested_room_id',
        'suggested_start',
        'reason',
        'status',
    ];

    protected $casts = [
        'suggested_start' => 'datetime',
    ];

    public function surgery(): BelongsTo
    {
        return $this->belongsTo(Surgery::class);
    }

    public function suggestedRoom(): BelongsTo
    {
        return $this->belongsTo(OperatingRoom::class, 'suggested_room_id');
    }
}
