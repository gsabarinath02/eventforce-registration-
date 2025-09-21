<?php

namespace HiEvents\Services\Domain\Payment\Razorpay\EventHandlers;

use HiEvents\DomainObjects\Generated\RazorpayPaymentDomainObjectAbstract;
use HiEvents\Repository\Interfaces\RazorpayPaymentRepositoryInterface;
use HiEvents\Services\Domain\Payment\Razorpay\DTOs\RazorpayWebhookEventDTO;
use Illuminate\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class RefundProcessedHandler
{
    public function __construct(
        private readonly RazorpayPaymentRepositoryInterface $razorpayPaymentRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
        private readonly Repository $cache,
    ) {
    }

    /**
     * Handle refund.processed webhook event
     */
    public function handleEvent(RazorpayWebhookEventDTO $event): void
    {
        $refundEntity = $event->getRefundEntity();
        
        if (!$refundEntity || empty($refundEntity['id'])) {
            $this->logger->error('Invalid refund.processed event: missing refund entity', [
                'event' => $event->event,
            ]);
            return;
        }

        $refundId = $refundEntity['id'];
        
        if ($this->isEventAlreadyHandled($event->event, $refundId)) {
            $this->logger->info('Refund processed event already handled', [
                'refund_id' => $refundId,
                'event' => $event->event,
            ]);
            return;
        }

        try {
            $this->databaseManager->transaction(function () use ($event, $refundEntity, $refundId) {
                $razorpayPayment = $this->findRazorpayPayment($refundEntity);
                
                if (!$razorpayPayment) {
                    $this->logger->error('Razorpay payment not found for refund processed event', [
                        'refund_id' => $refundId,
                        'payment_id' => $refundEntity['payment_id'] ?? null,
                    ]);
                    return;
                }

                $this->updatePaymentRecord($razorpayPayment, $refundEntity);

                $this->markEventAsHandled($event->event, $refundId);

                $this->logger->info('Refund processed event handled successfully', [
                    'refund_id' => $refundId,
                    'payment_id' => $refundEntity['payment_id'] ?? null,
                    'amount' => $refundEntity['amount'] ?? null,
                    'status' => $refundEntity['status'] ?? null,
                ]);
            });

        } catch (Throwable $exception) {
            $this->logger->error('Failed to process refund.processed event', [
                'error' => $exception->getMessage(),
                'refund_id' => $refundId,
                'event' => $event->event,
            ]);
            throw $exception;
        }
    }

    private function findRazorpayPayment(array $refundEntity): ?RazorpayPaymentDomainObjectAbstract
    {
        $paymentId = $refundEntity['payment_id'] ?? null;
        
        if (!$paymentId) {
            return null;
        }

        return $this->razorpayPaymentRepository->findFirstWhere([
            RazorpayPaymentDomainObjectAbstract::RAZORPAY_PAYMENT_ID => $paymentId,
        ]);
    }

    private function updatePaymentRecord(RazorpayPaymentDomainObjectAbstract $razorpayPayment, array $refundEntity): void
    {
        $this->razorpayPaymentRepository->updateWhere(
            attributes: [
                RazorpayPaymentDomainObjectAbstract::REFUND_ID => $refundEntity['id'],
            ],
            where: [
                RazorpayPaymentDomainObjectAbstract::ID => $razorpayPayment->getId(),
            ]
        );
    }

    private function isEventAlreadyHandled(string $eventType, string $refundId): bool
    {
        return $this->cache->has("razorpay_event_{$eventType}_{$refundId}");
    }

    private function markEventAsHandled(string $eventType, string $refundId): void
    {
        $this->cache->put("razorpay_event_{$eventType}_{$refundId}", true, now()->addHours(24));
    }
}