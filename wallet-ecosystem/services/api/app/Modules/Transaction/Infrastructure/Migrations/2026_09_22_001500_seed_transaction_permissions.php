<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        $permissions = [
            ['slug' => 'transaction.read',            'display_name' => 'View Transaction History',        'module' => 'transaction'],
            ['slug' => 'transaction.read.all',        'display_name' => 'View All Transactions (Admin)',   'module' => 'transaction'],
            ['slug' => 'transaction.receipt.read',    'display_name' => 'View Transaction Receipt',        'module' => 'transaction'],
            ['slug' => 'transaction.receipt.share',   'display_name' => 'Share Transaction Receipt',       'module' => 'transaction'],
            ['slug' => 'transaction.reversal.request','display_name' => 'Request Transaction Reversal',    'module' => 'transaction'],
            ['slug' => 'transaction.reversal.approve','display_name' => 'Approve Transaction Reversal',    'module' => 'transaction'],
            ['slug' => 'transaction.reversal.reject', 'display_name' => 'Reject Transaction Reversal',     'module' => 'transaction'],
            ['slug' => 'transaction.reversal.execute','display_name' => 'Execute Transaction Reversal',    'module' => 'transaction'],
            ['slug' => 'transaction.reversal.audit',  'display_name' => 'View Reversal Audit Log',         'module' => 'transaction'],
            ['slug' => 'transaction.refund.initiate', 'display_name' => 'Initiate Transaction Refund',     'module' => 'transaction'],
        ];

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'           => (string) Uuid::uuid7(),
                'slug'         => $perm['slug'],
                'display_name' => $perm['display_name'],
                'module'       => $perm['module'],
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', [
                'transaction.read',
                'transaction.read.all',
                'transaction.receipt.read',
                'transaction.receipt.share',
                'transaction.reversal.request',
                'transaction.reversal.approve',
                'transaction.reversal.reject',
                'transaction.reversal.execute',
                'transaction.reversal.audit',
                'transaction.refund.initiate',
            ])
            ->delete();
    }
};
