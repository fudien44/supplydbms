<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_transfer_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_report_id')
                  ->constrained('inventory_transfer_reports')
                  ->onDelete('cascade'); 
            $table->string('filename');
            $table->string('filepath');
            $table->string('filetype')->nullable();
            $table->unsignedBigInteger('filesize')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_attachments');
    }
};
