<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatingRoomSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(OperatingRoom::class, 'room_id');
    }
}
