<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tombstone column: media rows are flagged for remote deletion inside the
     * transaction, and the queued job removes the ImageKit file after commit.
     * A rollback therefore cannot strand a deleted CDN file.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->timestamp('imagekit_pending_deletion_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            // SQLite does not drop dependent indexes when a column is
            // dropped, so the index must go first or ALTER TABLE fails.
            $table->dropIndex(['imagekit_pending_deletion_at']);
            $table->dropColumn('imagekit_pending_deletion_at');
        });
    }
};
