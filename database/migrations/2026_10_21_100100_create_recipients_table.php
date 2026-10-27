<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // Ordre d'envoi : ordre du CSV, ou colonne PRIORITE.
            $table->unsignedInteger('position');
            $table->string('email');
            $table->string('domain')->index();
            $table->json('data')->nullable();
            $table->string('status')->default('pending');
            $table->dateTime('planned_at')->nullable();
            // Posé dans la transaction qui précède l'appel SMTP (addendum §3).
            $table->dateTime('sending_started_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('message_id')->nullable();
            $table->string('reply_status')->nullable();
            $table->text('notes')->nullable();
            $table->text('rendered_subject')->nullable();
            $table->text('rendered_body')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status', 'planned_at']);
            $table->index(['campaign_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipients');
    }
};
