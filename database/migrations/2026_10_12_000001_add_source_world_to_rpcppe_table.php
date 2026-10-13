<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: the column may already exist on environments where it was
        // added ad-hoc (e.g. the original local dev DB).
        if (!Schema::connection('sqlsrv')->hasColumn('rpcppe', 'source_world')) {
            Schema::connection('sqlsrv')->table('rpcppe', function (Blueprint $table) {
                // 'W1' (consumables/supply) or 'W2' (office supplies/property - MR).
                // Disambiguates item_id, which is a separate number space per world.
                $table->string('source_world', 8)->nullable()->after('item_id');
            });
        }

        // Existing rows predate the two-worlds split — default them to World 2 (MR).
        DB::connection('sqlsrv')->table('rpcppe')
            ->whereNull('source_world')
            ->update(['source_world' => 'W2']);
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table('rpcppe', function (Blueprint $table) {
            $table->dropColumn('source_world');
        });
    }
};
