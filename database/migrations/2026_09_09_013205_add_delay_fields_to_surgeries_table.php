<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgeries', function (Blueprint $table) {
            // When set, overrides the computed (scheduled_start + estimated_duration_min)
            // end time for overlap/availability calculations. Set by an auto-approved
            // delay request. estimated_duration_min is left untouched as the original plan.
            $table->dateTime('delayed_end_at')->nullable()->after('estimated_duration_min');
            $table->text('delay_reason')->nullable()->after('delayed_end_at');
        });
    }

    public function down(): void
    {
        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropColumn(['delayed_end_at', 'delay_reason']);
        });
    }
};
