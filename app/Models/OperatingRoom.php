<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class OperatingRoom extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status', 'supported_specialty', 'image_path'];

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class, 'room_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(OperatingRoomSlot::class, 'room_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
