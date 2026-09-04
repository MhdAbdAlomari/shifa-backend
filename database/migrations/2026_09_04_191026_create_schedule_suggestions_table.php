<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgery_id')->constrained('surgeries')->cascadeOnDelete();
            $table->foreignId('suggested_room_id')->constrained('operating_rooms')->restrictOnDelete();
            $table->dateTime('suggested_start');
            $table->string('reason');
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_suggestions');
    }
};
