<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an employee lives.
     *
     * Every column is nullable and nothing is back-filled: an employee saved
     * before this has no address, and none is guessed. Each pick-list code sits
     * beside the name it stood for when it was picked, so a printed record and
     * a retired list entry both still read correctly. The sub-city column holds
     * an Addis Ababa sub-city's name or, anywhere else, a zone's.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('address_region_code', 60)->nullable();
            $table->string('address_region_text', 100)->nullable();
            $table->string('address_sub_city_code', 60)->nullable();
            $table->string('address_zone_or_sub_city_text', 100)->nullable();
            $table->string('address_town_code', 60)->nullable();
            $table->string('address_town_text', 100)->nullable();
            $table->string('address_woreda', 50)->nullable();
            $table->string('address_kebele', 50)->nullable();
            $table->string('address_house_number', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'address_region_code',
                'address_region_text',
                'address_sub_city_code',
                'address_zone_or_sub_city_text',
                'address_town_code',
                'address_town_text',
                'address_woreda',
                'address_kebele',
                'address_house_number',
            ]);
        });
    }
};
