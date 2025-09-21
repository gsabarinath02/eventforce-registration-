<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Razorpay;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\RazorpayPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Exceptions\Razorpay\RazorpayRefundFailedException;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayRefundService;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

readonly class RefundRazorpayOrderHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private RazorpayRefundService $refundService,
        private EventRepositoryInterface $eventRepository,
        private RazorpayPaymentRepositoryInterface $razorpayPaymentRepository,
        private Mailer $mailer,
        private OrderCancelService $orderCancelService,
        private DatabaseManager $databaseManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle Razorpay order refund
     *
     * @throws RefundNotPossibleException
     * @throws RazorpayRefundFailedException
     * @throws Throwable
     */
    public function handle(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        return $this->databaseManager->transaction(fn() => $this->refundOrder($refundOrderDTO));
    }

    /**
     * Process the refund operation
     *
     * @throws RefundNotPossibleException
     * @throws RazorpayRefundFailedException
     * @throws Throwable
     */
    private function refundOrder(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        $order = $this->fetchOrder($refundOrderDTO->event_id, $refundOrderDTO->order_id);
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($refundOrderDTO->event_id);

        $amount = MoneyValue::fromFloat($refundOrderDTO->amount, $order->getCurrency());

        $this->validateRefundability($order);

        // Cancel order if requested
        if ($refundOrderDTO->cancel_order) {
            $this->orderCancelService->cancelOrder($order);
        }

        // Process the refund through Razorpay
        $razorpayPayment = $order->getRazorpayPayment();
        $refundResult = $this->refundService->refundPayment(
            $razorpayPayment->getRazorpayPaymentId(),
            $amount
        );

        // Update Razorpay payment record with refund information
        $this->updateRazorpayPaymentRecord($razorpayPayment->getId(), $refundResult->refundId);

        // Send notification to buyer if requested
        if ($refundOrderDTO->notify_buyer) {
            $this->notifyBuyer($order, $event, $amount);
        }

        // Mark order as refund pending
        $updatedOrder = $this->markOrderRefundPending($order);

        $this->logger->info('Razorpay refund processed successfully', [
            'order_id' => $order->getId(),
            'refund_id' => $refundResult->refundId,
            'amount' => $amount->toMinorUnit(),
            'currency' => $order->getCurrency(),
        ]);

        return $updatedOrder;
    }

    /**
     * Fetch order with Razorpay payment relationship
     */
    private function fetchOrder(int $eventId, int $orderId): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(RazorpayPaymentDomainObject::class, name: 'razorpay_payment'))
            ->findFirstWhere(['event_id' => $eventId, 'id' => $orderId]);

        if (!$order) {
            throw new ResourceNotFoundException('Order not found');
        }

        return $order;
    }

    /**
     * Validate that the order can be refunded
     *
     * @throws RefundNotPossibleException
     */
    private function validateRefundability(OrderDomainObject $order): void
    {
        if (!$order->getRazorpayPayment()) {
            throw new RefundNotPossibleException(__('There is no Razorpay payment data associated with this order.'));
        }

        if ($order->getRefundStatus() === OrderRefundStatus::REFUND_PENDING->name) {
            throw new RefundNotPossibleException(
                __('There is already a refund pending for this order. Please wait for the refund to be processed before requesting another one.')
            );
        }

        $razorpayPayment = $order->getRazorpayPayment();
        if (!$razorpayPayment->getRazorpayPaymentId()) {
            throw new RefundNotPossibleException(__('No valid Razorpay payment ID found for this order.'));
        }

        // Check if payment is eligible for refund using the refund service
        if (!$this->refundService->isRefundEligible($razorpayPayment->getRazorpayPaymentId())) {
            throw new RefundNotPossibleException(__('This payment is not eligible for refund.'));
        }
    }

    /**
     * Update Razorpay payment record with refund information
     */
    private function updateRazorpayPaymentRecord(int $razorpayPaymentId, string $refundId): void
    {
        $this->razorpayPaymentRepository->updateWhere(
            where: [RazorpayPaymentDomainObjectAbstract::ID => $razorpayPaymentId],
            attributes: [
                RazorpayPaymentDomainObjectAbstract::REFUND_ID => $refundId,
            ]
        );
    }

    /**
     * Send refund notification to buyer
     */
    private function notifyBuyer(OrderDomainObject $order, EventDomainObject $event, MoneyValue $amount): void
    {
        try {
            $this->mailer
                ->to($order->getEmail())
                ->locale($order->getLocale())
                ->send(new OrderRefunded(
                    order: $order,
                    event: $event,
                    organizer: $event->getOrganizer(),
                    eventSettings: $event->getEventSettings(),
                    refundAmount: $amount
                ));

            $this->logger->info('Refund notification sent to buyer', [
                'order_id' => $order->getId(),
                'email' => $order->getEmail(),
                'amount' => $amount->toMinorUnit(),
            ]);

        } catch (Throwable $exception) {
            $this->logger->error('Failed to send refund notification', [
                'order_id' => $order->getId(),
                'email' => $order->getEmail(),
                'error' => $exception->getMessage(),
            ]);

            // Don't throw the exception as the refund was successful
            // The notification failure shouldn't block the refund process
        }
    }

    /**
     * Mark order as refund pending
     */
    private function markOrderRefundPending(OrderDomainObject $order): OrderDomainObject
    {
        return $this->orderRepository->updateFromArray(
            id: $order->getId(),
            attributes: [
                OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_PENDING->name,
            ]
        );
    }

    /**
     * Check maximum refundable amount for an order
     */
    public function getMaxRefundableAmount(int $eventId, int $orderId): float
    {
        try {
            $order = $this->fetchOrder($eventId, $orderId);
            $razorpayPayment = $order->getRazorpayPayment();

            if (!$razorpayPayment || !$razorpayPayment->getRazorpayPaymentId()) {
                return 0.0;
            }

            $maxRefundableMinorUnits = $this->refundService->getMaxRefundableAmount(
                $razorpayPayment->getRazorpayPaymentId()
            );

            // Convert from minor units to major units
            return $maxRefundableMinorUnits / 100.0;

        } catch (Throwable $exception) {
            $this->logger->error('Failed to get max refundable amount', [
                'event_id' => $eventId,
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            return 0.0;
        }
    }

    /**
     * Check if an order is eligible for refund
     */
    public function isRefundEligible(int $eventId, int $orderId): bool
    {
        try {
            $order = $this->fetchOrder($eventId, $orderId);
            $this->validateRefundability($order);
            return true;

        } catch (Throwable $exception) {
            $this->logger->debug('Order not eligible for refund', [
                'event_id' => $eventId,
                'order_id' => $orderId,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}