<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Settings → Connections: what was set up there (each service's key, a
 * note when one stopped working, a paid library's account tokens), each
 * value encrypted with the app's key. Without this table, Ghostwriter keeps
 * them encrypted in storage/ghostwriter/credentials/ instead, and moves
 * them here the first time it finds the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ghostwriter_credentials')) {
            return;
        }

        Schema::create('ghostwriter_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191)->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghostwriter_credentials');
    }
};
