<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Razorpay;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\RazorpayPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\Razorpay\RazorpayPaymentVerificationFailedException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CompleteOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\CompleteOrderOrderDTO;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayPaymentVerificationService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

readonly class VerifyRazorpayPaymentHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private RazorpayPaymentVerificationService $verificationService,
        private CheckoutSessionManagementService $sessionIdentifierService,
        private RazorpayPaymentRepositoryInterface $razorpayPaymentRepository,
        private CompleteOrderHandler $completeOrderHandler,
        private DatabaseManager $databaseManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle Razorpay payment verification
     *
     * @throws RazorpayPaymentVerificationFailedException
     * @throws Throwable
     */
    public function handle(string $orderShortId, string $paymentId, string $razorpayOrderId, string $signature): bool
    {
        return $this->databaseManager->transaction(function () use ($orderShortId, $paymentId, $razorpayOrderId, $signature) {
            return $this->verifyPayment($orderShortId, $paymentId, $razorpayOrderId, $signature);
        });
    }

    /**
     * Verify payment and update order status
     *
     * @throws RazorpayPaymentVerificationFailedException
     * @throws Throwable
     */
    private function verifyPayment(string $orderShortId, string $paymentId, string $razorpayOrderId, string $signature): bool
    {
        // Load order with relationships
        $order = $this->orderRepository
            ->loadRelation(new Relationship(OrderItemDomainObject::class))
            ->loadRelation(new Relationship(RazorpayPaymentDomainObject::class, name: 'razorpay_payment'))
            ->findByShortId($orderShortId);

        if (!$order) {
            throw new ResourceNotFoundException('Order not found');
        }

        // Verify session (for public endpoints)
        if (!$this->sessionIdentifierService->verifySession($order->getSessionId())) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        // Check order status
        if ($order->getStatus() !== OrderStatus::RESERVED->name) {
            throw new ResourceConflictException(__('Order is not in a valid state for payment verification.'));
        }

        // Find Razorpay payment record
        $razorpayPayment = $order->getRazorpayPayment();
        if (!$razorpayPayment) {
            throw new RazorpayPaymentVerificationFailedException('No Razorpay payment record found for this order');
        }

        // Verify the Razorpay order ID matches
        if ($razorpayPayment->getRazorpayOrderId() !== $razorpayOrderId) {
            $this->logger->error('Razorpay order ID mismatch', [
                'order_short_id' => $orderShortId,
                'expected_razorpay_order_id' => $razorpayPayment->getRazorpayOrderId(),
                'provided_razorpay_order_id' => $razorpayOrderId,
            ]);

            throw new RazorpayPaymentVerificationFailedException('Razorpay order ID mismatch');
        }

        // Check if payment is already verified
        if ($razorpayPayment->getRazorpayPaymentId() && $order->getPaymentStatus() === OrderPaymentStatus::PAYMENT_RECEIVED->name) {
            $this->logger->info('Payment already verified', [
                'order_short_id' => $orderShortId,
                'payment_id' => $razorpayPayment->getRazorpayPaymentId(),
            ]);

            return true;
        }

        // Verify payment signature
        $isSignatureValid = $this->verificationService->verifyPayment($paymentId, $razorpayOrderId, $signature);

        if (!$isSignatureValid) {
            $this->logger->error('Payment signature verification failed', [
                'order_short_id' => $orderShortId,
                'payment_id' => $paymentId,
                'razorpay_order_id' => $razorpayOrderId,
            ]);

            // Update order status to payment failed
            $this->updateOrderPaymentStatus($order->getId(), OrderPaymentStatus::PAYMENT_FAILED);

            throw new RazorpayPaymentVerificationFailedException('Payment signature verification failed');
        }

        // Get payment details for additional validation
        $paymentDetails = $this->verificationService->getAndValidatePaymentDetails($paymentId);

        // Verify payment amount matches order amount
        $expectedAmount = (int) ($order->getTotalGross() * 100); // Convert to minor units
        $this->verificationService->verifyPaymentAmount($paymentDetails, $expectedAmount);

        // Verify payment currency matches order currency
        $this->verificationService->verifyPaymentCurrency($paymentDetails, $order->getCurrency());

        // Update Razorpay payment record
        $this->updateRazorpayPaymentRecord($razorpayPayment->getId(), $paymentId, $signature, $paymentDetails->amount);

        // Update order payment status
        $this->updateOrderPaymentStatus($order->getId(), OrderPaymentStatus::PAYMENT_RECEIVED);

        // Trigger order completion workflow if payment is captured
        if ($paymentDetails->status === 'captured') {
            $this->triggerOrderCompletion($order);
        }

        $this->logger->info('Payment verification successful', [
            'order_short_id' => $orderShortId,
            'payment_id' => $paymentId,
            'razorpay_order_id' => $razorpayOrderId,
            'payment_status' => $paymentDetails->status,
            'amount' => $paymentDetails->amount,
        ]);

        return true;
    }

    /**
     * Update Razorpay payment record with verification details
     */
    private function updateRazorpayPaymentRecord(int $razorpayPaymentId, string $paymentId, string $signature, int $amount): void
    {
        $this->razorpayPaymentRepository->updateWhere(
            where: [RazorpayPaymentDomainObjectAbstract::ID => $razorpayPaymentId],
            attributes: [
                RazorpayPaymentDomainObjectAbstract::RAZORPAY_PAYMENT_ID => $paymentId,
                RazorpayPaymentDomainObjectAbstract::RAZORPAY_SIGNATURE => $signature,
                RazorpayPaymentDomainObjectAbstract::AMOUNT_RECEIVED => $amount,
            ]
        );
    }

    /**
     * Update order payment status
     */
    private function updateOrderPaymentStatus(int $orderId, OrderPaymentStatus $status): void
    {
        $this->orderRepository->updateFromArray(
            id: $orderId,
            attributes: [
                OrderDomainObjectAbstract::PAYMENT_STATUS => $status->name,
            ]
        );
    }

    /**
     * Trigger order completion workflow for captured payments
     */
    private function triggerOrderCompletion($order): void
    {
        try {
            // For captured payments, we can complete the order immediately
            // This will handle inventory updates, email notifications, etc.
            $this->completeOrderHandler->handle($order->getShortId(), new CompleteOrderDTO(
                order: new CompleteOrderOrderDTO(
                    first_name: $order->getFirstName(),
                    last_name: $order->getLastName(),
                    email: $order->getEmail(),
                    locale: $order->getLocale(),
                ),
                products: collect() // Empty collection as order items are already created
            ));

            $this->logger->info('Order completion workflow triggered', [
                'order_id' => $order->getId(),
                'order_short_id' => $order->getShortId(),
            ]);

        } catch (Throwable $exception) {
            $this->logger->error('Failed to trigger order completion workflow', [
                'order_id' => $order->getId(),
                'error' => $exception->getMessage(),
            ]);

            // Don't throw the exception as payment verification was successful
            // The order completion can be handled separately
        }
    }
}