<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('rpcppe', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->after('ID');
            $table->index('item_id', 'rpcppe_item_id_index');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table('rpcppe', function (Blueprint $table) {
            $table->dropIndex('rpcppe_item_id_index');
            $table->dropColumn('item_id');
        });
    }
};
