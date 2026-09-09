<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurgeryType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'average_duration_min', 'required_specialty', 'default_room_id'];

    protected $casts = [
        'average_duration_min' => 'integer',
    ];

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }

    public function defaultRoom(): BelongsTo
    {
        return $this->belongsTo(OperatingRoom::class, 'default_room_id');
    }
}
