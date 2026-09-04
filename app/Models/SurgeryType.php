<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurgeryType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'average_duration_min', 'required_specialty'];

    protected $casts = [
        'average_duration_min' => 'integer',
    ];

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }
}
