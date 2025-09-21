<?php

namespace HiEvents\Console\Commands;

use HiEvents\Services\Infrastructure\Configuration\ConfigurationValidator;
use Illuminate\Console\Command;

class ValidateConfigurationCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'config:validate 
                            {--payment-providers : Only validate payment provider configurations}
                            {--razorpay : Only validate Razorpay configuration}
                            {--stripe : Only validate Stripe configuration}';

    /**
     * The console command description.
     */
    protected $description = 'Validate application configuration, especially payment providers';

    public function __construct(
        private readonly ConfigurationValidator $configurationValidator,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Validating application configuration...');

        try {
            if ($this->option('razorpay')) {
                $this->validateRazorpayOnly();
            } elseif ($this->option('stripe')) {
                $this->validateStripeOnly();
            } else {
                $this->validateAllConfiguration();
            }

            $this->displayValidationSummary();
            
            $this->info('Configuration validation completed successfully!');
            return Command::SUCCESS;

        } catch (\Throwable $exception) {
            $this->error('Configuration validation failed: ' . $exception->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateRazorpayOnly(): void
    {
        $this->info('Validating Razorpay configuration...');
        
        if (!config('services.razorpay.key_id')) {
            $this->warn('Razorpay is not configured (RAZORPAY_KEY_ID not set)');
            return;
        }

        // This will throw an exception if validation fails
        $razorpayConfig = new \HiEvents\Services\Infrastructure\Razorpay\RazorpayConfigurationService(
            app('config')
        );
        $razorpayConfig->validateConfiguration();
        
        $this->info('✓ Razorpay configuration is valid');
    }

    private function validateStripeOnly(): void
    {
        $this->info('Validating Stripe configuration...');
        
        if (!config('services.stripe.secret_key')) {
            $this->warn('Stripe is not configured (STRIPE_SECRET_KEY not set)');
            return;
        }

        $requiredKeys = [
            'secret_key' => 'STRIPE_SECRET_KEY',
            'public_key' => 'STRIPE_PUBLIC_KEY', 
            'webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
        ];

        $missingKeys = [];
        foreach ($requiredKeys as $configKey => $envVar) {
            if (empty(config("services.stripe.{$configKey}"))) {
                $missingKeys[] = $envVar;
            }
        }

        if (!empty($missingKeys)) {
            throw new \RuntimeException('Missing Stripe configuration: ' . implode(', ', $missingKeys));
        }

        $this->info('✓ Stripe configuration is valid');
    }

    private function validateAllConfiguration(): void
    {
        $this->configurationValidator->validateApplicationConfiguration();
    }

    private function displayValidationSummary(): void
    {
        $summary = $this->configurationValidator->getValidationSummary();
        
        $this->newLine();
        $this->info('Configuration Summary:');
        
        $headers = ['Provider', 'Configured', 'Valid'];
        $rows = [];
        
        foreach ($summary as $provider => $status) {
            $rows[] = [
                ucfirst($provider),
                $status['configured'] ? '✓' : '✗',
                $status['valid'] ? '✓' : '✗',
            ];
        }
        
        $this->table($headers, $rows);
    }
}