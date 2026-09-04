<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operating_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('status', ['free', 'preparing', 'in_use', 'cleaning'])->default('free');
            $table->string('supported_specialty')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('supported_specialty');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_rooms');
    }
};
