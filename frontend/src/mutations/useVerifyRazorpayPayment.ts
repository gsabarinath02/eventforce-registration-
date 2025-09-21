import {useMutation} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam, RazorpayPaymentResponse} from "../types.ts";

export const useVerifyRazorpayPayment = () => {
    return useMutation({
        mutationFn: ({
            eventId,
            orderShortId,
            payload
        }: {
            eventId: IdParam;
            orderShortId: IdParam;
            payload: RazorpayPaymentResponse;
        }) => orderClientPublic.verifyRazorpayPayment(eventId, orderShortId, payload),
    });
};