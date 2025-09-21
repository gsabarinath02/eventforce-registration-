<?php

namespace HiEvents\Http\Actions\Orders\Payment\Razorpay;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\VerifyRazorpayPaymentRequest;
use HiEvents\Services\Application\Handlers\Order\Payment\Razorpay\VerifyRazorpayPaymentHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class VerifyRazorpayPaymentActionPublic extends BaseAction
{
    public function __construct(
        private readonly VerifyRazorpayPaymentHandler $verifyRazorpayPaymentHandler
    ) {
    }

    public function __invoke(VerifyRazorpayPaymentRequest $request, int $eventId, string $orderShortId): JsonResponse
    {
        try {
            $paymentId = $request->input('razorpay_payment_id');
            $orderId = $request->input('razorpay_order_id');
            $signature = $request->input('razorpay_signature');

            $isVerified = $this->verifyRazorpayPaymentHandler->handle(
                $orderShortId,
                $paymentId,
                $orderId,
                $signature
            );

            // Return wrapped response to match frontend expectations: { data: { success, message } }
            return $this->jsonResponse([
                'success' => $isVerified,
                // Keep legacy key for backward compatibility if any consumers rely on it
                'verified' => $isVerified,
                'message' => $isVerified ? 'Payment verified successfully' : 'Payment verification failed',
            ], Response::HTTP_OK, true);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}