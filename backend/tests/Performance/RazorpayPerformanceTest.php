<?php

namespace Tests\Performance;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\RazorpayPaymentDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayOrderCreationService;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayPaymentVerificationService;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayRefundService;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayWebhookService;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\ValueObjects\MoneyValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RazorpayPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private RazorpayOrderCreationService $orderCreationService;
    private RazorpayPaymentVerificationService $verificationService;
    private RazorpayRefundService $refundService;
    private RazorpayWebhookService $webhookService;
    private EventRepositoryInterface $eventRepository;
    private OrderRepositoryInterface $orderRepository;
    private RazorpayPaymentRepositoryInterface $razorpayPaymentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderCreationService = $this->app->make(RazorpayOrderCreationService::class);
        $this->verificationService = $this->app->make(RazorpayPaymentVerificationService::class);
        $this->refundService = $this->app->make(RazorpayRefundService::class);
        $this->webhookService = $this->app->make(RazorpayWebhookService::class);
        $this->eventRepository = $this->app->make(EventRepositoryInterface::class);
        $this->orderRepository = $this->app->make(OrderRepositoryInterface::class);
        $this->razorpayPaymentRepository = $this->app->make(RazorpayPaymentRepositoryInterface::class);

        // Set up test environment variables
        config([
            'razorpay.key_id' => 'rzp_test_performance',
            'razorpay.key_secret' => 'test_secret_performance',
            'razorpay.webhook_secret' => 'test_webhook_secret_performance',
        ]);
    }

    /**
     * Test payment processing under load - simulates concurrent order creation
     */
    public function test_concurrent_order_creation_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $concurrentRequests = 50;
        $startTime = microtime(true);
        $queryCount = 0;

        // Enable query logging
        DB::enableQueryLog();

        $orders = [];
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $order = $this->createTestOrder($event);
            $orders[] = $order;
        }

        $processingStartTime = microtime(true);

        // Simulate concurrent Razorpay order creation
        foreach ($orders as $order) {
            try {
                $this->mockRazorpayClient();
                
                $result = $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(5000), // ₹50.00
                    currency: 'INR'
                );

                $this->assertNotNull($result);
            } catch (\Exception $e) {
                $this->fail("Order creation failed: " . $e->getMessage());
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $processingTime = $endTime - $processingStartTime;
        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        // Performance assertions
        $this->assertLessThan(10.0, $totalTime, "Total processing time should be under 10 seconds");
        $this->assertLessThan(5.0, $processingTime, "Order processing time should be under 5 seconds");
        $this->assertLessThan($concurrentRequests * 5, $queryCount, "Query count should be optimized");

        // Log performance metrics
        Log::info('Razorpay Performance Test - Concurrent Order Creation', [
            'concurrent_requests' => $concurrentRequests,
            'total_time' => $totalTime,
            'processing_time' => $processingTime,
            'query_count' => $queryCount,
            'avg_time_per_request' => $processingTime / $concurrentRequests,
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test database query optimization for payment lookups
     */
    public function test_database_query_optimization(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $batchSize = 100;

        // Create test data
        $orders = [];
        $payments = [];
        
        for ($i = 0; $i < $batchSize; $i++) {
            $order = $this->createTestOrder($event);
            $orders[] = $order;
            
            $payment = $this->createTestRazorpayPayment($order);
            $payments[] = $payment;
        }

        DB::enableQueryLog();
        $startTime = microtime(true);

        // Test bulk payment lookups
        $orderIds = collect($orders)->pluck('id')->toArray();
        $foundPayments = $this->razorpayPaymentRepository->findByOrderIds($orderIds);

        $endTime = microtime(true);
        $queries = DB::getQueryLog();
        
        // Performance assertions
        $this->assertLessThan(0.5, $endTime - $startTime, "Bulk lookup should be under 500ms");
        $this->assertLessThan(5, count($queries), "Should use minimal queries for bulk operations");
        $this->assertCount($batchSize, $foundPayments, "Should find all payments");

        // Test individual payment lookups with proper indexing
        DB::flushQueryLog();
        $startTime = microtime(true);

        foreach ($payments as $payment) {
            $found = $this->razorpayPaymentRepository->findByRazorpayOrderId($payment->getRazorpayOrderId());
            $this->assertNotNull($found);
        }

        $endTime = microtime(true);
        $queries = DB::getQueryLog();

        // Should be fast due to indexing
        $this->assertLessThan(1.0, $endTime - $startTime, "Individual lookups should be under 1 second");

        Log::info('Razorpay Performance Test - Database Query Optimization', [
            'batch_size' => $batchSize,
            'bulk_lookup_time' => $endTime - $startTime,
            'bulk_query_count' => count($queries),
            'individual_lookup_time' => $endTime - $startTime,
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test API call performance and rate limiting
     */
    public function test_api_call_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $apiCallCount = 20;
        $startTime = microtime(true);

        $this->mockRazorpayClient();

        // Test rapid API calls
        for ($i = 0; $i < $apiCallCount; $i++) {
            $order = $this->createTestOrder($event);
            
            try {
                $result = $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(1000),
                    currency: 'INR'
                );
                
                $this->assertNotNull($result);
                
                // Small delay to simulate real-world usage
                usleep(50000); // 50ms
                
            } catch (\Exception $e) {
                $this->fail("API call failed: " . $e->getMessage());
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTimePerCall = $totalTime / $apiCallCount;

        // Performance assertions
        $this->assertLessThan(0.5, $avgTimePerCall, "Average API call time should be under 500ms");
        $this->assertLessThan(15.0, $totalTime, "Total API processing time should be reasonable");

        Log::info('Razorpay Performance Test - API Call Performance', [
            'api_call_count' => $apiCallCount,
            'total_time' => $totalTime,
            'avg_time_per_call' => $avgTimePerCall,
        ]);
    }

    /**
     * Test webhook processing performance under load
     */
    public function test_webhook_processing_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $webhookCount = 100;
        $startTime = microtime(true);

        DB::enableQueryLog();

        // Create test orders and payments
        $testData = [];
        for ($i = 0; $i < $webhookCount; $i++) {
            $order = $this->createTestOrder($event);
            $payment = $this->createTestRazorpayPayment($order);
            $testData[] = ['order' => $order, 'payment' => $payment];
        }

        $processingStartTime = microtime(true);

        // Process webhooks
        foreach ($testData as $data) {
            $webhookPayload = json_encode([
                'event' => 'payment.captured',
                'payload' => [
                    'payment' => [
                        'entity' => [
                            'id' => $data['payment']->getRazorpayPaymentId(),
                            'order_id' => $data['payment']->getRazorpayOrderId(),
                            'status' => 'captured',
                            'amount' => 5000,
                        ]
                    ]
                ],
                'created_at' => time()
            ]);

            try {
                $this->mockRazorpayWebhookVerification();
                $this->webhookService->verifyAndParseWebhook($webhookPayload, 'test_signature');
            } catch (\Exception $e) {
                $this->fail("Webhook processing failed: " . $e->getMessage());
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $processingTime = $endTime - $processingStartTime;
        $queries = DB::getQueryLog();

        // Performance assertions
        $this->assertLessThan(5.0, $processingTime, "Webhook processing should be under 5 seconds");
        $this->assertLessThan($webhookCount * 3, count($queries), "Query count should be optimized");

        Log::info('Razorpay Performance Test - Webhook Processing Performance', [
            'webhook_count' => $webhookCount,
            'total_time' => $totalTime,
            'processing_time' => $processingTime,
            'query_count' => count($queries),
            'avg_time_per_webhook' => $processingTime / $webhookCount,
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test error handling and recovery performance
     */
    public function test_error_handling_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $errorScenarios = 50;
        $startTime = microtime(true);

        $errorCount = 0;
        $recoveryCount = 0;

        for ($i = 0; $i < $errorScenarios; $i++) {
            $order = $this->createTestOrder($event);

            try {
                // Simulate various error conditions
                if ($i % 3 === 0) {
                    // Simulate network timeout
                    $this->mockRazorpayClientWithError('network_timeout');
                } elseif ($i % 3 === 1) {
                    // Simulate invalid signature
                    $this->mockRazorpayClientWithError('invalid_signature');
                } else {
                    // Simulate API error
                    $this->mockRazorpayClientWithError('api_error');
                }

                $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(1000),
                    currency: 'INR'
                );

            } catch (\Exception $e) {
                $errorCount++;
                
                // Test recovery mechanism
                try {
                    $this->mockRazorpayClient(); // Reset to working state
                    $result = $this->orderCreationService->createOrder(
                        order: $order,
                        amount: new MoneyValue(1000),
                        currency: 'INR'
                    );
                    
                    if ($result) {
                        $recoveryCount++;
                    }
                } catch (\Exception $recoveryException) {
                    // Recovery failed
                }
            }
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Performance assertions for error handling
        $this->assertLessThan(10.0, $totalTime, "Error handling should not significantly impact performance");
        $this->assertGreaterThan($errorScenarios * 0.8, $errorCount, "Should properly detect errors");
        $this->assertGreaterThan($errorCount * 0.8, $recoveryCount, "Should have good recovery rate");

        Log::info('Razorpay Performance Test - Error Handling Performance', [
            'error_scenarios' => $errorScenarios,
            'total_time' => $totalTime,
            'error_count' => $errorCount,
            'recovery_count' => $recoveryCount,
            'recovery_rate' => $recoveryCount / max($errorCount, 1),
        ]);
    }

    /**
     * Test memory usage during bulk operations
     */
    public function test_memory_usage_optimization(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $bulkSize = 200;
        
        $initialMemory = memory_get_usage(true);
        $peakMemory = $initialMemory;

        // Create bulk test data
        for ($i = 0; $i < $bulkSize; $i++) {
            $order = $this->createTestOrder($event);
            $payment = $this->createTestRazorpayPayment($order);
            
            // Monitor memory usage
            $currentMemory = memory_get_usage(true);
            $peakMemory = max($peakMemory, $currentMemory);
            
            // Simulate processing
            $this->mockRazorpayClient();
            
            try {
                $this->verificationService->verifyPayment(
                    paymentId: $payment->getRazorpayPaymentId(),
                    orderId: $payment->getRazorpayOrderId(),
                    signature: 'test_signature_' . $i,
                    expectedAmount: new MoneyValue(1000)
                );
            } catch (\Exception $e) {
                // Expected for mock data
            }

            // Force garbage collection periodically
            if ($i % 50 === 0) {
                gc_collect_cycles();
            }
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        $peakIncrease = $peakMemory - $initialMemory;

        // Memory usage assertions (in MB)
        $memoryIncreaseMB = $memoryIncrease / 1024 / 1024;
        $peakIncreaseMB = $peakIncrease / 1024 / 1024;

        $this->assertLessThan(50, $memoryIncreaseMB, "Memory increase should be under 50MB");
        $this->assertLessThan(100, $peakIncreaseMB, "Peak memory increase should be under 100MB");

        Log::info('Razorpay Performance Test - Memory Usage Optimization', [
            'bulk_size' => $bulkSize,
            'initial_memory_mb' => $initialMemory / 1024 / 1024,
            'final_memory_mb' => $finalMemory / 1024 / 1024,
            'memory_increase_mb' => $memoryIncreaseMB,
            'peak_increase_mb' => $peakIncreaseMB,
        ]);
    }

    private function createTestEvent(): EventDomainObject
    {
        return $this->eventRepository->create([
            'title' => 'Performance Test Event',
            'description' => 'Test event for performance testing',
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
            'short_id' => 'PERF' . uniqid(),
            'email' => 'test@performance.com',
            'first_name' => 'Performance',
            'last_name' => 'Test',
            'total_gross' => 50.00,
            'total_tax' => 0.00,
            'total_fee' => 0.00,
            'currency' => 'INR',
            'status' => 'awaiting_payment',
            'payment_provider' => 'RAZORPAY',
        ]);
    }

    private function createTestRazorpayPayment(OrderDomainObject $order): RazorpayPaymentDomainObject
    {
        return $this->razorpayPaymentRepository->create([
            'order_id' => $order->getId(),
            'razorpay_order_id' => 'order_perf_' . uniqid(),
            'razorpay_payment_id' => 'pay_perf_' . uniqid(),
            'razorpay_signature' => 'sig_perf_' . uniqid(),
            'amount_received' => 5000,
        ]);
    }

    private function mockRazorpayClient(): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('createOrder')
            ->andReturn([
                'id' => 'order_mock_' . uniqid(),
                'amount' => 5000,
                'currency' => 'INR',
                'status' => 'created',
            ]);

        $mockClient->shouldReceive('verifyPaymentSignature')
            ->andReturn(true);

        $mockClient->shouldReceive('verifyWebhookSignature')
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);
    }

    private function mockRazorpayClientWithError(string $errorType): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        switch ($errorType) {
            case 'network_timeout':
                $mockClient->shouldReceive('createOrder')
                    ->andThrow(new \Exception('Network timeout'));
                break;
            case 'invalid_signature':
                $mockClient->shouldReceive('verifyPaymentSignature')
                    ->andReturn(false);
                break;
            case 'api_error':
                $mockClient->shouldReceive('createOrder')
                    ->andThrow(new \Exception('API Error: Invalid request'));
                break;
        }

        $this->app->instance(RazorpayClient::class, $mockClient);
    }

    private function mockRazorpayWebhookVerification(): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('verifyWebhookSignature')
            ->andReturn(true);

        $this->app->instance(RazorpayClient::class, $mockClient);
    }
}