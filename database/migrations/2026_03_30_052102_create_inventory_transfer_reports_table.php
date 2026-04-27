<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('inventory_transfer_reports', function (Blueprint $table) {
            $table->id();
            $table->string('itr_no')->unique();
            $table->string('entity_name');
            $table->string('fund_cluster');

            $table->string('from_entity', 255)->nullable();
            $table->string('to_entity', 255)->nullable();

            $table->date('date_issued');

            $table->bigInteger('transfer_type_id')->nullable();
            $table->text('others_specify')->nullable();

            $table->text('reason')->nullable();

            $table->bigInteger('approved_by')->nullable();
            $table->bigInteger('issued_by')->nullable();
            $table->bigInteger('received_by')->nullable();

            $table->string('status')->default('pending');

            $table->decimal('total_amount', 15, 2)->default(0); // total of all items

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('inventory_transfer_reports');
    }
};
