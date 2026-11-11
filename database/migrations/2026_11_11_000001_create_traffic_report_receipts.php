<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_traffic_report_receipt', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id');
            $table->string('report_id', 32);
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['server_id', 'report_id'], 'traffic_receipt_server_report_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_traffic_report_receipt');
    }
};
