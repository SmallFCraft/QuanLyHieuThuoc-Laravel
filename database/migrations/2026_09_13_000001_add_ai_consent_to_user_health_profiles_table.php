<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_health_profiles', function (Blueprint $table) {
            // Purpose-specific: consenting to a pharmacist reading the profile is not
            // consent to feeding it to an AI provider.
            $table->timestamp('ai_consent_at')->nullable()->after('consented_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_health_profiles', function (Blueprint $table) {
            $table->dropColumn('ai_consent_at');
        });
    }
};
