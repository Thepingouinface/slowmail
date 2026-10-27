<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Échecs d'envoi consécutifs (disjoncteur) : remis à zéro par un envoi réussi ou à la reprise.
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('planned_for');
            // Pourquoi la campagne est en pause (disjoncteur, modèle devenu invalide…), pour le bandeau.
            $table->text('pause_reason')->nullable()->after('consecutive_failures');
            // Sert à `slowmail:purge`.
            $table->dateTime('finished_at')->nullable()->after('pause_reason');
        });

        // Petit état partagé entre le scheduler et l'interface : dernier tick, prochain envoi prévu.
        Schema::create('scheduler_state', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_state');

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['consecutive_failures', 'pause_reason', 'finished_at']);
        });
    }
};
