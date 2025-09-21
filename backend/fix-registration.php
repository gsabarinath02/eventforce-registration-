<?php
/**
 * Fix Registration Script
 * 
 * This script fixes the registration issue by ensuring a default AccountConfiguration exists.
 * Run this if you're getting "An unexpected error occurred" during registration.
 * 
 * Usage: php fix-registration.php
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel environment
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->boot();

use Illuminate\Support\Facades\DB;

echo "Hi.Events Registration Fix Script\n";
echo "=================================\n\n";

try {
    // Check database connection
    echo "1. Checking database connection...\n";
    DB::connection()->getPdo();
    echo "   ✓ Database connection successful\n\n";
    
    // Check if account_configuration table exists
    echo "2. Checking account_configuration table...\n";
    if (!DB::getSchemaBuilder()->hasTable('account_configuration')) {
        echo "   ✗ account_configuration table does not exist\n";
        echo "   Please run: php artisan migrate\n";
        exit(1);
    }
    echo "   ✓ account_configuration table exists\n\n";
    
    // Check for default AccountConfiguration
    echo "3. Checking for default AccountConfiguration...\n";
    $existingDefault = DB::table('account_configuration')
        ->where('is_system_default', true)
        ->first();
    
    if ($existingDefault) {
        echo "   ✓ Default AccountConfiguration already exists (ID: {$existingDefault->id})\n";
        echo "   Registration should be working properly.\n\n";
        
        // Check if registration is disabled
        echo "4. Checking registration settings...\n";
        $registrationDisabled = env('APP_DISABLE_REGISTRATION', false);
        if ($registrationDisabled === true || $registrationDisabled === 'true') {
            echo "   ✗ Registration is disabled in your environment variables\n";
            echo "   Set APP_DISABLE_REGISTRATION=false in your .env file\n";
        } else {
            echo "   ✓ Registration is enabled\n";
        }
        
        echo "\nIf you're still having registration issues, check the application logs for more details.\n";
        exit(0);
    }
    
    echo "   ✗ No default AccountConfiguration found\n";
    echo "   Creating default AccountConfiguration...\n";
    
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
    
    echo "   ✓ Default AccountConfiguration created successfully!\n\n";
    
    // Check registration setting
    echo "4. Checking registration settings...\n";
    $registrationDisabled = env('APP_DISABLE_REGISTRATION', false);
    if ($registrationDisabled === true || $registrationDisabled === 'true') {
        echo "   ✗ Registration is disabled in your environment variables\n";
        echo "   Set APP_DISABLE_REGISTRATION=false in your .env file to enable registration\n";
    } else {
        echo "   ✓ Registration is enabled\n";
    }
    
    echo "\n✓ Registration fix completed successfully!\n";
    echo "You should now be able to register new accounts.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "1. Make sure the database is running and accessible\n";
    echo "2. Run 'php artisan migrate' to ensure all tables exist\n";
    echo "3. Check your database connection settings in .env\n";
    exit(1);
}
