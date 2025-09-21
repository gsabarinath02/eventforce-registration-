import {useState, useEffect} from "react";
import {useNavigate, useParams} from "react-router";
import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {StripePaymentMethod} from "./PaymentMethods/Stripe";
import {OfflinePaymentMethod} from "./PaymentMethods/Offline";
import {RazorpayPaymentMethod} from "./PaymentMethods/Razorpay";
import {Event, Order} from "../../../../types.ts";
import {CheckoutFooter} from "../../../layouts/Checkout/CheckoutFooter";
import {Group} from "@mantine/core";
import {formatCurrency} from "../../../../utilites/currency.ts";
import {t} from "@lingui/macro";
import {useGetOrderPublic} from "../../../../queries/useGetOrderPublic.ts";
import {
    useTransitionOrderToOfflinePaymentPublic
} from "../../../../mutations/useTransitionOrderToOfflinePaymentPublic.ts";
import {Card} from "../../../common/Card";
import {showError} from "../../../../utilites/notifications.tsx";

const Payment = () => {
    const navigate = useNavigate();
    const {eventId, orderShortId} = useParams();
    const {data: event, isFetched: isEventFetched} = useGetEventPublic(eventId);
    const {data: order, isFetched: isOrderFetched} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const isLoading = !isOrderFetched;
    const [isPaymentLoading, setIsPaymentLoading] = useState(false);
    const [activePaymentMethod, setActivePaymentMethod] = useState<'STRIPE' | 'OFFLINE' | 'RAZORPAY' | null>(null);
    const [submitHandler, setSubmitHandler] = useState<(() => Promise<void>) | null>(null);
    const transitionOrderToOfflinePaymentMutation = useTransitionOrderToOfflinePaymentPublic();

    const isStripeEnabled = event?.settings?.payment_providers?.includes('STRIPE');
    const isOfflineEnabled = event?.settings?.payment_providers?.includes('OFFLINE');
    const isRazorpayEnabled = event?.settings?.payment_providers?.includes('RAZORPAY');

    useEffect(() => {
        // Set Razorpay as default when enabled, otherwise fall back to other methods
        if (isRazorpayEnabled) {
            setActivePaymentMethod('RAZORPAY');
        } else if (isStripeEnabled) {
            setActivePaymentMethod('STRIPE');
        } else if (isOfflineEnabled) {
            setActivePaymentMethod('OFFLINE');
        } else {
            setActivePaymentMethod(null); // No methods available
        }
    }, [isRazorpayEnabled, isStripeEnabled, isOfflineEnabled]);

    const handleParentSubmit = () => {
        if (submitHandler) {
            setIsPaymentLoading(true);
            submitHandler().finally(() => setIsPaymentLoading(false));
        }
    };

    const handleSubmit = async () => {
        if (activePaymentMethod === 'STRIPE' || activePaymentMethod === 'RAZORPAY') {
            handleParentSubmit();
        } else if (activePaymentMethod === 'OFFLINE') {
            setIsPaymentLoading(true);

            await transitionOrderToOfflinePaymentMutation.mutateAsync({
                eventId,
                orderShortId
            }, {
                onSuccess: () => {
                    navigate(`/checkout/${eventId}/${orderShortId}/summary`);
                },
                onError: (error: any) => {
                    setIsPaymentLoading(false);
                    showError(error.response?.data?.message || t`Offline payment failed. Please try again or contact the event organizer.`);
                }
            });
        }
    };

    if (!isStripeEnabled && !isOfflineEnabled && !isRazorpayEnabled && isOrderFetched && isEventFetched) {
        return (
            <CheckoutContent>
                <Card>
                    {t`No payment methods are currently available. Please contact the event organizer for assistance.`}
                </Card>
            </CheckoutContent>
        );
    }

    return (
        <>
            <CheckoutContent>
                {isRazorpayEnabled && (
                    <div style={{display: activePaymentMethod === 'RAZORPAY' ? 'block' : 'none'}}>
                        <RazorpayPaymentMethod enabled={true} setSubmitHandler={setSubmitHandler}/>
                    </div>
                )}

                {isStripeEnabled && (
                    <div style={{display: activePaymentMethod === 'STRIPE' ? 'block' : 'none'}}>
                        <StripePaymentMethod enabled={true} setSubmitHandler={setSubmitHandler}/>
                    </div>
                )}

                {isOfflineEnabled && (
                    <div style={{display: activePaymentMethod === 'OFFLINE' ? 'block' : 'none'}}>
                        <OfflinePaymentMethod event={event as Event}/>
                    </div>
                )}

                {/* Payment method switching logic */}
                {((isRazorpayEnabled && isStripeEnabled) || (isRazorpayEnabled && isOfflineEnabled) || (isStripeEnabled && isOfflineEnabled)) && (
                    <div style={{marginTop: '20px'}}>
                        {/* Show Razorpay/Stripe switching */}
                        {isRazorpayEnabled && isStripeEnabled && !isOfflineEnabled && (
                            <a
                                onClick={() => setActivePaymentMethod(
                                    activePaymentMethod === 'RAZORPAY' ? 'STRIPE' : 'RAZORPAY'
                                )}
                                style={{cursor: 'pointer'}}
                            >
                                {activePaymentMethod === 'RAZORPAY'
                                    ? t`I would like to pay using Stripe instead`
                                    : t`I would like to pay using Razorpay instead`
                                }
                            </a>
                        )}

                        {/* Show Razorpay/Offline switching */}
                        {isRazorpayEnabled && isOfflineEnabled && !isStripeEnabled && (
                            <a
                                onClick={() => setActivePaymentMethod(
                                    activePaymentMethod === 'RAZORPAY' ? 'OFFLINE' : 'RAZORPAY'
                                )}
                                style={{cursor: 'pointer'}}
                            >
                                {activePaymentMethod === 'RAZORPAY'
                                    ? t`I would like to pay using an offline method`
                                    : t`I would like to pay using an online method`
                                }
                            </a>
                        )}

                        {/* Show Stripe/Offline switching (legacy) */}
                        {isStripeEnabled && isOfflineEnabled && !isRazorpayEnabled && (
                            <a
                                onClick={() => setActivePaymentMethod(
                                    activePaymentMethod === 'STRIPE' ? 'OFFLINE' : 'STRIPE'
                                )}
                                style={{cursor: 'pointer'}}
                            >
                                {activePaymentMethod === 'STRIPE'
                                    ? t`I would like to pay using an offline method`
                                    : t`I would like to pay using an online method (credit card etc.)`
                                }
                            </a>
                        )}

                        {/* Show multiple options dropdown when all three are enabled */}
                        {isRazorpayEnabled && isStripeEnabled && isOfflineEnabled && (
                            <div>
                                <p style={{marginBottom: '10px', fontSize: '14px', color: '#666'}}>
                                    {t`Choose a different payment method:`}
                                </p>
                                <div style={{display: 'flex', gap: '15px', flexWrap: 'wrap'}}>
                                    {activePaymentMethod !== 'RAZORPAY' && (
                                        <a
                                            onClick={() => setActivePaymentMethod('RAZORPAY')}
                                            style={{cursor: 'pointer', fontSize: '14px'}}
                                        >
                                            {t`Razorpay`}
                                        </a>
                                    )}
                                    {activePaymentMethod !== 'STRIPE' && (
                                        <a
                                            onClick={() => setActivePaymentMethod('STRIPE')}
                                            style={{cursor: 'pointer', fontSize: '14px'}}
                                        >
                                            {t`Stripe`}
                                        </a>
                                    )}
                                    {activePaymentMethod !== 'OFFLINE' && (
                                        <a
                                            onClick={() => setActivePaymentMethod('OFFLINE')}
                                            style={{cursor: 'pointer', fontSize: '14px'}}
                                        >
                                            {t`Offline Payment`}
                                        </a>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </CheckoutContent>

            <CheckoutFooter
                event={event as Event}
                order={order as Order}
                isLoading={isLoading || isPaymentLoading}
                onClick={handleSubmit}
                buttonContent={order?.is_payment_required ? (
                    <Group gap={'10px'}>
                        <div style={{fontWeight: "bold"}}>
                            {t`Place Order`}
                        </div>
                        <div style={{fontSize: 14}}>
                            {formatCurrency(order.total_gross, order.currency)}
                        </div>
                        <div style={{fontSize: 14, fontWeight: 500}}>
                            {order.currency}
                        </div>
                    </Group>
                ) : t`Complete Payment`}
            />
        </>
    );
}

export default Payment;
