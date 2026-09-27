<?php

use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only: the original create_users_table migration is left untouched so
     * the migration history stays truthful and no existing data is dropped.
     *
     * role_id is nullable so this migration is safe to apply to a populated users
     * table. Users without a role cannot authenticate (enforced in the
     * AuthenticationService) until an administrator assigns one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->restrictOnDelete();
            $table->string('status')->default(UserStatus::ACTIVE->value)->after('password')->index();
            $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'status', 'last_login_at']);
        });
    }
};
