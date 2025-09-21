<?php

namespace HiEvents\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixAccountConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hi-events:fix-account-configuration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensures a default AccountConfiguration exists for registration to work';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for default AccountConfiguration...');
        
        // Check if default configuration already exists
        $existingDefault = DB::table('account_configuration')
            ->where('is_system_default', true)
            ->first();

        if ($existingDefault) {
            $this->info('Default AccountConfiguration already exists.');
            return self::SUCCESS;
        }

        $this->warn('No default AccountConfiguration found. Creating one...');

        try {
            // Create default account configuration
            DB::table('account_configuration')->insert([
                'name' => 'Default',
                'is_system_default' => true,
                'application_fees' => json_encode([
                    'percentage' => config('app.saas_stripe_application_fee_percent', 1.5),
                    'fixed' => config('app.saas_stripe_application_fee_fixed', 0),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info('✓ Default AccountConfiguration created successfully!');
            $this->info('Registration should now work properly.');
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create default AccountConfiguration: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
