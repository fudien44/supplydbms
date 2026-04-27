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
        Schema::table('rpcppe', function (Blueprint $table) {
            if (!Schema::hasColumn('rpcppe', 'remarks')) {
                $table->text('remarks')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rpcppe', function (Blueprint $table) {
            if (Schema::hasColumn('rpcppe', 'remarks')) {
                $table->dropColumn('remarks');
            }
        });
    }
};
