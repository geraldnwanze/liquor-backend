<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An uploaded file wins over a pasted https address (`image_url` / `logo_url`), as with product photos.
        Schema::table('categories', fn (Blueprint $t) => $t->string('image_path', 255)->nullable()->after('image_url'));
        Schema::table('brands', fn (Blueprint $t) => $t->string('logo_path', 255)->nullable()->after('logo_url'));
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn('image_path'));
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn('logo_path'));
    }
};
