<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_room_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('operating_rooms')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday .. 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['room_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_room_slots');
    }
};
