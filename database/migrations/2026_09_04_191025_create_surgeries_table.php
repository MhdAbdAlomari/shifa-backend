<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('surgeries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('surgeon_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('operating_rooms')->restrictOnDelete();
            $table->foreignId('surgery_type_id')->constrained('surgery_types')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->enum('priority', ['normal', 'emergency'])->default('normal');
            $table->dateTime('scheduled_start');
            $table->integer('estimated_duration_min');
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled', 'delayed'])->default('scheduled');
            $table->timestamps();

            $table->index('scheduled_start');
            $table->index('status');
            $table->index(['room_id', 'scheduled_start']);
            $table->index(['surgeon_id', 'scheduled_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgeries');
    }
};
