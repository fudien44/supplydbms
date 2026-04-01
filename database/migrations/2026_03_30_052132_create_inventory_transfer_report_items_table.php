<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('inventory_transfer_report_items', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('transfer_report_id'); // link to main table, FK optional later

            $table->date('date_acquired')->nullable();
            $table->string('reference')->nullable(); // PO / ICS No.
            $table->longText('description')->nullable();

            $table->integer('qty')->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0); // per-item amount

            $table->string('item_condition')->nullable();
            $table->bigInteger('inventory_id')->nullable(); // optional

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('inventory_transfer_report_items');
    }
};