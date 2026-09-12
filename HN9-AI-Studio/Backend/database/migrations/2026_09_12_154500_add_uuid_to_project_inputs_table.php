<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_inputs', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique();
        });

        DB::table('project_inputs')->orderBy('id')->each(function (object $row): void {
            DB::table('project_inputs')->where('id', $row->id)->update([
                'uuid' => (string) Str::uuid(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('project_inputs', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
