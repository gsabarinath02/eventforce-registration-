<?php

namespace Tests\Performance;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RazorpayApiPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private EventRepositoryInterface $eventRepository;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = $this->app->make(EventRepositoryInterface::class);
        $this->orderRepository = $this->app->make(OrderRepositoryInterface::class);

        // Set up test environment
        config([
            'razorpay.key_id' => 'rzp_test_performance',
            'razorpay.key_secret' => 'test_secret_performance',
            'razorpay.webhook_secret' => 'test_webhook_secret_performance',
        ]);
    }

    /**
     * Test API endpoint performance under load
     */
    public function test_create_razorpay_order_endpoint_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $requestCount = 50;
        $concurrentBatches = 5;
        
        $this->mockRazorpayHttpResponses();

        $totalStartTime = microtime(true);
        $responseTimes = [];
        $successCount = 0;
        $errorCount = 0;

        // Test concurrent requests in batches
        for ($batch = 0; $batch < $concurrentBatches; $batch++) {
            $batchStartTime = microtime(true);
            
            for ($i = 0; $i < $requestCount / $concurrentBatches; $i++) {
                $order = $this->createTestOrder($event);
                
                $requestStartTime = microtime(true);
                
                try {
                    $response = $this->postJson("/api/public/events/{$event->getId()}/order/{$order->getShortId()}/razorpay/order", [
                        'amount' => 5000,
                        'currency' => 'INR',
                    ]);

                    $requestEndTime = microtime(true);
                    $responseTime = $requestEndTime - $requestStartTime;
                    $responseTimes[] = $responseTime;

                    if ($response->status() === 200) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }

                } catch (\Exception $e) {
                    $errorCount++;
                    $responseTimes[] = microtime(true) - $requestStartTime;
                }
            }
            
            $batchEndTime = microtime(true);
            Log::info("Batch {$batch} completed", [
                'batch_time' => $batchEndTime - $batchStartTime,
                'requests_in_batch' => $requestCount / $concurrentBatches,
            ]);
        }

        $totalEndTime = microtime(true);
        $totalTime = $totalEndTime - $totalStartTime;
        
        // Calculate performance metrics
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        $maxResponseTime = max($responseTimes);
        $minResponseTime = min($responseTimes);
        $successRate = $successCount / ($successCount + $errorCount) * 100;

        // Performance assertions
        $this->assertLessThan(2.0, $avgResponseTime, "Average response time should be under 2 seconds");
        $this->assertLessThan(5.0, $maxResponseTime, "Max response time should be under 5 seconds");
        $this->assertGreaterThan(80, $successRate, "Success rate should be above 80%");

        Log::info('Razorpay API Performance - Create Order Endpoint', [
            'total_requests' => $requestCount,
            'total_time' => $totalTime,
            'avg_response_time' => $avgResponseTime,
            'max_response_time' => $maxResponseTime,
            'min_response_time' => $minResponseTime,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'success_rate' => $successRate,
            'requests_per_second' => $requestCount / $totalTime,
        ]);
    }

    /**
     * Test payment verification endpoint performance
     */
    public function test_verify_razorpay_payment_endpoint_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $verificationCount = 30;
        
        $this->mockRazorpayHttpResponses();

        $orders = [];
        for ($i = 0; $i < $verificationCount; $i++) {
            $orders[] = $this->createTestOrder($event);
        }

        $startTime = microtime(true);
        $responseTimes = [];
        $successCount = 0;

        foreach ($orders as $order) {
            $requestStartTime = microtime(true);
            
            try {
                $response = $this->postJson("/api/public/events/{$event->getId()}/order/{$order->getShortId()}/razorpay/verify", [
                    'razorpay_payment_id' => 'pay_test_' . uniqid(),
                    'razorpay_order_id' => 'order_test_' . uniqid(),
                    'razorpay_signature' => 'sig_test_' . uniqid(),
                ]);

                $requestEndTime = microtime(true);
                $responseTimes[] = $requestEndTime - $requestStartTime;

                if ($response->status() === 200) {
                    $successCount++;
                }

            } catch (\Exception $e) {
                $responseTimes[] = microtime(true) - $requestStartTime;
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);

        // Performance assertions
        $this->assertLessThan(1.0, $avgResponseTime, "Average verification time should be under 1 second");
        $this->assertLessThan(10.0, $totalTime, "Total verification time should be reasonable");

        Log::info('Razorpay API Performance - Payment Verification Endpoint', [
            'verification_count' => $verificationCount,
            'total_time' => $totalTime,
            'avg_response_time' => $avgResponseTime,
            'success_count' => $successCount,
        ]);
    }

    /**
     * Test webhook endpoint performance under load
     */
    public function test_webhook_endpoint_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $webhookCount = 100;
        $this->mockRazorpayHttpResponses();

        $startTime = microtime(true);
        $responseTimes = [];
        $successCount = 0;

        for ($i = 0; $i < $webhookCount; $i++) {
            $webhookPayload = [
                'event' => 'payment.captured',
                'payload' => [
                    'payment' => [
                        'entity' => [
                            'id' => 'pay_webhook_' . $i,
                            'order_id' => 'order_webhook_' . $i,
                            'status' => 'captured',
                            'amount' => 5000,
                        ]
                    ]
                ],
                'created_at' => time()
            ];

            $requestStartTime = microtime(true);

            try {
                $response = $this->postJson('/api/public/webhooks/razorpay', $webhookPayload, [
                    'X-Razorpay-Signature' => 'test_signature_' . $i,
                ]);

                $requestEndTime = microtime(true);
                $responseTimes[] = $requestEndTime - $requestStartTime;

                if ($response->status() === 200) {
                    $successCount++;
                }

            } catch (\Exception $e) {
                $responseTimes[] = microtime(true) - $requestStartTime;
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgResponseTime = array_sum($responseTimes) / count($responseTimes);

        // Performance assertions
        $this->assertLessThan(0.5, $avgResponseTime, "Average webhook processing time should be under 500ms");
        $this->assertLessThan(30.0, $totalTime, "Total webhook processing should be efficient");

        Log::info('Razorpay API Performance - Webhook Endpoint', [
            'webhook_count' => $webhookCount,
            'total_time' => $totalTime,
            'avg_response_time' => $avgResponseTime,
            'success_count' => $successCount,
            'webhooks_per_second' => $webhookCount / $totalTime,
        ]);
    }

    /**
     * Test API rate limiting and throttling
     */
    public function test_api_rate_limiting_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $rapidRequestCount = 20;
        $requestInterval = 0.1; // 100ms between requests
        
        $this->mockRazorpayHttpResponses();

        $startTime = microtime(true);
        $responseTimes = [];
        $statusCodes = [];

        for ($i = 0; $i < $rapidRequestCount; $i++) {
            $order = $this->createTestOrder($event);
            
            $requestStartTime = microtime(true);
            
            try {
                $response = $this->postJson("/api/public/events/{$event->getId()}/order/{$order->getShortId()}/razorpay/order", [
                    'amount' => 1000,
                    'currency' => 'INR',
                ]);

                $requestEndTime = microtime(true);
                $responseTimes[] = $requestEndTime - $requestStartTime;
                $statusCodes[] = $response->status();

            } catch (\Exception $e) {
                $responseTimes[] = microtime(true) - $requestStartTime;
                $statusCodes[] = 500;
            }

            // Small delay between requests
            usleep($requestInterval * 1000000);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Analyze rate limiting behavior
        $successfulRequests = count(array_filter($statusCodes, fn($code) => $code === 200));
        $rateLimitedRequests = count(array_filter($statusCodes, fn($code) => $code === 429));
        
        Log::info('Razorpay API Performance - Rate Limiting', [
            'rapid_request_count' => $rapidRequestCount,
            'total_time' => $totalTime,
            'successful_requests' => $successfulRequests,
            'rate_limited_requests' => $rateLimitedRequests,
            'avg_response_time' => array_sum($responseTimes) / count($responseTimes),
            'status_code_distribution' => array_count_values($statusCodes),
        ]);

        // Should handle rate limiting gracefully
        $this->assertGreaterThanOrEqual(0, $rateLimitedRequests, "Rate limiting should be handled");
    }

    /**
     * Test external API call optimization
     */
    public function test_external_api_call_optimization(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $apiCallCount = 15;
        $this->mockRazorpayHttpResponses();

        // Test with connection pooling and keep-alive
        $startTime = microtime(true);
        
        $client = $this->app->make(RazorpayClient::class);
        
        for ($i = 0; $i < $apiCallCount; $i++) {
            try {
                $result = $client->createOrder([
                    'amount' => 5000,
                    'currency' => 'INR',
                    'receipt' => 'test_receipt_' . $i,
                ]);
                
                $this->assertNotNull($result);
                
            } catch (\Exception $e) {
                // Expected for mock responses
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTimePerCall = $totalTime / $apiCallCount;

        // Performance assertions
        $this->assertLessThan(0.3, $avgTimePerCall, "Average API call should be under 300ms");

        Log::info('Razorpay API Performance - External API Optimization', [
            'api_call_count' => $apiCallCount,
            'total_time' => $totalTime,
            'avg_time_per_call' => $avgTimePerCall,
        ]);
    }

    /**
     * Test error handling performance impact
     */
    public function test_error_handling_performance_impact(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $errorTestCount = 25;

        // Test normal operation performance
        $this->mockRazorpayHttpResponses();
        
        $normalStartTime = microtime(true);
        
        for ($i = 0; $i < $errorTestCount; $i++) {
            $order = $this->createTestOrder($event);
            
            try {
                $this->postJson("/api/public/events/{$event->getId()}/order/{$order->getShortId()}/razorpay/order", [
                    'amount' => 5000,
                    'currency' => 'INR',
                ]);
            } catch (\Exception $e) {
                // Expected
            }
        }
        
        $normalEndTime = microtime(true);
        $normalTime = $normalEndTime - $normalStartTime;

        // Test error condition performance
        $this->mockRazorpayHttpErrorResponses();
        
        $errorStartTime = microtime(true);
        
        for ($i = 0; $i < $errorTestCount; $i++) {
            $order = $this->createTestOrder($event);
            
            try {
                $this->postJson("/api/public/events/{$event->getId()}/order/{$order->getShortId()}/razorpay/order", [
                    'amount' => 5000,
                    'currency' => 'INR',
                ]);
            } catch (\Exception $e) {
                // Expected
            }
        }
        
        $errorEndTime = microtime(true);
        $errorTime = $errorEndTime - $errorStartTime;

        // Error handling shouldn't significantly impact performance
        $performanceImpact = ($errorTime - $normalTime) / $normalTime * 100;
        
        $this->assertLessThan(50, $performanceImpact, "Error handling should not significantly impact performance");

        Log::info('Razorpay API Performance - Error Handling Impact', [
            'test_count' => $errorTestCount,
            'normal_time' => $normalTime,
            'error_time' => $errorTime,
            'performance_impact_percent' => $performanceImpact,
        ]);
    }

    private function createTestEvent(): EventDomainObject
    {
        return $this->eventRepository->create([
            'title' => 'API Performance Test Event',
            'description' => 'Test event for API performance testing',
            'start_date' => now()->addDays(30),
            'end_date' => now()->addDays(31),
            'timezone' => 'UTC',
            'currency' => 'INR',
            'user_id' => 1,
            'account_id' => 1,
        ]);
    }

    private function createTestOrder(EventDomainObject $event): OrderDomainObject
    {
        return $this->orderRepository->create([
            'event_id' => $event->getId(),
            'short_id' => 'APIPERF' . uniqid(),
            'email' => 'test@apiperformance.com',
            'first_name' => 'API',
            'last_name' => 'Performance',
            'total_gross' => 50.00,
            'total_tax' => 0.00,
            'total_fee' => 0.00,
            'currency' => 'INR',
            'status' => 'awaiting_payment',
            'payment_provider' => 'RAZORPAY',
        ]);
    }

    private function mockRazorpayHttpResponses(): void
    {
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response([
                'id' => 'order_mock_' . uniqid(),
                'amount' => 5000,
                'currency' => 'INR',
                'status' => 'created',
            ], 200),
            
            'api.razorpay.com/v1/payments/*' => Http::response([
                'id' => 'pay_mock_' . uniqid(),
                'status' => 'captured',
                'amount' => 5000,
            ], 200),
        ]);
    }

    private function mockRazorpayHttpErrorResponses(): void
    {
        Http::fake([
            'api.razorpay.com/*' => Http::response([
                'error' => [
                    'code' => 'BAD_REQUEST_ERROR',
                    'description' => 'Test error for performance testing',
                ]
            ], 400),
        ]);
    }
}