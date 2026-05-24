<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            // 'weekly' | 'monthly' | 'never' — cast to App\Enums\ScanCadence
            // on the Domain model. Drives DispatchScheduledScans.
            $table->string('scan_cadence', 16)->default('weekly')->after('status');
            $table->timestamp('last_scanned_at')->nullable()->after('scan_cadence');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['scan_cadence', 'last_scanned_at']);
        });
    }
};
