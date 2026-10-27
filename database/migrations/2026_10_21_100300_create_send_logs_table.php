<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('send_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // Vide pour les événements de campagne (import, interruption détectée…).
            $table->foreignId('recipient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('send_logs');
    }
};
