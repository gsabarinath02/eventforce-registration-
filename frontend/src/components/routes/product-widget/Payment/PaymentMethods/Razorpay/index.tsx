
import {useParams} from "react-router";
import {useGetEventPublic} from "../../../../../../queries/useGetEventPublic.ts";
import {CheckoutContent} from "../../../../../layouts/Checkout/CheckoutContent";
import {HomepageInfoMessage} from "../../../../../common/HomepageInfoMessage";
import {eventHomepagePath} from "../../../../../../utilites/urlHelper.ts";
import {Event} from "../../../../../../types.ts";
import {t} from "@lingui/macro";
import RazorpayCheckoutForm from "../../../../../forms/RazorpayCheckoutForm";

interface RazorpayPaymentMethodProps {
    enabled: boolean;
    setSubmitHandler: (submitHandler: () => () => Promise<void>) => void;
}

export const RazorpayPaymentMethod = ({enabled, setSubmitHandler}: RazorpayPaymentMethodProps) => {
    const {eventId} = useParams();
    const {data: event} = useGetEventPublic(eventId);

    if (!enabled && event) {
        return (
            <CheckoutContent>
                <HomepageInfoMessage
                    message={t`Razorpay payments are not enabled for this event.`}
                    linkText={t`Return to event page`}
                    link={eventHomepagePath(event as Event)}
                />
            </CheckoutContent>
        );
    }

    return <RazorpayCheckoutForm setSubmitHandler={setSubmitHandler} />;
};