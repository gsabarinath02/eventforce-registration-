import {useEffect, useState} from "react";
import {useParams, useNavigate} from "react-router";
import {Alert, Skeleton} from "@mantine/core";
import {t} from "@lingui/macro";
import classes from './RazorpayCheckoutForm.module.scss';
import {LoadingMask} from "../../common/LoadingMask";
import {useGetOrderPublic} from "../../../queries/useGetOrderPublic.ts";
import {useCreateRazorpayOrder} from "../../../queries/useCreateRazorpayOrder.ts";
import {useVerifyRazorpayPayment} from "../../../mutations/useVerifyRazorpayPayment.ts";
import {CheckoutContent} from "../../layouts/Checkout/CheckoutContent";
import {HomepageInfoMessage} from "../../common/HomepageInfoMessage";
import {eventCheckoutPath, eventHomepagePath} from "../../../utilites/urlHelper.ts";
import {Event} from "../../../types.ts";
import {getConfig} from "../../../utilites/config.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {getRazorpayConfig, validateRazorpayConfig} from "../../../utilites/razorpayConfig.ts";
import {formatCurrency} from "../../../utilites/currency.ts";

// Declare Razorpay types for TypeScript
declare global {
    interface Window {
        Razorpay: any;
    }
}

interface RazorpayResponse {
    razorpay_payment_id: string;
    razorpay_order_id: string;
    razorpay_signature: string;
}

