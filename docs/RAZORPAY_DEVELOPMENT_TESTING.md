# Razorpay Local Development and Testing Guide

This guide provides comprehensive instructions for setting up Razorpay integration in your local development environment and testing the complete payment flow.

## Prerequisites

Before starting, ensure you have:

- Hi.Events development environment set up and running
- Razorpay account with test mode access
- Basic understanding of payment flows and webhooks

## Local Development Setup

### 1. Environment Configuration

Create or update your local `.env` file with Razorpay test credentials:

```env
# Razorpay Test Configuration
RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxxxx
RAZORPAY_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxxxxxxx

# Frontend Configuration
VITE_RAZORPAY_KEY_ID=rzp_test_xxxxxxxxxx

# Optional: Enable debug logging
LOG_LEVEL=debug
RAZORPAY_DEBUG=true
```

### 2. Obtain Test Credentials

#### Get Test API Keys

1. Log into [Razorpay Dashboard](https://dashboard.razorpay.com/)
2. Ensure you're in **Test Mode** (toggle in top-left corner)
3. Navigate to **Settings** → **API Keys**
4. Click **Generate Test Key**
5. Copy the **Key ID** and **Key Secret**

#### Generate Webhook Secret

1. Go to **Settings** → **Webhooks**
2. Click **Create Webhook**
3. Set URL to your local webhook endpoint (see next section)
4. Generate and copy the webhook secret

### 3. Local Webhook Setup

Since Razorpay needs to send webhooks to your local development server, you'll need to expose it to the internet.

#### Option A: Using ngrok (Recommended)

1. **Install ngrok:**
   ```bash
   # macOS
   brew install ngrok
   
   # Windows
   choco install ngrok
   
   # Or download from https://ngrok.com/
   ```

2. **Start your Hi.Events application:**
   ```bash
   # If using Docker
   docker compose up -d
   
   # If running locally
   php artisan serve --port=8000
   npm run dev
   ```

3. **Expose your local server:**
   ```bash
   ngrok http 8000
   ```

4. **Copy the HTTPS URL:**
   ```
   Forwarding    https://abc123.ngrok.io -> http://localhost:8000
   ```

5. **Configure webhook URL in Razorpay:**
   ```
   https://abc123.ngrok.io/api/public/webhooks/razorpay
   ```

#### Option B: Using LocalTunnel

1. **Install LocalTunnel:**
   ```bash
   npm install -g localtunnel
   ```

2. **Expose your server:**
   ```bash
   lt --port 8000 --subdomain your-unique-name
   ```

3. **Use the provided URL:**
   ```
   https://your-unique-name.loca.lt/api/public/webhooks/razorpay
   ```

#### Option C: Using Serveo (No Installation Required)

```bash
ssh -R 80:localhost:8000 serveo.net
```

### 4. Verify Configuration

Test your configuration with this command:

```bash
php artisan config:show razorpay
```

Expected output:
```
razorpay.key_id ........................... rzp_test_xxxxxxxxxx
razorpay.key_secret ....................... [Hidden]
razorpay.webhook_secret ................... [Hidden]
```

## Testing Scenarios

### 1. Basic Payment Flow Testing

#### Test Case: Successful Payment

1. **Create a test event:**
   ```bash
   php artisan tinker
   ```
   ```php
   $event = \App\Models\Event::factory()->create([
       'payment_provider' => 'RAZORPAY'
   ]);
   ```

2. **Create test tickets:**
   ```php
   $ticket = \App\Models\Ticket::factory()->create([
       'event_id' => $event->id,
       'price' => 500.00, // ₹500
       'quantity_available' => 100
   ]);
   ```

3. **Test checkout flow:**
   - Navigate to your event page
   - Add tickets to cart
   - Proceed to checkout
   - Select Razorpay payment method
   - Use test card details (see Test Cards section)

#### Test Case: Failed Payment

1. Use failure test card: `4000000000000002`
2. Complete the checkout process
3. Verify order status updates to "Payment Failed"
4. Check that retry is possible

#### Test Case: Payment Timeout

1. Start payment process but don't complete it
2. Wait for timeout (usually 15 minutes)
3. Verify order status handling

### 2. Webhook Testing

#### Manual Webhook Testing

Test webhook endpoint directly:

```bash
curl -X POST http://localhost:8000/api/public/webhooks/razorpay \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: $(echo -n '{"test":"webhook"}' | openssl dgst -sha256 -hmac 'your_webhook_secret' -binary | base64)" \
  -d '{"test":"webhook"}'
```

#### Webhook Event Simulation

Create test webhook payloads:

```bash
# Test payment captured webhook
curl -X POST http://localhost:8000/api/public/webhooks/razorpay \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: your_calculated_signature" \
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

### 3. Refund Testing

#### Test Full Refund

1. **Create a completed order:**
   ```php
   $order = \App\Models\Order::factory()->create([
       'status' => 'COMPLETED',
       'payment_provider' => 'RAZORPAY',
       'total_gross' => 50000 // ₹500 in paise
   ]);
   ```

2. **Process refund through admin panel:**
   - Navigate to order management
   - Select the order
   - Click "Refund Order"
   - Verify refund processes successfully

#### Test Partial Refund

1. Process partial refund for 50% of order amount
2. Verify remaining amount is still captured
3. Test multiple partial refunds

### 4. Error Handling Testing

#### Invalid Credentials Test

1. **Temporarily set invalid credentials:**
   ```env
   RAZORPAY_KEY_ID=invalid_key
   ```

2. **Attempt payment creation:**
   - Should show appropriate error message
   - Should not expose sensitive information
   - Should allow retry with correct credentials

#### Network Failure Simulation

1. **Block Razorpay API access:**
   ```bash
   # Add to /etc/hosts (temporary)
   127.0.0.1 api.razorpay.com
   ```

2. **Test error handling:**
   - Verify graceful degradation
   - Check error logging
   - Ensure user-friendly error messages

## Test Cards and Credentials

### Test Card Numbers

| Purpose | Card Number | CVV | Expiry | Expected Result |
|---------|-------------|-----|--------|-----------------|
| Success | `4111 1111 1111 1111` | `123` | Any future date | Payment succeeds |
| Success (Visa) | `4012 8888 8888 1881` | `123` | Any future date | Payment succeeds |
| Success (Mastercard) | `5555 5555 5555 4444` | `123` | Any future date | Payment succeeds |
| Failure | `4000 0000 0000 0002` | `123` | Any future date | Payment fails |
| Insufficient Funds | `4000 0000 0000 9995` | `123` | Any future date | Insufficient funds error |
| Invalid CVV | `4000 0000 0000 0127` | `123` | Any future date | Invalid CVV error |
| Expired Card | `4000 0000 0000 0069` | `123` | Any past date | Expired card error |

### Test UPI IDs

For UPI testing:
- **Success:** `success@razorpay`
- **Failure:** `failure@razorpay`

### Test Bank Account Details

For net banking testing:
- **Bank:** Any test bank from Razorpay's list
- **Credentials:** Use test credentials provided by Razorpay

## Automated Testing

### Unit Tests

Run Razorpay-specific unit tests:

```bash
# Run all Razorpay tests
php artisan test --filter=Razorpay

# Run specific test classes
php artisan test tests/Unit/Services/Razorpay/
php artisan test tests/Feature/Orders/Payment/Razorpay/
```

### Integration Tests

Test complete payment flows:

```bash
# Test order creation
php artisan test tests/Feature/Orders/Payment/Razorpay/CreateRazorpayOrderActionPublicTest.php

# Test payment verification
php artisan test tests/Feature/Orders/Payment/Razorpay/VerifyRazorpayPaymentActionPublicTest.php

# Test webhook processing
php artisan test tests/Feature/Webhooks/RazorpayWebhookTest.php
```

### Frontend Tests

Run frontend component tests:

```bash
cd frontend
npm test -- --testNamePattern="Razorpay"
```

## Debugging and Troubleshooting

### Enable Debug Logging

Add to your `.env`:

```env
LOG_LEVEL=debug
RAZORPAY_DEBUG=true
```

### Monitor Logs

```bash
# Watch Laravel logs
tail -f backend/storage/logs/laravel.log | grep -i razorpay

# Watch Docker logs
docker logs -f hi-events-backend | grep -i razorpay
```

### Common Development Issues

#### Issue: Webhook Not Received

**Symptoms:**
- Payment completes but order status doesn't update
- No webhook entries in logs

**Debug Steps:**
1. **Check ngrok status:**
   ```bash
   curl https://your-ngrok-url.ngrok.io/api/public/webhooks/razorpay
   ```

2. **Verify webhook URL in Razorpay dashboard**

3. **Test webhook manually:**
   ```bash
   curl -X POST https://your-ngrok-url.ngrok.io/api/public/webhooks/razorpay \
     -H "Content-Type: application/json" \
     -d '{"test": "webhook"}'
   ```

#### Issue: Payment Creation Fails

**Symptoms:**
- "Order creation failed" error
- API key errors

**Debug Steps:**
1. **Verify credentials:**
   ```bash
   php artisan tinker
   ```
   ```php
   config('razorpay.key_id');
   // Should return rzp_test_xxxxxxxxxx
   ```

2. **Test API connectivity:**
   ```php
   $client = new \Razorpay\Api\Api(
       config('razorpay.key_id'),
       config('razorpay.key_secret')
   );
   $client->order->create([
       'amount' => 50000,
       'currency' => 'INR'
   ]);
   ```

#### Issue: Frontend Integration Problems

**Symptoms:**
- Razorpay checkout doesn't load
- JavaScript errors in console

**Debug Steps:**
1. **Check frontend environment:**
   ```bash
   echo $VITE_RAZORPAY_KEY_ID
   ```

2. **Verify Razorpay script loading:**
   - Open browser developer tools
   - Check Network tab for `checkout.js` loading
   - Verify no CORS errors

3. **Test Razorpay object:**
   ```javascript
   // In browser console
   console.log(window.Razorpay);
   ```

### Performance Testing

#### Load Testing Payment Creation

```bash
# Install Apache Bench
sudo apt-get install apache2-utils

# Test order creation endpoint
ab -n 100 -c 10 -H "Content-Type: application/json" \
   -p order_payload.json \
   http://localhost:8000/api/public/events/1/order/ABC123/razorpay/order
```

#### Monitor Database Performance

```sql
-- Check slow queries
SELECT * FROM information_schema.processlist 
WHERE command != 'Sleep' AND time > 5;

-- Monitor Razorpay payment queries
EXPLAIN SELECT * FROM razorpay_payments 
WHERE razorpay_order_id = 'order_test123';
```

## Development Best Practices

### 1. Environment Management

- Use separate Razorpay accounts for development, staging, and production
- Never commit real credentials to version control
- Use environment-specific webhook URLs
- Implement proper credential rotation

### 2. Error Handling

- Always handle Razorpay API exceptions gracefully
- Log errors with sufficient context but no sensitive data
- Provide user-friendly error messages
- Implement proper retry mechanisms

### 3. Testing Strategy

- Write tests for all payment scenarios
- Mock external API calls in unit tests
- Use real API calls in integration tests with test credentials
- Implement end-to-end tests for critical payment flows

### 4. Security Considerations

- Validate all webhook signatures
- Implement rate limiting on payment endpoints
- Use HTTPS for all payment-related communications
- Regularly audit payment processing logs

## Useful Development Commands

### Artisan Commands

```bash
# Clear configuration cache
php artisan config:clear

# Validate Razorpay configuration
php artisan config:show razorpay

# Run database migrations
php artisan migrate

# Seed test data
php artisan db:seed --class=RazorpayTestDataSeeder
```

### Database Queries

```sql
-- Check recent Razorpay payments
SELECT * FROM razorpay_payments 
ORDER BY created_at DESC LIMIT 10;

-- Find orders by payment status
SELECT o.*, rp.razorpay_payment_id 
FROM orders o 
LEFT JOIN razorpay_payments rp ON o.id = rp.order_id 
WHERE o.payment_provider = 'RAZORPAY' 
AND o.status = 'AWAITING_PAYMENT';

-- Check webhook processing logs
SELECT * FROM webhook_logs 
WHERE provider = 'razorpay' 
ORDER BY created_at DESC;
```

### Frontend Development

```bash
# Start frontend development server
cd frontend
npm run dev

# Run frontend tests
npm test

# Build for production
npm run build

# Type checking
npm run type-check
```

## Resources and References

### Razorpay Documentation
- [Test Mode Guide](https://razorpay.com/docs/payments/test-mode/)
- [Test Cards and Credentials](https://razorpay.com/docs/payments/payments/test-card-upi-details/)
- [API Reference](https://razorpay.com/docs/api/)
- [Webhook Documentation](https://razorpay.com/docs/webhooks/)

### Development Tools
- [ngrok](https://ngrok.com/) - Secure tunnels to localhost
- [Postman](https://www.postman.com/) - API testing
- [Webhook.site](https://webhook.site/) - Webhook testing
- [JSON Formatter](https://jsonformatter.org/) - JSON validation

### Hi.Events Resources
- [Contributing Guidelines](../CONTRIBUTING.md)
- [Development Setup](../INSTALL_WITHOUT_DOCKER.md)
- [API Documentation](https://hi.events/docs/api)

---

**Note:** Always test thoroughly in development before deploying to production. Keep your test credentials secure and separate from production credentials.