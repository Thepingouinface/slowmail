<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Dernier jour (dans le fuseau de la campagne) dont le plan a été tiré.
            // Réclamé atomiquement : un redémarrage ou un second tick ne retire jamais les heures du même jour.
            $table->date('planned_for')->nullable()->after('end_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('planned_for');
        });
    }
};
