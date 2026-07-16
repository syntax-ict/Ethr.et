<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees') || DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            'ALTER TABLE `employees` ADD FULLTEXT INDEX `emp_search` (`name`, `name_am`, `email`, `employee_code`)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees') || DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `employees` DROP INDEX `emp_search`');
    }
};
