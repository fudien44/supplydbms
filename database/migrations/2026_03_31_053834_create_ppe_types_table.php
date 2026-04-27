<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePPETypesTable extends Migration
{
    public function up()
    {
        Schema::create('ppe_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ppe_name')->unique(); 
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ppe_types');
    }
}