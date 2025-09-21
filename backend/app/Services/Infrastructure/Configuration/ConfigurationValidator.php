<?php

namespace HiEvents\Services\Infrastructure\Configuration;

use HiEvents\Exceptions\Razorpay\RazorpayConfigurationException;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayConfigurationService;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

class ConfigurationValidator
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate all application configuration on startup
     */
    public function validateApplicationConfiguration(): void
    {
        $this->validateRazorpayConfiguration();
        $this->validateStripeConfiguration();
    }

    /**
     * Validate Razorpay configuration if enabled
     */
    private function validateRazorpayConfiguration(): void
    {
        // Skip validation if Razorpay is not configured at all
        if (!$this->isRazorpayConfigured()) {
            $this->logger->debug('Razorpay is not configured, skipping validation');
            return;
        }

        try {
            $razorpayConfig = new RazorpayConfigurationService($this->config);
            $razorpayConfig->validateConfiguration();
            
            $this->logger->info('Razorpay configuration validation passed', 
                $razorpayConfig->getConfigurationSummary()
            );
        } catch (RazorpayConfigurationException $exception) {
            $this->logger->error('Razorpay configuration validation failed', [
                'error' => $exception->getMessage(),
            ]);
            
            // In production, we might want to throw the exception to prevent startup
            // For now, we'll just log the error to allow the application to start
            if ($this->config->get('app.env') === 'production') {
                throw $exception;
            }
        }
    }

    /**
     * Validate Stripe configuration if enabled
     */
    private function validateStripeConfiguration(): void
    {
        // Skip validation if Stripe is not configured at all
        if (!$this->isStripeConfigured()) {
            $this->logger->debug('Stripe is not configured, skipping validation');
            return;
        }

        $requiredKeys = [
            'secret_key' => 'STRIPE_SECRET_KEY',
            'public_key' => 'STRIPE_PUBLIC_KEY',
            'webhook_secret' => 'STRIPE_WEBHOOK_SECRET',
        ];

        $missingKeys = [];
        foreach ($requiredKeys as $configKey => $envVar) {
            $value = $this->config->get("services.stripe.{$configKey}");
            if (empty($value)) {
                $missingKeys[] = $envVar;
            }
        }

        if (!empty($missingKeys)) {
            $message = 'Missing required Stripe configuration: ' . implode(', ', $missingKeys);
            $this->logger->error('Stripe configuration validation failed', [
                'missing_keys' => $missingKeys,
            ]);
            
            // In production, we might want to be more strict
            if ($this->config->get('app.env') === 'production') {
                throw new \RuntimeException($message);
            }
        } else {
            $this->logger->info('Stripe configuration validation passed');
        }
    }

    /**
     * Check if Razorpay is configured (at least key_id is present)
     */
    private function isRazorpayConfigured(): bool
    {
        return !empty($this->config->get('services.razorpay.key_id'));
    }

    /**
     * Check if Stripe is configured (at least secret_key is present)
     */
    private function isStripeConfigured(): bool
    {
        return !empty($this->config->get('services.stripe.secret_key'));
    }

    /**
     * Get configuration validation summary
     */
    public function getValidationSummary(): array
    {
        return [
            'razorpay' => [
                'configured' => $this->isRazorpayConfigured(),
                'valid' => $this->isRazorpayConfigurationValid(),
            ],
            'stripe' => [
                'configured' => $this->isStripeConfigured(),
                'valid' => $this->isStripeConfigurationValid(),
            ],
        ];
    }

    /**
     * Check if Razorpay configuration is valid
     */
    private function isRazorpayConfigurationValid(): bool
    {
        if (!$this->isRazorpayConfigured()) {
            return false;
        }

        try {
            $razorpayConfig = new RazorpayConfigurationService($this->config);
            $razorpayConfig->validateConfiguration();
            return true;
        } catch (RazorpayConfigurationException) {
            return false;
        }
    }

    /**
     * Check if Stripe configuration is valid
     */
    private function isStripeConfigurationValid(): bool
    {
        if (!$this->isStripeConfigured()) {
            return false;
        }

        $requiredKeys = ['secret_key', 'public_key', 'webhook_secret'];
        foreach ($requiredKeys as $key) {
            if (empty($this->config->get("services.stripe.{$key}"))) {
                return false;
            }
        }

        return true;
    }
}