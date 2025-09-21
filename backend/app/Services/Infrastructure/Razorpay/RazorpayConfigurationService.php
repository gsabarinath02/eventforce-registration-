<?php

namespace HiEvents\Services\Infrastructure\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayConfigurationException;
use Illuminate\Config\Repository;

class RazorpayConfigurationService
{
    public function __construct(
        private readonly Repository $config,
    ) {
    }

    /**
     * Validate that all required Razorpay configuration is present
     *
     * @throws RazorpayConfigurationException
     */
    public function validateConfiguration(): void
    {
        $requiredKeys = [
            'key_id' => 'RAZORPAY_KEY_ID',
            'key_secret' => 'RAZORPAY_KEY_SECRET',
            'webhook_secret' => 'RAZORPAY_WEBHOOK_SECRET',
        ];

        foreach ($requiredKeys as $configKey => $envVar) {
            $value = $this->config->get("services.razorpay.{$configKey}");
            
            if (empty($value)) {
                throw new RazorpayConfigurationException(
                    "Missing required Razorpay configuration: {$envVar}. Please set this environment variable."
                );
            }
        }
    }

    /**
     * Get Razorpay Key ID
     */
    public function getKeyId(): string
    {
        $keyId = $this->config->get('services.razorpay.key_id');
        
        if (empty($keyId)) {
            throw new RazorpayConfigurationException('RAZORPAY_KEY_ID is not configured');
        }
        
        return $keyId;
    }

    /**
     * Get Razorpay Key Secret
     */
    public function getKeySecret(): string
    {
        $keySecret = $this->config->get('services.razorpay.key_secret');
        
        if (empty($keySecret)) {
            throw new RazorpayConfigurationException('RAZORPAY_KEY_SECRET is not configured');
        }
        
        return $keySecret;
    }

    /**
     * Get Razorpay Webhook Secret
     */
    public function getWebhookSecret(): string
    {
        $webhookSecret = $this->config->get('services.razorpay.webhook_secret');
        
        if (empty($webhookSecret)) {
            throw new RazorpayConfigurationException('RAZORPAY_WEBHOOK_SECRET is not configured');
        }
        
        return $webhookSecret;
    }

    /**
     * Get Razorpay environment (test/live)
     */
    public function getEnvironment(): string
    {
        return $this->config->get('services.razorpay.environment', 'test');
    }

    /**
     * Check if Razorpay is in test mode
     */
    public function isTestMode(): bool
    {
        return $this->getEnvironment() === 'test';
    }

    /**
     * Get all Razorpay configuration as array (without secrets)
     */
    public function getConfigurationSummary(): array
    {
        return [
            'environment' => $this->getEnvironment(),
            'key_id_configured' => !empty($this->config->get('services.razorpay.key_id')),
            'key_secret_configured' => !empty($this->config->get('services.razorpay.key_secret')),
            'webhook_secret_configured' => !empty($this->config->get('services.razorpay.webhook_secret')),
        ];
    }
}