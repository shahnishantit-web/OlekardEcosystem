<?php

declare(strict_types=1);

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(), 'slug' => EventPermissions::CURRENCY_ASSIGN,
            'display_name' => 'Events currency assign', 'module' => 'events',
            'is_system_permission' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::connection('identity')->table('identity.permissions')->where('slug', EventPermissions::CURRENCY_ASSIGN)->value('id');
        if ($id !== null && ! DB::connection('identity')->table('identity.role_permissions')->where('permission_id', $id)->exists()) {
            DB::connection('identity')->table('identity.permissions')->where('id', $id)->delete();
        }
    }
};
