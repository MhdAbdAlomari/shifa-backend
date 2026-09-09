<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delay_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surgery_id')->constrained('surgeries')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('new_expected_end');
            $table->text('reason');
            $table->boolean('auto_approved');
            $table->timestamp('created_at')->nullable();

            $table->index('surgery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delay_requests');
    }
};
