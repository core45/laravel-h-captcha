<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            // Whether the package accepted the submission. `success` stays
            // hCaptcha's own verdict; the two differ on a local rejection
            // (success true, accepted false) and on a fail-open outage
            // (success false, accepted true).
            $table->boolean('accepted')->default(false)->index()->after('success');
        });

        // Rows written by 1.x stored the final answer in `success`.
        DB::connection($this->connection())
            ->table($this->table())
            ->update(['accepted' => DB::raw('success')]);
    }

    public function down(): void
    {
        Schema::connection($this->connection())->table($this->table(), function (Blueprint $table): void {
            $table->dropIndex([$this->indexName()]);
            $table->dropColumn('accepted');
        });
    }

    private function indexName(): string
    {
        return 'accepted';
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
