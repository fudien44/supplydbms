<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPpeTypeIdToArticlesTable extends Migration
{
    public function up()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unsignedBigInteger('ppe_type_id')->nullable();

            $table->foreign('ppe_type_id')
                ->references('id')
                ->on('ppe_types')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropForeign(['ppe_type_id']);
            $table->dropColumn('ppe_type_id');
        });
    }
}