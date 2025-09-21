import {getConfig} from "./config.ts";

export interface RazorpayConfig {
    keyId: string;
    isConfigured: boolean;
}

export const getRazorpayConfig = (): RazorpayConfig => {
    const keyId = getConfig('VITE_RAZORPAY_KEY_ID');
    
    return {
        keyId: keyId || '',
        isConfigured: Boolean(keyId && keyId.trim() !== ''),
    };
};

export const validateRazorpayConfig = (): { isValid: boolean; errors: string[] } => {
    const config = getRazorpayConfig();
    const errors: string[] = [];

    if (!config.keyId) {
        errors.push('VITE_RAZORPAY_KEY_ID is not configured');
    } else if (!config.keyId.startsWith('rzp_')) {
        errors.push('VITE_RAZORPAY_KEY_ID must start with "rzp_"');
    }

    return {
        isValid: errors.length === 0,
        errors,
    };
};

export const isRazorpayAvailable = (): boolean => {
    const config = getRazorpayConfig();
    return config.isConfigured;
};