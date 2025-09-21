<?php

namespace HiEvents\Http\Actions\Orders\Payment\Razorpay;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Order\RefundRazorpayOrderRequest;
use HiEvents\Services\Application\Handlers\Order\Payment\Razorpay\RefundRazorpayOrderHandler;
use HiEvents\Values\MoneyValue;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RefundRazorpayOrderAction extends BaseAction
{
    public function __construct(
        private readonly RefundRazorpayOrderHandler $refundRazorpayOrderHandler
    ) {
    }

    public function __invoke(RefundRazorpayOrderRequest $request, int $eventId, int $orderId): JsonResponse
    {
        try {
            $refundAmount = MoneyValue::fromFloat(
                $request->input('refund_amount'),
                $request->input('currency', 'INR')
            );

            $refund = $this->refundRazorpayOrderHandler->handle($orderId, $refundAmount);

            return $this->jsonResponse([
                'refund_id' => $refund->refundId,
                'payment_id' => $refund->paymentId,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}