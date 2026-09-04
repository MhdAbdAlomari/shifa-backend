<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperatingRoom extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status', 'supported_specialty'];

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class, 'room_id');
    }
}
