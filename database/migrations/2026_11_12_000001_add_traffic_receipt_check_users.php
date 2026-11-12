<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_traffic_report_receipt', function (Blueprint $table) {
            $table->json('check_user_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_traffic_report_receipt', function (Blueprint $table) {
            $table->dropColumn('check_user_ids');
        });
    }
};
