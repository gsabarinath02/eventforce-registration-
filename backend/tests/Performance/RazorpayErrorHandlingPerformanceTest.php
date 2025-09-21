<?php

namespace Tests\Performance;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayOrderCreationService;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayPaymentVerificationService;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayRefundService;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayWebhookService;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\ValueObjects\MoneyValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RazorpayErrorHandlingPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private RazorpayOrderCreationService $orderCreationService;
    private RazorpayPaymentVerificationService $verificationService;
    private RazorpayRefundService $refundService;
    private RazorpayWebhookService $webhookService;
    private EventRepositoryInterface $eventRepository;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderCreationService = $this->app->make(RazorpayOrderCreationService::class);
        $this->verificationService = $this->app->make(RazorpayPaymentVerificationService::class);
        $this->refundService = $this->app->make(RazorpayRefundService::class);
        $this->webhookService = $this->app->make(RazorpayWebhookService::class);
        $this->eventRepository = $this->app->make(EventRepositoryInterface::class);
        $this->orderRepository = $this->app->make(OrderRepositoryInterface::class);

        config([
            'razorpay.key_id' => 'rzp_test_error_performance',
            'razorpay.key_secret' => 'test_secret_error_performance',
            'razorpay.webhook_secret' => 'test_webhook_secret_error_performance',
        ]);
    }

    /**
     * Test error recovery performance under various failure scenarios
     */
    public function test_error_recovery_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $errorScenarios = 30;
        $recoveryAttempts = [];
        $totalRecoveryTime = 0;

        for ($i = 0; $i < $errorScenarios; $i++) {
            $order = $this->createTestOrder($event);
            
            // Simulate different error types
            $errorType = ['network_timeout', 'api_error', 'invalid_signature'][$i % 3];
            
            $startTime = microtime(true);
            
            try {
                // First attempt (should fail)
                $this->mockRazorpayClientWithError($errorType);
                $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(5000),
                    currency: 'INR'
                );
                
            } catch (\Exception $e) {
                // Recovery attempt
                $recoveryStartTime = microtime(true);
                
                try {
                    $this->mockRazorpayClient(); // Reset to working state
                    $result = $this->orderCreationService->createOrder(
                        order: $order,
                        amount: new MoneyValue(5000),
                        currency: 'INR'
                    );
                    
                    $recoveryEndTime = microtime(true);
                    $recoveryTime = $recoveryEndTime - $recoveryStartTime;
                    $recoveryAttempts[] = $recoveryTime;
                    $totalRecoveryTime += $recoveryTime;
                    
                } catch (\Exception $recoveryException) {
                    $recoveryAttempts[] = null; // Failed recovery
                }
            }
        }

        $successfulRecoveries = count(array_filter($recoveryAttempts));
        $avgRecoveryTime = $totalRecoveryTime / max($successfulRecoveries, 1);
        $recoveryRate = $successfulRecoveries / $errorScenarios * 100;

        // Performance assertions
        $this->assertLessThan(2.0, $avgRecoveryTime, "Average recovery time should be under 2 seconds");
        $this->assertGreaterThan(80, $recoveryRate, "Recovery rate should be above 80%");

        Log::info('Razorpay Error Handling Performance - Recovery Performance', [
            'error_scenarios' => $errorScenarios,
            'successful_recoveries' => $successfulRecoveries,
            'recovery_rate' => $recoveryRate,
            'avg_recovery_time' => $avgRecoveryTime,
            'total_recovery_time' => $totalRecoveryTime,
        ]);
    }

    /**
     * Test circuit breaker performance under sustained errors
     */
    public function test_circuit_breaker_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $sustainedErrors = 20;
        $errorTimes = [];
        $circuitBreakerTriggered = false;

        // Simulate sustained API errors
        $this->mockRazorpayClientWithSustainedErrors();

        for ($i = 0; $i < $sustainedErrors; $i++) {
            $order = $this->createTestOrder($event);
            
            $startTime = microtime(true);
            
            try {
                $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(1000),
                    currency: 'INR'
                );
                
            } catch (\Exception $e) {
                $endTime = microtime(true);
                $errorTime = $endTime - $startTime;
                $errorTimes[] = $errorTime;
                
                // Check if circuit breaker pattern is implemented
                if ($errorTime < 0.1 && $i > 5) { // Fast failure after multiple errors
                    $circuitBreakerTriggered = true;
                }
            }
        }

        $avgErrorTime = array_sum($errorTimes) / count($errorTimes);
        $laterErrorTimes = array_slice($errorTimes, -5); // Last 5 errors
        $avgLaterErrorTime = array_sum($laterErrorTimes) / count($laterErrorTimes);

        // Circuit breaker should make later errors fail faster
        if ($circuitBreakerTriggered) {
            $this->assertLessThan($avgErrorTime, $avgLaterErrorTime, "Circuit breaker should make errors fail faster");
        }

        Log::info('Razorpay Error Handling Performance - Circuit Breaker', [
            'sustained_errors' => $sustainedErrors,
            'avg_error_time' => $avgErrorTime,
            'avg_later_error_time' => $avgLaterErrorTime,
            'circuit_breaker_triggered' => $circuitBreakerTriggered,
            'error_times' => $errorTimes,
        ]);
    }

    /**
     * Test timeout handling performance
     */
    public function test_timeout_handling_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $timeoutTests = 15;
        $timeoutDurations = [];

        for ($i = 0; $i < $timeoutTests; $i++) {
            $order = $this->createTestOrder($event);
            
            // Mock timeout scenarios
            $this->mockRazorpayClientWithTimeout();
            
            $startTime = microtime(true);
            
            try {
                $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(2000),
                    currency: 'INR'
                );
                
            } catch (\Exception $e) {
                $endTime = microtime(true);
                $timeoutDuration = $endTime - $startTime;
                $timeoutDurations[] = $timeoutDuration;
            }
        }

        $avgTimeoutDuration = array_sum($timeoutDurations) / count($timeoutDurations);
        $maxTimeoutDuration = max($timeoutDurations);

        // Timeouts should be handled within reasonable time
        $this->assertLessThan(10.0, $avgTimeoutDuration, "Average timeout handling should be under 10 seconds");
        $this->assertLessThan(15.0, $maxTimeoutDuration, "Max timeout should be under 15 seconds");

        Log::info('Razorpay Error Handling Performance - Timeout Handling', [
            'timeout_tests' => $timeoutTests,
            'avg_timeout_duration' => $avgTimeoutDuration,
            'max_timeout_duration' => $maxTimeoutDuration,
            'timeout_durations' => $timeoutDurations,
        ]);
    }

    /**
     * Test retry mechanism performance
     */
    public function test_retry_mechanism_performance(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $retryTests = 20;
        $retryMetrics = [];

        for ($i = 0; $i < $retryTests; $i++) {
            $order = $this->createTestOrder($event);
            
            // Mock intermittent failures (50% success rate)
            $this->mockRazorpayClientWithIntermittentFailures();
            
            $startTime = microtime(true);
            $attempts = 0;
            $success = false;
            
            // Simulate retry logic (max 3 attempts)
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $attempts++;
                
                try {
                    $result = $this->orderCreationService->createOrder(
                        order: $order,
                        amount: new MoneyValue(1500),
                        currency: 'INR'
                    );
                    
                    if ($result) {
                        $success = true;
                        break;
                    }
                    
                } catch (\Exception $e) {
                    // Continue to next attempt
                    usleep(100000); // 100ms delay between retries
                }
            }
            
            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;
            
            $retryMetrics[] = [
                'attempts' => $attempts,
                'success' => $success,
                'total_time' => $totalTime,
            ];
        }

        $successfulRetries = count(array_filter($retryMetrics, fn($m) => $m['success']));
        $avgAttempts = array_sum(array_column($retryMetrics, 'attempts')) / count($retryMetrics);
        $avgTotalTime = array_sum(array_column($retryMetrics, 'total_time')) / count($retryMetrics);
        $successRate = $successfulRetries / $retryTests * 100;

        // Retry mechanism should improve success rate without excessive time
        $this->assertGreaterThan(70, $successRate, "Retry mechanism should improve success rate");
        $this->assertLessThan(5.0, $avgTotalTime, "Average retry time should be reasonable");

        Log::info('Razorpay Error Handling Performance - Retry Mechanism', [
            'retry_tests' => $retryTests,
            'successful_retries' => $successfulRetries,
            'success_rate' => $successRate,
            'avg_attempts' => $avgAttempts,
            'avg_total_time' => $avgTotalTime,
        ]);
    }

    /**
     * Test error logging performance impact
     */
    public function test_error_logging_performance_impact(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $errorCount = 25;

        // Test with error logging enabled
        $this->mockRazorpayClientWithErrors();
        
        $startTime = microtime(true);
        
        for ($i = 0; $i < $errorCount; $i++) {
            $order = $this->createTestOrder($event);
            
            try {
                $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(1000),
                    currency: 'INR'
                );
            } catch (\Exception $e) {
                // Error logging happens here
                Log::error('Test error for performance measurement', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $endTime = microtime(true);
        $timeWithLogging = $endTime - $startTime;

        // Test without error logging (disable logging temporarily)
        $originalLogLevel = config('logging.level');
        config(['logging.level' => 'emergency']); // Effectively disable logging
        
        $startTime = microtime(true);
        
        for ($i = 0; $i < $errorCount; $i++) {
            $order = $this->createTestOrder($event);
            
            try {
                $this->orderCreationService->createOrder(
                    order: $order,
                    amount: new MoneyValue(1000),
                    currency: 'INR'
                );
            } catch (\Exception $e) {
                // No logging
            }
        }
        
        $endTime = microtime(true);
        $timeWithoutLogging = $endTime - $startTime;
        
        // Restore original log level
        config(['logging.level' => $originalLogLevel]);

        $loggingOverhead = ($timeWithLogging - $timeWithoutLogging) / $timeWithoutLogging * 100;

        // Logging overhead should be minimal
        $this->assertLessThan(50, $loggingOverhead, "Error logging overhead should be under 50%");

        Log::info('Razorpay Error Handling Performance - Logging Impact', [
            'error_count' => $errorCount,
            'time_with_logging' => $timeWithLogging,
            'time_without_logging' => $timeWithoutLogging,
            'logging_overhead_percent' => $loggingOverhead,
        ]);
    }

    private function createTestEvent(): EventDomainObject
    {
        return $this->eventRepository->create([
            'title' => 'Error Performance Test Event',
            'description' => 'Test event for error handling performance',
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
            'short_id' => 'ERRPERF' . uniqid(),
            'email' => 'test@errorperformance.com',
            'first_name' => 'Error',
            'last_name' => 'Performance',
            'total_gross' => 50.00,
            'total_tax' => 0.00,
            'total_fee' => 0.00,
            'currency' => 'INR',
            'status' => 'awaiting_payment',
            'payment_provider' => 'RAZORPAY',
        ]);
    }

    private function mockRazorpayClient(): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('createOrder')
            ->andReturn([
                'id' => 'order_success_' . uniqid(),
                'amount' => 5000,
                'currency' => 'INR',
                'status' => 'created',
            ]);

        $this->app->instance(RazorpayClient::class, $mockClient);
    }

    private function mockRazorpayClientWithError(string $errorType): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        switch ($errorType) {
            case 'network_timeout':
                $mockClient->shouldReceive('createOrder')
                    ->andThrow(new \Exception('Network timeout occurred'));
                break;
            case 'api_error':
                $mockClient->shouldReceive('createOrder')
                    ->andThrow(new \Exception('API Error: Bad request'));
                break;
            case 'invalid_signature':
                $mockClient->shouldReceive('verifyPaymentSignature')
                    ->andReturn(false);
                break;
        }

        $this->app->instance(RazorpayClient::class, $mockClient);
    }

    private function mockRazorpayClientWithSustainedErrors(): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('createOrder')
            ->andThrow(new \Exception('Sustained API error'));

        $this->app->instance(RazorpayClient::class, $mockClient);
    }

    private function mockRazorpayClientWithTimeout(): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('createOrder')
            ->andReturnUsing(function () {
                sleep(2); // Simulate timeout
                throw new \Exception('Request timeout');
            });

        $this->app->instance(RazorpayClient::class, $mockClient);
    }

    private function mockRazorpayClientWithIntermittentFailures(): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('createOrder')
            ->andReturnUsing(function () {
                if (rand(0, 1)) { // 50% chance of success
                    return [
                        'id' => 'order_intermittent_' . uniqid(),
                        'amount' => 1500,
                        'currency' => 'INR',
                        'status' => 'created',
                    ];
                } else {
                    throw new \Exception('Intermittent failure');
                }
            });

        $this->app->instance(RazorpayClient::class, $mockClient);
    }

    private function mockRazorpayClientWithErrors(): void
    {
        $mockClient = \Mockery::mock(RazorpayClient::class);
        
        $mockClient->shouldReceive('createOrder')
            ->andThrow(new \Exception('Test error for logging performance'));

        $this->app->instance(RazorpayClient::class, $mockClient);
    }
}