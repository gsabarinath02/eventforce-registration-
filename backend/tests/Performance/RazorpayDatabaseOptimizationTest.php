<?php

namespace Tests\Performance;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\RazorpayPaymentDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RazorpayDatabaseOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private EventRepositoryInterface $eventRepository;
    private OrderRepositoryInterface $orderRepository;
    private RazorpayPaymentRepositoryInterface $razorpayPaymentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventRepository = $this->app->make(EventRepositoryInterface::class);
        $this->orderRepository = $this->app->make(OrderRepositoryInterface::class);
        $this->razorpayPaymentRepository = $this->app->make(RazorpayPaymentRepositoryInterface::class);
    }

    /**
     * Test database indexes are properly configured for optimal performance
     */
    public function test_database_indexes_optimization(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        // Test index existence and effectiveness
        $indexes = $this->getTableIndexes('razorpay_payments');
        
        $expectedIndexes = [
            'idx_razorpay_payments_order_id',
            'idx_razorpay_payments_razorpay_order_id',
            'idx_razorpay_payments_razorpay_payment_id',
        ];

        foreach ($expectedIndexes as $expectedIndex) {
            $this->assertContains($expectedIndex, $indexes, "Missing index: {$expectedIndex}");
        }

        // Test query performance with indexes
        $event = $this->createTestEvent();
        $testDataSize = 1000;

        // Create test data
        for ($i = 0; $i < $testDataSize; $i++) {
            $order = $this->createTestOrder($event);
            $this->createTestRazorpayPayment($order);
        }

        // Test indexed queries performance
        DB::enableQueryLog();
        $startTime = microtime(true);

        // Query by order_id (should use index)
        $payments = DB::table('razorpay_payments')
            ->where('order_id', '>', 0)
            ->limit(100)
            ->get();

        $endTime = microtime(true);
        $queries = DB::getQueryLog();

        $this->assertLessThan(0.1, $endTime - $startTime, "Indexed query should be under 100ms");
        $this->assertCount(1, $queries, "Should use single optimized query");

        // Test query by razorpay_order_id (should use index)
        DB::flushQueryLog();
        $startTime = microtime(true);

        $payment = DB::table('razorpay_payments')
            ->where('razorpay_order_id', 'like', 'order_%')
            ->first();

        $endTime = microtime(true);
        $queries = DB::getQueryLog();

        $this->assertLessThan(0.05, $endTime - $startTime, "Indexed lookup should be under 50ms");

        Log::info('Razorpay Database Optimization - Index Performance', [
            'test_data_size' => $testDataSize,
            'indexed_query_time' => $endTime - $startTime,
            'available_indexes' => $indexes,
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test N+1 query prevention in payment lookups
     */
    public function test_n_plus_one_query_prevention(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $orderCount = 50;

        // Create test orders with payments
        $orders = [];
        for ($i = 0; $i < $orderCount; $i++) {
            $order = $this->createTestOrder($event);
            $this->createTestRazorpayPayment($order);
            $orders[] = $order;
        }

        DB::enableQueryLog();
        $startTime = microtime(true);

        // Test efficient bulk loading (should avoid N+1)
        $orderIds = collect($orders)->pluck('id')->toArray();
        $ordersWithPayments = $this->orderRepository->findByIds($orderIds, ['razorpayPayment']);

        $endTime = microtime(true);
        $queries = DB::getQueryLog();

        // Should use minimal queries (ideally 2: orders + payments)
        $this->assertLessThan(5, count($queries), "Should avoid N+1 queries");
        $this->assertLessThan(0.5, $endTime - $startTime, "Bulk loading should be efficient");
        $this->assertCount($orderCount, $ordersWithPayments, "Should load all orders");

        // Test individual access doesn't trigger additional queries
        DB::flushQueryLog();
        
        foreach ($ordersWithPayments as $order) {
            $payment = $order->getRazorpayPayment();
            $this->assertNotNull($payment, "Payment should be loaded");
        }

        $additionalQueries = DB::getQueryLog();
        $this->assertEmpty($additionalQueries, "Should not trigger additional queries when accessing loaded relationships");

        Log::info('Razorpay Database Optimization - N+1 Prevention', [
            'order_count' => $orderCount,
            'bulk_load_time' => $endTime - $startTime,
            'query_count' => count($queries),
            'additional_queries' => count($additionalQueries),
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test query optimization for payment status updates
     */
    public function test_payment_status_update_optimization(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $batchSize = 100;

        // Create test payments
        $payments = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $order = $this->createTestOrder($event);
            $payment = $this->createTestRazorpayPayment($order);
            $payments[] = $payment;
        }

        DB::enableQueryLog();
        $startTime = microtime(true);

        // Test bulk status updates
        $paymentIds = collect($payments)->pluck('id')->toArray();
        
        DB::table('razorpay_payments')
            ->whereIn('id', $paymentIds)
            ->update([
                'razorpay_payment_id' => DB::raw("CONCAT('pay_bulk_', id)"),
                'updated_at' => now(),
            ]);

        $endTime = microtime(true);
        $queries = DB::getQueryLog();

        $this->assertLessThan(0.2, $endTime - $startTime, "Bulk update should be under 200ms");
        $this->assertCount(1, $queries, "Should use single bulk update query");

        // Test individual updates for comparison
        DB::flushQueryLog();
        $startTime = microtime(true);

        foreach (array_slice($payments, 0, 10) as $payment) {
            DB::table('razorpay_payments')
                ->where('id', $payment->getId())
                ->update(['razorpay_signature' => 'sig_individual_' . $payment->getId()]);
        }

        $endTime = microtime(true);
        $individualQueries = DB::getQueryLog();

        $this->assertGreaterThan(5, count($individualQueries), "Individual updates should use more queries");

        Log::info('Razorpay Database Optimization - Status Update Performance', [
            'batch_size' => $batchSize,
            'bulk_update_time' => $endTime - $startTime,
            'bulk_query_count' => count($queries),
            'individual_query_count' => count($individualQueries),
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test database connection pooling and transaction optimization
     */
    public function test_transaction_optimization(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        $transactionCount = 20;

        DB::enableQueryLog();
        $startTime = microtime(true);

        // Test transaction batching
        DB::transaction(function () use ($event, $transactionCount) {
            for ($i = 0; $i < $transactionCount; $i++) {
                $order = $this->createTestOrder($event);
                $this->createTestRazorpayPayment($order);
            }
        });

        $endTime = microtime(true);
        $batchedTime = $endTime - $startTime;
        $batchedQueries = DB::getQueryLog();

        // Test individual transactions
        DB::flushQueryLog();
        $startTime = microtime(true);

        for ($i = 0; $i < $transactionCount; $i++) {
            DB::transaction(function () use ($event) {
                $order = $this->createTestOrder($event);
                $this->createTestRazorpayPayment($order);
            });
        }

        $endTime = microtime(true);
        $individualTime = $endTime - $startTime;
        $individualQueries = DB::getQueryLog();

        // Batched transactions should be more efficient
        $this->assertLessThan($individualTime, $batchedTime, "Batched transactions should be faster");
        $this->assertLessThan(count($individualQueries), count($batchedQueries), "Batched transactions should use fewer queries");

        Log::info('Razorpay Database Optimization - Transaction Performance', [
            'transaction_count' => $transactionCount,
            'batched_time' => $batchedTime,
            'individual_time' => $individualTime,
            'batched_query_count' => count($batchedQueries),
            'individual_query_count' => count($individualQueries),
            'performance_improvement' => ($individualTime - $batchedTime) / $individualTime * 100,
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test query caching effectiveness
     */
    public function test_query_caching_optimization(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        
        // Create test data
        $order = $this->createTestOrder($event);
        $payment = $this->createTestRazorpayPayment($order);

        // First query (cache miss)
        DB::enableQueryLog();
        $startTime = microtime(true);

        $result1 = $this->razorpayPaymentRepository->findByRazorpayOrderId($payment->getRazorpayOrderId());

        $endTime = microtime(true);
        $firstQueryTime = $endTime - $startTime;
        $firstQueries = DB::getQueryLog();

        // Second identical query (should be faster if cached)
        DB::flushQueryLog();
        $startTime = microtime(true);

        $result2 = $this->razorpayPaymentRepository->findByRazorpayOrderId($payment->getRazorpayOrderId());

        $endTime = microtime(true);
        $secondQueryTime = $endTime - $startTime;
        $secondQueries = DB::getQueryLog();

        $this->assertEquals($result1->getId(), $result2->getId(), "Should return same result");
        
        // If caching is implemented, second query should be faster
        if (config('cache.default') !== 'array') {
            $this->assertLessThanOrEqual($firstQueryTime, $secondQueryTime, "Cached query should not be slower");
        }

        Log::info('Razorpay Database Optimization - Query Caching', [
            'first_query_time' => $firstQueryTime,
            'second_query_time' => $secondQueryTime,
            'first_query_count' => count($firstQueries),
            'second_query_count' => count($secondQueries),
            'cache_enabled' => config('cache.default') !== 'array',
        ]);

        DB::disableQueryLog();
    }

    /**
     * Test database query explain plans for optimization
     */
    public function test_query_explain_plans(): void
    {
        $this->markTestSkipped('Performance test - run manually when needed');

        $event = $this->createTestEvent();
        
        // Create substantial test data
        for ($i = 0; $i < 100; $i++) {
            $order = $this->createTestOrder($event);
            $this->createTestRazorpayPayment($order);
        }

        // Test explain plans for common queries
        $queries = [
            'SELECT * FROM razorpay_payments WHERE order_id = 1',
            'SELECT * FROM razorpay_payments WHERE razorpay_order_id = "order_test"',
            'SELECT * FROM razorpay_payments WHERE razorpay_payment_id = "pay_test"',
            'SELECT rp.*, o.* FROM razorpay_payments rp JOIN orders o ON rp.order_id = o.id WHERE o.event_id = 1',
        ];

        $explainResults = [];

        foreach ($queries as $query) {
            try {
                $explain = DB::select("EXPLAIN {$query}");
                $explainResults[$query] = $explain;

                // Check for full table scans (should be avoided)
                foreach ($explain as $row) {
                    if (isset($row->type) && $row->type === 'ALL') {
                        Log::warning('Full table scan detected', [
                            'query' => $query,
                            'explain' => $row,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Query explain failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Razorpay Database Optimization - Query Explain Plans', [
            'analyzed_queries' => count($queries),
            'explain_results' => $explainResults,
        ]);

        $this->assertNotEmpty($explainResults, "Should analyze query plans");
    }

    private function createTestEvent(): EventDomainObject
    {
        return $this->eventRepository->create([
            'title' => 'DB Optimization Test Event',
            'description' => 'Test event for database optimization',
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
            'short_id' => 'DBOPT' . uniqid(),
            'email' => 'test@dboptimization.com',
            'first_name' => 'Database',
            'last_name' => 'Optimization',
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
            'razorpay_order_id' => 'order_dbopt_' . uniqid(),
            'razorpay_payment_id' => 'pay_dbopt_' . uniqid(),
            'razorpay_signature' => 'sig_dbopt_' . uniqid(),
            'amount_received' => 5000,
        ]);
    }

    private function getTableIndexes(string $tableName): array
    {
        $indexes = DB::select("SHOW INDEX FROM {$tableName}");
        return collect($indexes)->pluck('Key_name')->unique()->toArray();
    }
}