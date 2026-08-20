# Coinbase Checkout Gateway for Magento / Adobe Commerce

Accept USDC payments on the Base network in your Adobe Commerce (Magento 2) store via the Coinbase Business Checkouts API.

Customers select "Pay with Coinbase (USDC)" at checkout, get redirected to a Coinbase-hosted payment page to pay with USDC, and are returned to your store after payment. Webhook notifications confirm payment status asynchronously.

## Features

- **USDC on Base network** — 1:1 USD to USDC, no currency conversion
- **Redirect-based flow** — customers pay on a secure Coinbase-hosted page
- **Webhook-driven confirmation** — payment status updates via signed webhooks
- **Sandbox support** — test the full flow with Base Sepolia testnet USDC
- **Admin payment info** — view checkout ID, status, transaction hash (linked to BaseScan), and settlement breakdown
- **Automatic cleanup** — cron job cancels stale pending orders after expiration
- **Tamper-protected redirects** — HMAC-signed return/cancel URLs
- **CSP whitelisted** — Coinbase domains pre-configured for Content Security Policy

## Requirements

- Adobe Commerce / Magento 2.4.7+
- PHP 8.2+
- Composer
- A [Coinbase Developer Platform (CDP)](https://portal.cdp.coinbase.com) account with an API key

## Installation

### Step 1: Install the module files

Copy the module into your Adobe Commerce installation:

```bash
cp -r app/code/Coinbase/ <magento-root>/app/code/
```

### Step 2: Install the JWT dependency

```bash
cd <magento-root>
composer require firebase/php-jwt:^7.0
```

### Step 3: Enable the module

```bash
bin/magento module:enable Coinbase_CheckoutGateway
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Verify the module is enabled:

```bash
bin/magento module:status Coinbase_CheckoutGateway
```

## Configuration

### Step 1: Create a CDP API key

1. Go to the [Coinbase Developer Platform](https://portal.cdp.coinbase.com) and navigate to **API Keys → Secret API Keys**.
2. Click **Create API key**.
3. Set the signature algorithm to **ECDSA**.
4. Under API restrictions, enable the **View** scope (required for Checkouts API).
5. Save your **API key name** (e.g., `organizations/{org_id}/apiKeys/{key_id}`) and **EC private key** (PEM format).

### Step 2: Create a webhook subscription

Register a webhook to receive payment status updates. Use [cdpcurl](https://github.com/coinbase/cdpcurl) or any HTTP client:

```bash
cdpcurl -X POST \
  -i "YOUR_API_KEY_ID" \
  -s "YOUR_API_KEY_SECRET" \
  "https://api.cdp.coinbase.com/platform/v2/data/webhooks/subscriptions" \
  -d '{
    "description": "Adobe Commerce checkout webhook",
    "eventTypes": [
      "checkout.payment.success",
      "checkout.payment.failed",
      "checkout.payment.expired"
    ],
    "target": {
      "url": "https://your-store.com/coinbase/payment/webhook",
      "method": "POST"
    },
    "labels": {},
    "isEnabled": true
  }'
