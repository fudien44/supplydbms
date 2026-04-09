<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
     public function up(): void
    {
        Schema::create('rpcppe', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('com_desc')->nullable();              // ✅ was string('com_desc', 255)
            $table->string('unitofmeasure', 255)->nullable();  // ✅ was string('unitofmeasure', 50)
            $table->string('po_no', 255)->nullable();          // ✅ was string('po_no', 100)
            $table->date('date_acquired')->nullable();
            $table->decimal('u_cost', 15, 2)->nullable();
            $table->integer('qty')->nullable();
            $table->string('stock_no', 255)->nullable();       // ✅ was string('stock_no', 100)
            $table->string('source', 500)->nullable();         // ✅ was string('source', 150)
            $table->string('emp_no', 100)->nullable();         // ✅ was string('emp_no', 50)
            $table->string('issuance_type', 50)->nullable();   // ✅ was string('issuance_type', 10)
            $table->unsignedBigInteger('issuance_id')->nullable();
            $table->decimal('amt', 15, 2)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('rpcppe');
    }
};