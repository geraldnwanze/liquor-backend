<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            // draft | active | archived | discontinued
            $table->string('status', 20)->default('draft');
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('country_of_origin', 80)->nullable();
            $table->decimal('abv', 4, 1)->nullable();
            $table->json('flavor_profile')->nullable();
            $table->text('tasting_notes')->nullable();
            $table->text('serving_suggestions')->nullable();
            // Only populated when the business has verified sourcing; never auto-generated.
            $table->text('authenticity_note')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_age_restricted')->default(true);
            $table->boolean('is_demo')->default(false);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->index(['status', 'category_id']);
            $table->index(['status', 'brand_id']);
            $table->index(['status', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
