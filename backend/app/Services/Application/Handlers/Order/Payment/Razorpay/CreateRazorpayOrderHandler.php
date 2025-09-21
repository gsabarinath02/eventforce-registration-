<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Razorpay;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\RazorpayPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\Razorpay\CreateRazorpayOrderFailedException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderRequestDTO;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\CreateRazorpayOrderResponseDTO;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayOrderCreationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use HiEvents\Values\MoneyValue;
use Psr\Log\LoggerInterface;
use Throwable;

readonly class CreateRazorpayOrderHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private RazorpayOrderCreationService $razorpayOrderService,
        private CheckoutSessionManagementService $sessionIdentifierService,
        private AccountRepositoryInterface $accountRepository,
        private RazorpayPaymentRepositoryInterface $razorpayPaymentRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle Razorpay order creation
     *
     * @throws CreateRazorpayOrderFailedException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     * @throws Throwable
     */
    public function handle(string $orderShortId): CreateRazorpayOrderResponseDTO
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(OrderItemDomainObject::class))
            ->loadRelation(new Relationship(RazorpayPaymentDomainObject::class, name: 'razorpay_payment'))
            ->findByShortId($orderShortId);

        if (!$order || !$this->sessionIdentifierService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        if ($order->getStatus() !== OrderStatus::RESERVED->name || $order->isReservedOrderExpired()) {
            throw new ResourceConflictException(__('Sorry, this order is expired or not in a valid state.'));
        }

        $account = $this->accountRepository
            ->loadRelation(new Relationship(
                domainObject: AccountConfigurationDomainObject::class,
                name: 'configuration',
            ))
            ->findByEventId($order->getEventId());

        // If we already have a Razorpay payment record, return existing order details
        if ($order->getRazorpayPayment() !== null) {
            $this->logger->info('Returning existing Razorpay order', [
                'order_id' => $order->getId(),
                'razorpay_order_id' => $order->getRazorpayPayment()->getRazorpayOrderId(),
            ]);

            return new CreateRazorpayOrderResponseDTO(
                razorpayOrderId: $order->getRazorpayPayment()->getRazorpayOrderId(),
                currency: $order->getCurrency(),
                amount: $order->getTotalGross() * 100, // Convert to minor units
                receipt: 'order_' . $order->getShortId(),
                status: 'created',
            );
        }

        // Create new Razorpay order
        $razorpayOrder = $this->razorpayOrderService->createOrder(new CreateRazorpayOrderRequestDTO(
            amount: MoneyValue::fromFloat($order->getTotalGross(), $order->getCurrency()),
            currencyCode: $order->getCurrency(),
            account: $account,
            order: $order,
            receipt: 'order_' . $order->getShortId(),
            notes: [
                'order_id' => $order->getId(),
                'order_short_id' => $order->getShortId(),
                'event_id' => $order->getEventId(),
            ],
        ));

        // Store payment record in database
        $this->razorpayPaymentRepository->create([
            RazorpayPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_ORDER_ID => $razorpayOrder->razorpayOrderId,
            RazorpayPaymentDomainObjectAbstract::AMOUNT_RECEIVED => null, // Will be set on payment completion
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_PAYMENT_ID => null, // Will be set on payment completion
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_SIGNATURE => null, // Will be set on payment verification
        ]);

        $this->logger->info('Razorpay order created successfully', [
            'order_id' => $order->getId(),
            'razorpay_order_id' => $razorpayOrder->razorpayOrderId,
            'amount' => $razorpayOrder->amount,
            'currency' => $razorpayOrder->currency,
        ]);

        return $razorpayOrder;
    }
}