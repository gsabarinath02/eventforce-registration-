<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountConfigurationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if default configuration already exists
        $existingDefault = DB::table('account_configuration')
            ->where('is_system_default', true)
            ->first();

        if ($existingDefault) {
            return; // Default configuration already exists
        }

        // Create default account configuration
        DB::table('account_configuration')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => json_encode([
                'percentage' => config('app.saas_stripe_application_fee_percent', 1.5),
                'fixed' => config('app.saas_stripe_application_fee_fixed', 0),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
