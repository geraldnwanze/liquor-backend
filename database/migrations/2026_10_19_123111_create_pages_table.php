<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Editable information pages (delivery, FAQs, about...). Staff with content.manage write them.
        Schema::create('pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 150);
            $table->string('slug', 120)->unique();
            $table->text('body');
            $table->string('meta_description', 300)->nullable();
            $table->string('status', 16)->default('draft');
            $table->boolean('show_in_footer')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'show_in_footer', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
