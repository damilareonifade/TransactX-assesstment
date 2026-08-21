<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reference is a globally unique payment-provider event id, so the index
     * is not scoped to wallet_id: replaying one event against a different wallet
     * is still a duplicate. Added as a separate migration rather than by editing
     * the original, which has already shipped. On a live database that has
     * recorded duplicates, the rows have to be reconciled before this will apply.
     */
    public function up(): void
    {
        Schema::table('wallet_fundings', function (Blueprint $table) {
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_fundings', function (Blueprint $table) {
            $table->dropUnique(['reference']);
        });
    }
};
