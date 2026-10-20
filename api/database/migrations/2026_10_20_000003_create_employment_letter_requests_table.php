<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employment letters an employee asks HR for: confirmation of employment,
 * with salary when asked for (blueprint assessment ESS7). HR issues or
 * declines each; an issued letter is generated as a PDF the employee
 * downloads, with a reference number unique within the organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_letter_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 255);
            $table->string('addressed_to', 255)->nullable();
            $table->boolean('include_salary')->default(false);
            $table->string('language', 2)->default('en');
            $table->string('status', 20)->default('pending');
            $table->string('reference', 40)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'letter_requests_tenant_status');
            $table->unique(['tenant_id', 'reference'], 'letter_requests_tenant_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_letter_requests');
    }
};
