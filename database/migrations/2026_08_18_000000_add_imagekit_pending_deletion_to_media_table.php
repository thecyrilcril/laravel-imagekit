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
     *
     * Guarded on both sides because this migration can legitimately run twice.
     * The migrator sorts by filename, so on a fresh app this vendor-dated file
     * runs before the `create_media_table` migration that `imagekit:install`
     * publishes today — at which point there is no table to alter. The install
     * command also publishes a copy of this file timestamped after the table
     * migration, so whichever copy meets an existing table without the column
     * adds it, and the other one does nothing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('media') || Schema::hasColumn('media', 'imagekit_pending_deletion_at')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->timestamp('imagekit_pending_deletion_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media') || ! Schema::hasColumn('media', 'imagekit_pending_deletion_at')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            // SQLite does not drop dependent indexes when a column is
            // dropped, so the index must go first or ALTER TABLE fails.
            $table->dropIndex(['imagekit_pending_deletion_at']);
            $table->dropColumn('imagekit_pending_deletion_at');
        });
    }
};
