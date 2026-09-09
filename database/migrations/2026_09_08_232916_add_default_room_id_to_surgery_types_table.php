<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgery_types', function (Blueprint $table) {
            $table->foreignId('default_room_id')->nullable()->after('required_specialty')
                ->constrained('operating_rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('surgery_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_room_id');
        });
    }
};
