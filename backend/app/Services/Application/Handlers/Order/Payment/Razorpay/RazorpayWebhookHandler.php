<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Razorpay;

use HiEvents\Exceptions\Razorpay\RazorpayWebhookVerificationFailedException;
use HiEvents\Services\Domain\Payment\Razorpay\RazorpayWebhookService;
use Illuminate\Cache\Repository;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

readonly class RazorpayWebhookHandler
{
    private const VALID_EVENTS = [
        'payment.authorized',
        'payment.captured',
        'payment.failed',
        'refund.processed',
    ];

    public function __construct(
        private RazorpayWebhookService $webhookService,
        private Repository $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Handle incoming Razorpay webhook
     *
     * @throws RazorpayWebhookVerificationFailedException
     * @throws Throwable
     */
    public function handle(string $payload, string $signature): void
    {
        try {
            // Verify and parse the webhook
            $event = $this->webhookService->verifyAndParseWebhook($payload, $signature);

            // Check if this is a supported event type
            if (!in_array($event->event, self::VALID_EVENTS, true)) {
                $this->logger->debug(__('Received a :event Razorpay event, which has no handler', [
                    'event' => $event->event,
                ]), [
                    'event_id' => $event->id,
                    'event_type' => $event->event,
                ]);

                return;
            }

            // Check if event has already been processed (idempotency)
            if ($this->hasEventBeenHandled($event->id)) {
                $this->logger->debug('Razorpay event already handled', [
                    'event_id' => $event->id,
                    'event_type' => $event->event,
                    'payment_id' => $event->getPaymentId(),
                    'order_id' => $event->getOrderId(),
                ]);

                return;
            }

            // Validate the event data
            if (!$this->webhookService->validateWebhookEvent($event)) {
                $this->logger->warning('Invalid Razorpay webhook event data', [
                    'event_id' => $event->id,
                    'event_type' => $event->event,
                    'payment_id' => $event->getPaymentId(),
                    'order_id' => $event->getOrderId(),
                ]);
                return;
            }

            $this->logger->debug('Razorpay webhook event received', [
                'event_id' => $event->id,
                'event_type' => $event->event,
                'payment_id' => $event->getPaymentId(),
                'order_id' => $event->getOrderId(),
            ]);

            // Process the webhook event
            $this->webhookService->processWebhookEvent($event);

            // Mark event as handled to prevent duplicate processing
            $this->markEventAsHandled($event->id);

            $this->logger->info('Razorpay webhook processed successfully', [
                'event_id' => $event->id,
                'event_type' => $event->event,
                'payment_id' => $event->getPaymentId(),
                'order_id' => $event->getOrderId(),
            ]);

        } catch (RazorpayWebhookVerificationFailedException $exception) {
            $this->logger->error('Razorpay webhook verification failed', [
                'error' => $exception->getMessage(),
                'payload_length' => strlen($payload),
            ]);

            throw $exception;

        } catch (UnexpectedValueException $exception) {
            $this->logger->error('Unexpected value in Razorpay webhook payload', [
                'error' => $exception->getMessage(),
                'payload_length' => strlen($payload),
            ]);

            throw $exception;

        } catch (Throwable $exception) {
            $this->logger->error('Unhandled Razorpay webhook error', [
                'error' => $exception->getMessage(),
                'payload_length' => strlen($payload),
            ]);

            throw $exception;
        }
    }

    /**
     * Check if webhook event has already been processed
     */
    private function hasEventBeenHandled(string $eventId): bool
    {
        return $this->cache->has('razorpay_event_' . $eventId);
    }

    /**
     * Mark webhook event as processed to prevent duplicate handling
     */
    private function markEventAsHandled(string $eventId): void
    {
        $this->logger->info('Marking Razorpay event as handled', [
            'event_id' => $eventId,
        ]);

        // Cache for 60 minutes to prevent duplicate processing
        $this->cache->put('razorpay_event_' . $eventId, true, now()->addMinutes(60));
    }

    /**
     * Get supported webhook event types
     */
    public static function getSupportedEvents(): array
    {
        return self::VALID_EVENTS;
    }

    /**
     * Check if an event type is supported
     */
    public static function isEventSupported(string $eventType): bool
    {
        return in_array($eventType, self::VALID_EVENTS, true);
    }
}