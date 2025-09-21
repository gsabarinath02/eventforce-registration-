import {useQuery} from "@tanstack/react-query";
import {orderClientPublic} from "../api/order.client.ts";
import {IdParam} from "../types.ts";

export const useCreateRazorpayOrder = (eventId: IdParam, orderShortId: IdParam, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['razorpay-order', eventId, orderShortId],
        queryFn: () => orderClientPublic.createRazorpayOrder(eventId, orderShortId),
        enabled: enabled && !!eventId && !!orderShortId,
        staleTime: 0, // Always fetch fresh data for payment orders
        gcTime: 0, // Don't cache payment order data
    });
};