export default function RazorpayCheckoutForm({setSubmitHandler}: {
    setSubmitHandler: (submitHandler: () => () => Promise<void>) => void
}) {
    const {eventId, orderShortId} = useParams();
    const navigate = useNavigate();
    const [message, setMessage] = useState<string | undefined>('');
    const [isProcessing, setIsProcessing] = useState(false);
    
    const {data: order, isFetched: isOrderFetched} = useGetOrderPublic(eventId, orderShortId, ['event']);
    const event = order?.event;
    
    const {
        data: razorpayOrderData,
        isFetched: isRazorpayOrderFetched,
        error: razorpayOrderError
    } = useCreateRazorpayOrder(eventId, orderShortId, isOrderFetched && order?.payment_status === 'AWAITING_PAYMENT');
    
    const verifyPaymentMutation = useVerifyRazorpayPayment();

    // Load Razorpay script
    useEffect(() => {
        const script = document.createElement('script');
        script.src = 'https://checkout.razorpay.com/v1/checkout.js';
        script.async = true;
        document.body.appendChild(script);

        return () => {
            document.body.removeChild(script);
        };
    }, []);

    const handlePaymentSuccess = async (response: RazorpayResponse) => {
        setIsProcessing(true);
        setMessage(t`Verifying payment...`);

        try {
            const verificationResult = await verifyPaymentMutation.mutateAsync({
                eventId,
                orderShortId,
                payload: {
                    razorpay_payment_id: response.razorpay_payment_id,
                    razorpay_order_id: response.razorpay_order_id,
                    razorpay_signature: response.razorpay_signature,
                }
            });

            if (verificationResult.data.success) {
                showSuccess(t`Payment successful! Redirecting to order summary...`);
                navigate(eventCheckoutPath(eventId, orderShortId, 'summary'));
            } else {
                setMessage(verificationResult.data.message || t`Payment verification failed. Please contact support.`);
                setIsProcessing(false);
            }
        } catch (error: any) {
            console.error('Payment verification error:', error);
            setMessage(error.response?.data?.message || t`Payment verification failed. Please try again or contact support.`);
            setIsProcessing(false);
        }
    };

    const handlePaymentError = (error: any) => {
        console.error('Razorpay payment error:', error);
        
        if (error.error?.code === 'PAYMENT_CANCELLED') {
            setMessage(t`Payment was cancelled. You can try again.`);
        } else {
            setMessage(error.error?.description || t`Payment failed. Please try again.`);
        }
        
        showError(t`Payment was not successful. Please try again.`);
    };

    const initializeRazorpayPayment = () => {
        // Validate Razorpay configuration
        const configValidation = validateRazorpayConfig();
        if (!configValidation.isValid) {
            console.error('Razorpay configuration errors:', configValidation.errors);
            setMessage(t`Razorpay is not properly configured. Please contact support.`);
            return;
        }

        if (!window.Razorpay || !razorpayOrderData || !order) {
            setMessage(t`Payment system is not ready. Please try again.`);
            return;
        }

        const razorpayConfig = getRazorpayConfig();
        const options = {
            key: razorpayConfig.keyId,
            amount: razorpayOrderData.amount,
            currency: razorpayOrderData.currency,
            name: event?.title || 'Event Ticket',
            description: `Order #${order.short_id}`,
            order_id: razorpayOrderData.razorpay_order_id,
            handler: handlePaymentSuccess,
            prefill: {
                name: `${order.first_name} ${order.last_name}`,
                email: order.email,
            },
            theme: {
                color: getConfig('VITE_APP_PRIMARY_COLOR', '#3399cc'),
            },
            modal: {
                ondismiss: () => {
                    setMessage(t`Payment was cancelled. You can try again.`);
                }
            }
        };

        const razorpayInstance = new window.Razorpay(options);
        razorpayInstance.on('payment.failed', handlePaymentError);
        razorpayInstance.open();
    };

    const handleSubmit = async () => {
        if (isProcessing) {
            return;
        }

        if (!isRazorpayOrderFetched || !razorpayOrderData) {
            setMessage(t`Payment order is not ready. Please wait and try again.`);
            return;
        }

        initializeRazorpayPayment();
    };

    useEffect(() => {
        if (setSubmitHandler) {
            setSubmitHandler(() => handleSubmit);
        }
    }, [setSubmitHandler, isRazorpayOrderFetched, razorpayOrderData, isProcessing]);

    // Handle URL parameters for payment status
    useEffect(() => {
        const urlParams = new URLSearchParams(window?.location.search);
        if (urlParams.get('payment_failed') === 'true') {
            setMessage(t`Your previous payment attempt was unsuccessful. Please try again.`);
        }
    }, []);

    // Validate Razorpay configuration on mount
    useEffect(() => {
        const configValidation = validateRazorpayConfig();
        if (!configValidation.isValid) {
            console.warn('Razorpay configuration issues:', configValidation.errors);
        }
    }, []);

    if (!isOrderFetched || !order?.payment_status) {
        return (
            <CheckoutContent>
                <Skeleton height={300} mb={20}/>
            </CheckoutContent>
        );
    }

    if (order?.payment_status === 'PAYMENT_RECEIVED') {
        return (
            <HomepageInfoMessage
                message={t`This order has already been paid.`}
                linkText={t`View order details`}
                link={eventCheckoutPath(eventId, orderShortId, 'summary')}
            />
        );
    }

    if (order?.payment_status !== 'AWAITING_PAYMENT' && order?.payment_status !== 'PAYMENT_FAILED') {
        return (
            <HomepageInfoMessage
                message={t`This order page is no longer available.`}
                linkText={t`Return to event page`}
                link={eventHomepagePath(event as Event)}
            />
        );
    }

    if (razorpayOrderError && event) {
        return (
            <CheckoutContent>
                <HomepageInfoMessage
                    /* @ts-ignore */
                    message={razorpayOrderError.response?.data?.message || t`Sorry, something has gone wrong. Please restart the checkout process.`}
                    link={eventHomepagePath(event)}
                    linkText={t`Return to event page`}
                />
            </CheckoutContent>
        );
    }

    return (
        <form id="razorpay-payment-form">
            <>
                <h2>
                    {t`Payment`}
                </h2>
                {(order?.payment_status === 'PAYMENT_FAILED' || window?.location.search.includes('payment_failed')) && (
                    <Alert mb={20} color={'red'}>{t`Your payment was unsuccessful. Please try again.`}</Alert>
                )}

                {message !== '' && <Alert mb={20}>{message}</Alert>}
                
                {isProcessing && <LoadingMask/>}
                
                <div className={classes.razorpayContainer}>
                    <div className={classes.paymentInfo}>
                        <div className={classes.infoItem}>
                            <span className={classes.label}>{t`Event:`}</span>
                            <span className={classes.value}>{event?.title}</span>
                        </div>
                        <div className={classes.infoItem}>
                            <span className={classes.label}>{t`Order ID:`}</span>
                            <span className={classes.value}>#{order.short_id}</span>
                        </div>
                        <div className={classes.infoItem}>
                            <span className={classes.label}>{t`Amount:`}</span>
                            <span className={classes.value}>{formatCurrency(order.total_gross, order.currency)}</span>
                        </div>
                    </div>
                    
                    <p>{t`Click "Place Order" to proceed with Razorpay payment.`}</p>
                    <p>{t`You will be redirected to Razorpay's secure payment gateway.`}</p>
                    
                    {!isRazorpayOrderFetched && (
                        <div className={classes.loadingState}>
                            <Skeleton height={20} mb={10}/>
                            <Skeleton height={20} width="70%"/>
                        </div>
                    )}
                </div>
            </>
        </form>
    );
}