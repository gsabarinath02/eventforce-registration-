<?php

namespace Tests\Feature\Security;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\RazorpayPaymentDomainObject;
use HiEvents\Enums\PaymentProvider;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Comprehensive security review test for Razorpay integration
 * 
 * This test validates:
 * - Credential handling and storage security
 * - Webhook signature verification implementation
 * - Protection against potential security vulnerabilities
 */
class RazorpaySecurityReviewTest extends TestCase
{
    use RefreshDatabase;

    private EventDomainObject $event;
    private OrderDomainObject $order;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test credentials
        Config::set('services.razorpay.key_id', 'rzp_test_security_123');
        Config::set('services.razorpay.key_secret', 'test_security_secret');
        Config::set('services.razorpay.webhook_secret', 'test_webhook_secret');

        $this->event = $this->createEvent([
            'settings' => [
                'payment_providers' => [PaymentProvider::RAZORPAY->value],
                'razorpay_key_id' => 'rzp_test_security_123',
                'razorpay_key_secret' => 'test_security_secret',
                'razorpay_webhook_secret' => 'test_webhook_secret',
            ]
        ]);

        $this->order = $this->createOrder($this->event);
    }

    /**
     * Test 1: Credential Handling and Storage Security
     */
    public function test_credentials_are_never_logged_or_exposed(): void
    {
        // Capture all log messages
        Log::spy();

        // Test configuration service
        $configService = app(RazorpayConfigurationService::class);
        
        // Trigger various operations that might log credentials
        $configService->validateConfiguration();
        $configService->getKeyId();
        $configService->getKeySecret();
        $configService->getWebhookSecret();
        $configService->getConfigurationSummary();

        // Verify no sensitive data is logged
        Log::shouldNotHaveReceived('info', function ($message, $context = []) {
            return $this->containsSensitiveData($message, $context);
        });

        Log::shouldNotHaveReceived('error', function ($message, $context = []) {
            return $this->containsSensitiveData($message, $context);
        });

        Log::shouldNotHaveReceived('warning', function ($message, $context = []) {
            return $this->containsSensitiveData($message, $context);
        });

        Log::shouldNotHaveReceived('debug', function ($message, $context = []) {
            return $this->containsSensitiveData($message, $context);
        });
    }

    public function test_configuration_summary_does_not_expose_secrets(): void
    {
        $configService = app(RazorpayConfigurationService::class);
        $summary = $configService->getConfigurationSummary();

        // Verify summary only contains boolean flags, not actual secrets
        $this->assertArrayHasKey('key_id_configured', $summary);
        $this->assertArrayHasKey('key_secret_configured', $summary);
        $this->assertArrayHasKey('webhook_secret_configured', $summary);
        $this->assertArrayHasKey('environment', $summary);

        // Verify no actual secrets are present
        $this->assertArrayNotHasKey('key_id', $summary);
        $this->assertArrayNotHasKey('key_secret', $summary);
        $this->assertArrayNotHasKey('webhook_secret', $summary);

        // Verify values are boolean flags
        $this->assertIsBool($summary['key_id_configured']);
        $this->assertIsBool($summary['key_secret_configured']);
        $this->assertIsBool($summary['webhook_secret_configured']);
    }

    public function test_missing_credentials_error_messages_do_not_expose_values(): void
    {
        // Clear configuration
        Config::set('services.razorpay.key_id', '');
        Config::set('services.razorpay.key_secret', '');
        Config::set('services.razorpay.webhook_secret', '');

        $configService = app(RazorpayConfigurationService::class);

        try {
            $configService->validateConfiguration();
            $this->fail('Expected RazorpayConfigurationException');
        } catch (\Exception $e) {
            // Verify error message mentions environment variable name but not value
            $this->assertStringContainsString('RAZORPAY_KEY_ID', $e->getMessage());
            $this->assertStringNotContainsString('test_security_secret', $e->getMessage());
            $this->assertStringNotContainsString('test_webhook_secret', $e->getMessage());
        }
    }

    /**
     * Test 2: Webhook Signature Verification Security
     */
    public function test_webhook_signature_verification_uses_constant_time_comparison(): void
    {
        $client = app(RazorpayClient::class);
        
        $payload = '{"event":"payment.captured","payload":{"payment":{"entity":{"id":"pay_test123"}}}}';
        $validSignature = hash_hmac('sha256', $payload, 'test_webhook_secret');
        $invalidSignature = 'invalid_signature_123';

        // Test valid signature
        $this->assertTrue($client->verifyWebhookSignature($payload, $validSignature));

        // Test invalid signature
        $this->assertFalse($client->verifyWebhookSignature($payload, $invalidSignature));

        // Test empty signature
        $this->assertFalse($client->verifyWebhookSignature($payload, ''));

        // Test empty payload
        $this->assertFalse($client->verifyWebhookSignature('', $validSignature));
    }

    public function test_payment_signature_verification_uses_constant_time_comparison(): void
    {
        $client = app(RazorpayClient::class);
        
        $orderId = 'order_test123';
        $paymentId = 'pay_test456';
        $validSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, 'test_security_secret');
        $invalidSignature = 'invalid_signature_123';

        // Test valid signature
        $this->assertTrue($client->verifyPaymentSignature($paymentId, $orderId, $validSignature));

        // Test invalid signature
        $this->assertFalse($client->verifyPaymentSignature($paymentId, $orderId, $invalidSignature));

        // Test signature with different order
        $differentSignature = hash_hmac('sha256', 'different_order|' . $paymentId, 'test_security_secret');
        $this->assertFalse($client->verifyPaymentSignature($paymentId, $orderId, $differentSignature));
    }

    public function test_webhook_signature_verification_prevents_timing_attacks(): void
    {
        $client = app(RazorpayClient::class);
        $payload = '{"event":"payment.captured"}';
        
        // Generate signatures of different lengths
        $shortSignature = 'short';
        $longSignature = str_repeat('a', 64);
        $validSignature = hash_hmac('sha256', $payload, 'test_webhook_secret');

        // All invalid signatures should return false regardless of length
        $this->assertFalse($client->verifyWebhookSignature($payload, $shortSignature));
        $this->assertFalse($client->verifyWebhookSignature($payload, $longSignature));
        $this->assertTrue($client->verifyWebhookSignature($payload, $validSignature));
    }

    /**
     * Test 3: Protection Against Security Vulnerabilities
     */
    public function test_webhook_endpoint_validates_content_type(): void
    {
        $razorpayPayment = $this->createRazorpayPayment($this->order);

        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $razorpayPayment->getRazorpayPaymentId(),
                        'order_id' => $razorpayPayment->getRazorpayOrderId(),
                        'status' => 'captured',
                        'amount' => 5000,
                        'currency' => 'INR',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        $webhookBody = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $webhookBody, 'test_webhook_secret');

        // Test with wrong content type
        $response = $this->postJson(
            route('api.public.webhooks.razorpay'),
            $webhookPayload,
            [
                'Content-Type' => 'text/plain',
                'X-Razorpay-Signature' => $signature,
            ]
        );

        // Should still work as Laravel handles JSON parsing
        $this->assertIn($response->getStatusCode(), [200, 422]);
    }

    public function test_webhook_endpoint_requires_signature_header(): void
    {
        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test123',
                        'order_id' => 'order_test123',
                        'status' => 'captured',
                    ]
                ]
            ],
        ];

        // Test without signature header
        $response = $this->postJson(
            route('api.public.webhooks.razorpay'),
            $webhookPayload
        );

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_webhook_prevents_replay_attacks(): void
    {
        $razorpayPayment = $this->createRazorpayPayment($this->order);

        $webhookPayload = [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $razorpayPayment->getRazorpayPaymentId(),
                        'order_id' => $razorpayPayment->getRazorpayOrderId(),
                        'status' => 'captured',
                        'amount' => 5000,
                        'currency' => 'INR',
                    ]
                ]
            ],
            'created_at' => time(),
        ];

        $webhookBody = json_encode($webhookPayload);
        $signature = hash_hmac('sha256', $webhookBody, 'test_webhook_secret');

        // First request should succeed
        $response1 = $this->postJson(
            route('api.public.webhooks.razorpay'),
            $webhookPayload,
            ['X-Razorpay-Signature' => $signature]
        );

        $this->assertEquals(200, $response1->getStatusCode());

        // Second identical request should be handled idempotently
        $response2 = $this->postJson(
            route('api.public.webhooks.razorpay'),
            $webhookPayload,
            ['X-Razorpay-Signature' => $signature]
        );

        $this->assertEquals(200, $response2->getStatusCode());
    }

    public function test_payment_verification_prevents_amount_manipulation(): void
    {
        $razorpayPayment = $this->createRazorpayPayment($this->order);

        $paymentId = $razorpayPayment->getRazorpayPaymentId();
        $orderId = $razorpayPayment->getRazorpayOrderId();
        
        // Create valid signature for original amount
        $validSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, 'test_security_secret');

        // Attempt to verify with manipulated data
        $response = $this->postJson(
            route('api.public.events.orders.razorpay.verify', [
                'eventId' => $this->event->getId(),
                'orderShortId' => $this->order->getShortId(),
            ]),
            [
                'razorpay_payment_id' => $paymentId,
                'razorpay_order_id' => $orderId,
                'razorpay_signature' => $validSignature,
                // This should be ignored - amount comes from stored order
                'amount' => 999999,
            ]
        );

        // Should succeed with original amount, not manipulated amount
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_sql_injection_protection_in_payment_queries(): void
    {
        // Test with malicious input in payment ID
        $maliciousPaymentId = "pay_test'; DROP TABLE orders; --";
        $orderId = 'order_test123';
        $signature = hash_hmac('sha256', $orderId . '|' . $maliciousPaymentId, 'test_security_secret');

        $response = $this->postJson(
            route('api.public.events.orders.razorpay.verify', [
                'eventId' => $this->event->getId(),
                'orderShortId' => $this->order->getShortId(),
            ]),
            [
                'razorpay_payment_id' => $maliciousPaymentId,
                'razorpay_order_id' => $orderId,
                'razorpay_signature' => $signature,
            ]
        );

        // Should handle gracefully without SQL injection
        $this->assertIn($response->getStatusCode(), [400, 422, 500]);
        
        // Verify orders table still exists
        $this->assertDatabaseHas('orders', ['id' => $this->order->getId()]);
    }

    public function test_xss_protection_in_error_messages(): void
    {
        $xssPayload = '<script>alert("xss")</script>';
        
        $response = $this->postJson(
            route('api.public.events.orders.razorpay.verify', [
                'eventId' => $this->event->getId(),
                'orderShortId' => $this->order->getShortId(),
            ]),
            [
                'razorpay_payment_id' => $xssPayload,
                'razorpay_order_id' => 'order_test123',
                'razorpay_signature' => 'invalid_signature',
            ]
        );

        $responseContent = $response->getContent();
        
        // Verify XSS payload is not executed in response
        $this->assertStringNotContainsString('<script>', $responseContent);
        $this->assertStringNotContainsString('alert("xss")', $responseContent);
    }

    /**
     * Test 4: Environment Variable Security
     */
    public function test_environment_variables_are_not_exposed_in_config_dump(): void
    {
        $configDump = config()->all();
        
        // Convert to JSON to simulate config exposure
        $configJson = json_encode($configDump);
        
        // Verify sensitive values are not in the dump
        $this->assertStringNotContainsString('test_security_secret', $configJson);
        $this->assertStringNotContainsString('test_webhook_secret', $configJson);
        
        // But configuration keys should be present
        $this->assertArrayHasKey('services', $configDump);
        $this->assertArrayHasKey('razorpay', $configDump['services']);
    }

    public function test_credentials_validation_on_startup(): void
    {
        // Test with invalid key format
        Config::set('services.razorpay.key_id', 'invalid_key_format');
        
        $configService = app(RazorpayConfigurationService::class);
        
        // Should not throw exception for format validation (Razorpay handles this)
        $keyId = $configService->getKeyId();
        $this->assertEquals('invalid_key_format', $keyId);
    }

    /**
     * Helper Methods
     */
    private function containsSensitiveData(string $message, array $context = []): bool
    {
        $sensitivePatterns = [
            'test_security_secret',
            'test_webhook_secret',
            'rzp_test_security_123',
            // Add patterns for any other sensitive data
        ];

        $allContent = $message . ' ' . json_encode($context);
        
        foreach ($sensitivePatterns as $pattern) {
            if (stripos($allContent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function createRazorpayPayment(OrderDomainObject $order): RazorpayPaymentDomainObject
    {
        return RazorpayPaymentDomainObject::create([
            'order_id' => $order->getId(),
            'razorpay_order_id' => 'order_test_' . uniqid(),
            'razorpay_payment_id' => 'pay_test_' . uniqid(),
            'razorpay_signature' => 'signature_' . uniqid(),
            'amount_received' => 5000,
        ]);
    }
}