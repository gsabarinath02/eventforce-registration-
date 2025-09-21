<?php

namespace Tests\Unit\Services\Domain\Payment\Razorpay;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\Razorpay\CreateRazorpayOrderFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderRequestDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayOrderCreationService;
use HiEvents\Services\Infrastructure\Razorpay\RazorpayClient;
use HiEvents\Values\MoneyValue;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class RazorpayOrderCreationServiceTest extends TestCase
{
    private RazorpayClient $razorpayClient;
    private LoggerInterface $logger;
    private RazorpayOrderCreationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->razorpayClient = m::mock(RazorpayClient::class);
        $this->logger = m::mock(LoggerInterface::class);

        $this->service = new RazorpayOrderCreationService(
            razorpayClient: $this->razorpayClient,
            logger: $this->logger,
        );
    }

    public function testCreateOrderSuccessfully(): void
    {
        // Arrange
        $amount = new MoneyValue(50000); // ₹500.00
        $currency = 'INR';
        $account = m::mock(AccountDomainObject::class);
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(123);

        $orderRequest = new CreateRazorpayOrderRequestDTO(
            amount: $amount,
            currencyCode: $currency,
            account: $account,
            order: $order,
            receipt: 'order_123',
            notes: ['order_id' => '123']
        );

        $expectedResponse = new CreateRazorpayOrderResponseDTO(
            razorpayOrderId: 'order_test123',
            currency: 'INR',
            amount: 50000,
            receipt: 'order_123',
            status: 'created'
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay order creation requested', [
                'order_id' => 123,
                'amount' => 50000,
                'currency' => 'INR',
            ]);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay order created successfully', [
                'order_id' => 123,
                'razorpay_order_id' => 'order_test123',
                'amount' => 50000,
                'status' => 'created',
            ]);

        // Mock Razorpay client
        $this->razorpayClient->shouldReceive('createOrder')
            ->once()
            ->with($orderRequest)
            ->andReturn($expectedResponse);

        // Act
        $result = $this->service->createOrder($orderRequest);

        // Assert
        $this->assertEquals($expectedResponse, $result);
        $this->assertEquals('order_test123', $result->razorpayOrderId);
        $this->assertEquals(50000, $result->amount);
        $this->assertEquals('INR', $result->currency);
    }

    public function testCreateOrderWithUnsupportedCurrency(): void
    {
        // Arrange
        $amount = new MoneyValue(50000);
        $currency = 'JPY'; // Unsupported currency
        $account = m::mock(AccountDomainObject::class);
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(123);

        $orderRequest = new CreateRazorpayOrderRequestDTO(
            amount: $amount,
            currencyCode: $currency,
            account: $account,
            order: $order
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay order creation requested', [
                'order_id' => 123,
                'amount' => 50000,
                'currency' => 'JPY',
            ]);

        // Act & Assert
        $this->expectException(CreateRazorpayOrderFailedException::class);
        $this->expectExceptionMessage("Currency 'JPY' is not supported by Razorpay");

        $this->service->createOrder($orderRequest);
    }

    public function testCreateOrderWithAmountTooSmall(): void
    {
        // Arrange
        $amount = new MoneyValue(50); // ₹0.50 - below minimum
        $currency = 'INR';
        $account = m::mock(AccountDomainObject::class);
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(123);

        $orderRequest = new CreateRazorpayOrderRequestDTO(
            amount: $amount,
            currencyCode: $currency,
            account: $account,
            order: $order
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay order creation requested', [
                'order_id' => 123,
                'amount' => 50,
                'currency' => 'INR',
            ]);

        // Act & Assert
        $this->expectException(CreateRazorpayOrderFailedException::class);
        $this->expectExceptionMessage('Amount is too small. Minimum amount for INR is ₹1.00');

        $this->service->createOrder($orderRequest);
    }

    public function testCreateOrderWithAmountTooLarge(): void
    {
        // Arrange
        $amount = new MoneyValue(1600000000); // Above maximum
        $currency = 'INR';
        $account = m::mock(AccountDomainObject::class);
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(123);

        $orderRequest = new CreateRazorpayOrderRequestDTO(
            amount: $amount,
            currencyCode: $currency,
            account: $account,
            order: $order
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay order creation requested', [
                'order_id' => 123,
                'amount' => 1600000000,
                'currency' => 'INR',
            ]);

        // Act & Assert
        $this->expectException(CreateRazorpayOrderFailedException::class);
        $this->expectExceptionMessage('Amount is too large. Maximum amount for INR is ₹15,00,00,000');

        $this->service->createOrder($orderRequest);
    }

    public function testCreateOrderWithZeroAmount(): void
    {
        // Arrange
        $amount = new MoneyValue(0);
        $currency = 'INR';
        $account = m::mock(AccountDomainObject::class);
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(123);

        $orderRequest = new CreateRazorpayOrderRequestDTO(
            amount: $amount,
            currencyCode: $currency,
            account: $account,
            order: $order
        );

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay order creation requested', [
                'order_id' => 123,
                'amount' => 0,
                'currency' => 'INR',
            ]);

        // Act & Assert
        $this->expectException(CreateRazorpayOrderFailedException::class);
        $this->expectExceptionMessage('Amount must be greater than zero');

        $this->service->createOrder($orderRequest);
    }

    public function testCreateOrderWithRazorpayClientException(): void
    {
        // Arrange
        $amount = new MoneyValue(50000);
        $currency = 'INR';
        $account = m::mock(AccountDomainObject::class);
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(123);

        $orderRequest = new CreateRazorpayOrderRequestDTO(
            amount: $amount,
            currencyCode: $currency,
            account: $account,
            order: $order
        );

        $exception = new CreateRazorpayOrderFailedException('API Error');

        // Mock logger calls
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Razorpay order creation requested', [
                'order_id' => 123,
                'amount' => 50000,
                'currency' => 'INR',
            ]);

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Razorpay order creation failed', [
                'order_id' => 123,
                'error' => 'API Error',
            ]);

        // Mock Razorpay client to throw exception
        $this->razorpayClient->shouldReceive('createOrder')
            ->once()
            ->with($orderRequest)
            ->andThrow($exception);

        // Act & Assert
        $this->expectException(CreateRazorpayOrderFailedException::class);
        $this->expectExceptionMessage('API Error');

        $this->service->createOrder($orderRequest);
    }

    public function testCreateOrderWithSupportedCurrencies(): void
    {
        $supportedCurrencies = ['INR', 'USD', 'EUR', 'GBP', 'SGD', 'AED', 'MYR'];

        foreach ($supportedCurrencies as $currency) {
            // Arrange
            $amount = new MoneyValue(50000);
            $account = m::mock(AccountDomainObject::class);
            $order = m::mock(OrderDomainObject::class);
            $order->shouldReceive('getId')->andReturn(123);

            $orderRequest = new CreateRazorpayOrderRequestDTO(
                amount: $amount,
                currencyCode: $currency,
                account: $account,
                order: $order
            );

            $expectedResponse = new CreateRazorpayOrderResponseDTO(
                razorpayOrderId: 'order_test123',
                currency: $currency,
                amount: 50000,
                status: 'created'
            );

            // Mock logger calls
            $this->logger->shouldReceive('info')
                ->once()
                ->with('Razorpay order creation requested', [
                    'order_id' => 123,
                    'amount' => 50000,
                    'currency' => $currency,
                ]);

            $this->logger->shouldReceive('info')
                ->once()
                ->with('Razorpay order created successfully', [
                    'order_id' => 123,
                    'razorpay_order_id' => 'order_test123',
                    'amount' => 50000,
                    'status' => 'created',
                ]);

            // Mock Razorpay client
            $this->razorpayClient->shouldReceive('createOrder')
                ->once()
                ->with($orderRequest)
                ->andReturn($expectedResponse);

            // Act
            $result = $this->service->createOrder($orderRequest);

            // Assert
            $this->assertEquals($currency, $result->currency);
        }
    }
}