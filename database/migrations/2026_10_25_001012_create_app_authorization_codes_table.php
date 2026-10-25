<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The one-time codes of the app authorisation (/app/verbinden, AppAuthorizationCode): only the SHA-256 hash of the
     * code is stored, it is valid for five minutes and once. Pruned daily, and deleted with the account.
     */
    public function up(): void
    {
        Schema::create('app_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_id', 100);
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_authorization_codes');
    }
};
