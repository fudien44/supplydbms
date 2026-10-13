<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCategoryClassificationsTable extends Migration
{
    public function up()
    {
        Schema::create('category_classifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            // 'W1' (consumables/supply) or 'W2' (office supplies/property - MR)
            $table->string('world', 2);
            // The identifier used to match stock rows: for W1 this is the
            // ref_commoditycategory.categ_code (= trans_gen_inventory.typeofcomid),
            // for W2 this is tbl_mrcategory.Category_ID.
            $table->string('category_code', 50);
            $table->string('category_name', 255)->nullable();
            // 'PPE' = eligible for RPCPPE/RPCSP; 'RPCI' = inventory/consumable.
            $table->string('report_type', 8)->default('PPE');
            $table->boolean('is_active')->default(1);
            $table->timestamps();

            $table->unique(['world', 'category_code']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('category_classifications');
    }
}
