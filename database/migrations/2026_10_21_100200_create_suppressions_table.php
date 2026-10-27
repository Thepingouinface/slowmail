<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            // Adresse complète, ou domaine sans le « @ ».
            $table->string('value');
            $table->string('type');
            $table->string('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
