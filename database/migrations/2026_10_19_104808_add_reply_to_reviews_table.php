<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A public answer from the shop under a review.
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('reply')->nullable()->after('body');
            $table->timestamp('replied_at')->nullable()->after('reply');
            $table->foreignUuid('replied_by')->nullable()->after('replied_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replied_by');
            $table->dropColumn(['reply', 'replied_at']);
        });
    }
};
