<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('surgery_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('average_duration_min');
            $table->string('required_specialty')->nullable();
            $table->timestamps();

            $table->index('required_specialty');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_types');
    }
};
