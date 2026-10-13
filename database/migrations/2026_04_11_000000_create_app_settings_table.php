<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('key', 100)->unique();
                $table->string('value', 255)->nullable();
                $table->string('description', 500)->nullable();
                $table->timestamps();
            });
        }

        // Seed the capitalization threshold from the current config default.
        $exists = DB::table('app_settings')
            ->where('key', 'rpcppe_capitalization_threshold')
            ->exists();

        if (! $exists) {
            DB::table('app_settings')->insert([
                'key'         => 'rpcppe_capitalization_threshold',
                'value'       => (string) config('rpcppe.capitalization_threshold', 50000),
                'description' => 'PPE capitalization threshold (PHP). Items at or above this unit cost are PPE; below are semi-expendable.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