```

Save the `secret` value from the response — you'll need it in the next step.

### Step 3: Configure the payment method in Admin

1. Navigate to **Stores → Configuration → Sales → Payment Methods**.
2. Expand the **Coinbase Business** section.
3. Configure the following fields:

| Field | Description |
|-------|-------------|
| **Enabled** | Set to **Yes** to activate the payment method |
| **Title** | Display name at checkout (default: "Pay with Coinbase (USDC)") |
| **Environment** | Select **Sandbox** for testing or **Production** for live payments |
| **API Key Name** | Your CDP API key name (e.g., `organizations/{org_id}/apiKeys/{key_id}`) |
| **API Private Key (PEM)** | Your EC private key in PEM format (stored encrypted) |
| **Webhook Secret** | **Required.** The `secret` from your webhook subscription response (stored encrypted). Payments cannot be placed until this is set. |
| **Checkout Expiration (Hours)** | Hours before a checkout expires (default: 24) |
| **Debug Mode** | Enable to log API requests/responses (disable in production) |
| **Payment from Applicable Countries** | Restrict by country if needed |
| **Sort Order** | Controls position in the payment methods list |

4. Click **Save Config** and flush the cache:

```bash
bin/magento cache:flush
```

## Payment Flow

1. Customer adds items to cart and proceeds to checkout.
2. Customer selects **"Pay with Coinbase (USDC)"** and clicks **Place Order**.
3. Adobe Commerce creates the order in `pending_payment` state and calls the Coinbase API to create a checkout.
4. Customer is redirected to the Coinbase payment page to pay with USDC from their wallet.
5. After payment:
   - **Success** — customer is redirected back to the order success page.
   - **Cancel/Fail** — customer is redirected to the cart with their items restored.
6. A webhook notification confirms the final payment status:
   - `checkout.payment.success` — order moves to `processing`, invoice is created, confirmation email is sent.
   - `checkout.payment.failed` — order is canceled.
   - `checkout.payment.expired` — order is canceled.

## Sandbox Testing

1. Set the **Environment** to **Sandbox** in the admin configuration.
2. Use sandbox API keys from the CDP portal.
3. Set your webhook URL to point to your development/staging environment (use a tool like [ngrok](https://ngrok.com) for local testing).
4. Fund a test wallet with Base Sepolia USDC via the [CDP Faucet](https://portal.cdp.coinbase.com/products/faucet).
5. Complete a test checkout and pay with the testnet USDC.

## Refunds

The Coinbase Checkouts API does not support refunds. Refunds must be processed outside of this plugin (e.g., via a direct USDC transfer to the customer). The admin payment info block displays a note about this.

## Cron Job

The module registers a cron job (`coinbase_cleanup_expired_orders`) that runs every 15 minutes. It:

1. Finds orders in `pending_payment` state older than the configured expiration window.
2. Checks the checkout status via the Coinbase API (to avoid canceling orders that were actually paid).
3. Cancels orders whose checkouts have expired.

Ensure your Magento cron is running:

```bash
crontab -e
# Add:
* * * * * cd <magento-root> && php bin/magento cron:run >> var/log/cron.log 2>&1
```

## Troubleshooting

### Payment method not showing at checkout
- Verify the module is enabled: `bin/magento module:status Coinbase_CheckoutGateway`
- Confirm **Enabled** is set to **Yes** in admin configuration.
- Flush all caches: `bin/magento cache:flush`
- Recompile DI: `bin/magento setup:di:compile`
- Redeploy static content: `bin/magento setup:static-content:deploy -f`

### "Unable to initialize Coinbase payment"
- Confirm the **Webhook Secret** is set for the selected environment (Production or Sandbox).
- Check `var/log/system.log` for `Coinbase redirect HMAC: Webhook secret is not configured`.

### "Unable to communicate with Coinbase payment service"
- Check your API Key Name and Private Key are correct.
- Verify the private key is in PEM format (starts with `-----BEGIN EC PRIVATE KEY-----`).
- Ensure the environment setting matches the API keys (sandbox keys for sandbox, production keys for production).
- Enable Debug Mode and check `var/log/system.log` for detailed request/response logs.

### Webhooks not arriving
- Verify your webhook URL is publicly accessible over HTTPS.
- Check the webhook subscription is active via the CDP API.
- Ensure the webhook secret matches the `secret` from the subscription response.
- Check `var/log/system.log` for webhook signature validation errors.

### Orders stuck in pending_payment
- Verify webhooks are being received and processed.
- Check the cron job is running: `bin/magento cron:run --group=default`
- Review `var/log/system.log` for any webhook processing errors.

## Module Structure

```
app/code/Coinbase/CheckoutGateway/
├── registration.php                        # Module registration
├── composer.json                           # Dependencies
├── etc/
│   ├── module.xml                          # Module declaration
│   ├── config.xml                          # Default config values
│   ├── di.xml                              # Gateway framework wiring
│   ├── csp_whitelist.xml                   # CSP whitelist for Coinbase domains
│   ├── crontab.xml                         # Expired order cleanup cron
│   ├── frontend/
│   │   ├── di.xml                          # ConfigProvider registration
│   │   └── routes.xml                      # Frontend route: /coinbase/*
│   └── adminhtml/
│       └── system.xml                      # Admin config UI
├── Gateway/
│   ├── Config.php                          # Typed config accessors
│   ├── Http/
│   │   ├── TransferFactory.php             # JWT auth + HTTP request builder
│   │   └── Client/
│   │       └── CheckoutClient.php          # HTTP client for Coinbase API
│   ├── Request/
│   │   ├── CheckoutBuilder.php             # amount/currency/network/description
│   │   └── CheckoutDataBuilder.php         # redirect URLs, metadata, expiry
│   ├── Response/
│   │   └── CheckoutHandler.php             # Stores API response on payment
│   └── Validator/
│       └── ResponseValidator.php           # Validates API response
├── Service/
│   ├── JwtGenerator.php                    # ECDSA (ES256) JWT generation
│   ├── CheckoutService.php                 # Get status / deactivate checkouts
│   ├── RedirectHash.php                    # HMAC for return/cancel URL protection
│   └── WebhookSignatureValidator.php       # HMAC-SHA256 webhook verification
├── Model/
│   ├── Adminhtml/Source/
│   │   └── Environment.php                 # Sandbox/Production dropdown
│   └── Ui/
│       └── ConfigProvider.php              # Checkout config provider
├── Controller/Payment/
│   ├── Create.php                          # Returns redirect URL as JSON
│   ├── ReturnAction.php                    # Handles success redirect
│   ├── Cancel.php                          # Handles cancel, restores cart
│   └── Webhook.php                         # Processes webhook notifications
├── Block/
│   └── Info.php                            # Admin payment info display
├── Cron/
│   └── CleanupExpiredOrders.php            # Cancels stale pending orders
└── view/frontend/
    ├── requirejs-config.js
    ├── layout/
    │   └── checkout_index_index.xml        # Payment renderer registration
    └── web/
        ├── js/view/payment/
        │   ├── method-renderer.js          # Renderer registration
        │   └── coinbase-checkout.js        # Payment UI + redirect logic
        ├── template/payment/
        │   └── coinbase-checkout.html      # Checkout template
        └── images/
            └── coinbase-logo.svg           # Coinbase logo
```

## License

MIT
