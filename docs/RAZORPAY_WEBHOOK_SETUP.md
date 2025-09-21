# Razorpay Webhook Setup Guide

This guide provides detailed instructions for setting up and configuring Razorpay webhooks with Hi.Events to ensure reliable payment processing and order status synchronization.

## Overview

Webhooks are HTTP callbacks that Razorpay sends to your application when specific events occur. They ensure that your Hi.Events installation stays synchronized with payment statuses even if customers don't return to your website after completing payment.

## Required Webhook Events

Hi.Events requires the following webhook events to be configured in your Razorpay dashboard:

| Event | Description | Purpose |
|-------|-------------|---------|
| `payment.authorized` | Payment has been authorized but not captured | Updates order status for manual capture workflows |
| `payment.captured` | Payment has been successfully captured | Completes the order and triggers confirmation emails |
| `payment.failed` | Payment attempt has failed | Updates order status to failed and allows retry |
| `refund.processed` | Refund has been processed successfully | Updates order refund status and sends notifications |

## Step-by-Step Webhook Configuration

### 1. Access Razorpay Dashboard

1. Log into your [Razorpay Dashboard](https://dashboard.razorpay.com/)
2. Navigate to **Settings** → **Webhooks**
3. Click **Create Webhook**

### 2. Configure Webhook URL

Set your webhook URL based on your Hi.Events installation:

**Format:** `https://yourdomain.com/api/public/webhooks/razorpay`

**Examples:**
- Production: `https://events.yourcompany.com/api/public/webhooks/razorpay`
- Staging: `https://staging-events.yourcompany.com/api/public/webhooks/razorpay`
- Local Development: `https://your-ngrok-url.ngrok.io/api/public/webhooks/razorpay`

### 3. Select Events

In the webhook configuration, select the following events:

```
✅ payment.authorized
✅ payment.captured  
✅ payment.failed
✅ refund.processed
```

**Important:** Do not select unnecessary events as they will create additional processing overhead.

### 4. Generate Webhook Secret

1. Click **Generate Secret** in the webhook configuration
2. Copy the generated secret (it will look like: `whsec_xxxxxxxxxxxxxxxxxx`)
3. Add this secret to your Hi.Events environment configuration:

```env
RAZORPAY_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxx
```

### 5. Save and Activate

1. Click **Create Webhook**
2. Ensure the webhook status shows as **Active**
3. Note the webhook ID for future reference

## Webhook URL Requirements

### Security Requirements

- **HTTPS Only:** Razorpay only sends webhooks to HTTPS URLs
- **Valid SSL Certificate:** Your domain must have a valid SSL certificate
- **Accessible from Internet:** The webhook URL must be publicly accessible

### Local Development Setup

For local development, you'll need to expose your local server to the internet:

#### Using ngrok (Recommended)

1. Install [ngrok](https://ngrok.com/)
2. Start your Hi.Events application locally
3. In a new terminal, run:
   ```bash
   ngrok http 8000
   ```
4. Use the provided HTTPS URL for your webhook configuration

#### Using LocalTunnel

1. Install localtunnel:
   ```bash
   npm install -g localtunnel
   ```
2. Expose your local server:
   ```bash
   lt --port 8000 --subdomain your-unique-name
   ```

## Testing Webhook Configuration

### 1. Webhook Delivery Test

After configuring your webhook:

1. Go to your Razorpay dashboard → **Settings** → **Webhooks**
2. Click on your webhook
3. Use the **Test Webhook** feature to send a sample payload
4. Verify that your Hi.Events application receives and processes the webhook

### 2. End-to-End Payment Test

1. Create a test event in Hi.Events with Razorpay enabled
2. Make a test payment using Razorpay test credentials:
   - **Card Number:** `4111 1111 1111 1111`
   - **CVV:** `123`
   - **Expiry:** Any future date
3. Complete the payment
4. Verify that the order status updates correctly in Hi.Events

### 3. Webhook Logs Verification

Check your Hi.Events application logs for webhook processing:

```bash
# Laravel logs location
tail -f storage/logs/laravel.log | grep -i razorpay

# Docker logs
docker logs hi-events-backend | grep -i razorpay
```

Look for log entries like:
```
[INFO] Razorpay webhook received: payment.captured
[INFO] Order status updated successfully for order: ORD123
```

## Webhook Signature Verification

Hi.Events automatically verifies webhook signatures to ensure authenticity. The verification process:

1. **Signature Header:** Razorpay sends a signature in the `X-Razorpay-Signature` header
2. **HMAC Verification:** Hi.Events uses HMAC SHA256 to verify the signature
3. **Secret Validation:** The webhook secret is used to validate the signature

### Signature Verification Process

```php
// This is handled automatically by Hi.Events
$expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
$receivedSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];

if (!hash_equals($expectedSignature, $receivedSignature)) {
    // Webhook rejected - invalid signature
}
```

## Troubleshooting Webhook Issues

### Common Issues and Solutions

#### 1. Webhook Not Receiving Events

**Symptoms:**
- Orders stuck in "Awaiting Payment" status
- No webhook logs in application

**Solutions:**
- Verify webhook URL is correct and accessible
- Check that your server is running and responding to HTTP requests
- Ensure firewall allows incoming connections on the webhook port
- Test webhook URL manually with curl:
  ```bash
  curl -X POST https://yourdomain.com/api/public/webhooks/razorpay \
    -H "Content-Type: application/json" \
    -d '{"test": "webhook"}'
  ```

#### 2. Signature Verification Failures

**Symptoms:**
- Webhook events received but not processed
- "Invalid webhook signature" errors in logs

**Solutions:**
- Verify `RAZORPAY_WEBHOOK_SECRET` matches the secret in Razorpay dashboard
- Check for extra spaces or characters in environment variable
- Ensure webhook secret is properly quoted in environment file
- Restart your application after updating environment variables

#### 3. Duplicate Event Processing

**Symptoms:**
- Multiple order status updates for the same payment
- Duplicate confirmation emails

**Solutions:**
- Hi.Events handles idempotency automatically using Razorpay event IDs
- Check application logs for duplicate event processing warnings
- Verify webhook configuration doesn't have duplicate URLs

#### 4. SSL Certificate Issues

**Symptoms:**
- Webhooks not delivered
- SSL verification errors in Razorpay logs

**Solutions:**
- Ensure your domain has a valid SSL certificate
- Test SSL configuration: https://www.ssllabs.com/ssltest/
- For development, use ngrok or similar service with valid SSL

### Webhook Debugging

#### Enable Debug Logging

Add to your `.env` file:
```env
LOG_LEVEL=debug
RAZORPAY_DEBUG=true
```

#### Monitor Webhook Delivery

1. **Razorpay Dashboard:**
   - Go to **Settings** → **Webhooks**
   - Click on your webhook
   - Check the **Delivery Attempts** tab for failed deliveries

2. **Application Logs:**
   ```bash
   # Monitor real-time webhook processing
   tail -f storage/logs/laravel.log | grep -E "(webhook|razorpay)"
   ```

#### Test Webhook Manually

Create a test webhook payload:

```bash
curl -X POST https://yourdomain.com/api/public/webhooks/razorpay \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: your-test-signature" \
  -d '{
    "event": "payment.captured",
    "payload": {
      "payment": {
        "entity": {
          "id": "pay_test123",
          "order_id": "order_test123",
          "status": "captured",
          "amount": 50000
        }
      }
    },
    "created_at": 1640995200
  }'
```

## Webhook Security Best Practices

### 1. Signature Verification
- Always verify webhook signatures before processing
- Never process webhooks with invalid signatures
- Use constant-time comparison for signature validation

### 2. Idempotency
- Handle duplicate webhooks gracefully
- Use Razorpay event IDs to prevent duplicate processing
- Implement proper database constraints to prevent race conditions

### 3. Error Handling
- Return appropriate HTTP status codes (200 for success, 4xx/5xx for errors)
- Log webhook processing errors for debugging
- Implement retry logic for transient failures

### 4. Rate Limiting
- Implement rate limiting on webhook endpoints
- Monitor for unusual webhook traffic patterns
- Set up alerts for webhook processing failures

## Webhook Payload Examples

### Payment Captured Event

```json
{
  "event": "payment.captured",
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_FHf9a7AO0iXM9I",
        "amount": 50000,
        "currency": "INR",
        "status": "captured",
        "order_id": "order_FHf9a7AO0iXM9I",
        "method": "card",
        "captured": true,
        "created_at": 1640995200
      }
    }
  },
  "created_at": 1640995200
}
```

### Payment Failed Event

```json
{
  "event": "payment.failed",
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_FHf9a7AO0iXM9I",
        "amount": 50000,
        "currency": "INR",
        "status": "failed",
        "order_id": "order_FHf9a7AO0iXM9I",
        "method": "card",
        "error_code": "BAD_REQUEST_ERROR",
        "error_description": "Payment failed due to insufficient funds",
        "created_at": 1640995200
      }
    }
  },
  "created_at": 1640995200
}
```

### Refund Processed Event

```json
{
  "event": "refund.processed",
  "payload": {
    "refund": {
      "entity": {
        "id": "rfnd_FHf9a7AO0iXM9I",
        "amount": 25000,
        "currency": "INR",
        "payment_id": "pay_FHf9a7AO0iXM9I",
        "status": "processed",
        "created_at": 1640995200
      }
    }
  },
  "created_at": 1640995200
}
```

## Support and Resources

### Razorpay Documentation
- [Webhook Documentation](https://razorpay.com/docs/webhooks/)
- [Event Types](https://razorpay.com/docs/webhooks/events/)
- [Signature Verification](https://razorpay.com/docs/webhooks/validate-test/)

### Hi.Events Support
- Check application logs for detailed error messages
- Review webhook configuration in Razorpay dashboard
- Contact support with specific error messages and webhook IDs

### Testing Resources
- [Razorpay Test Cards](https://razorpay.com/docs/payments/payments/test-card-upi-details/)
- [Webhook Testing Tools](https://webhook.site/)
- [ngrok for Local Development](https://ngrok.com/)

---

**Note:** Always test webhook configuration in a staging environment before deploying to production. Ensure proper monitoring and alerting are in place for webhook processing failures.