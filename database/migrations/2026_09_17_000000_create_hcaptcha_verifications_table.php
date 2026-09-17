<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->id();

            $table->boolean('success')->index();

            // SHA-256 of the token, never the token itself: it is a single-use
            // credential. Indexed so a replayed token is one query away.
            $table->string('token_hash', 64)->nullable()->index();

            $table->string('hostname')->nullable();
            $table->timestamp('challenge_ts')->nullable();

            // Publisher/Pro accounts only: a risk score where higher is more
            // bot-like. Null on accounts without scoring.
            $table->decimal('score', 5, 4)->nullable();

            $table->json('error_codes')->nullable();

            // Set when hCaptcha said yes but a local assertion said no
            // (hostname-mismatch, score-too-high).
            $table->string('rejected_by', 64)->nullable();

            // Personal data, written only when the matching config toggle is on.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->text('url')->nullable();

            $table->timestamps();

            // The prune command and any retention report both scan by age.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('hcaptcha.logging.table', 'hcaptcha_verifications');
    }

    private function connection(): ?string
    {
        $connection = config('hcaptcha.logging.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
};
