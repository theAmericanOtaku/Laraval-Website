# Payments Lab

A small Laravel 13 site for learning how **Stripe Checkout** and **PayPal Orders v2** work, using test/sandbox payments only.

## Run it

```bash
composer install
cp .env.example .env && php artisan key:generate   # skip if .env already exists
touch database/database.sqlite && php artisan migrate
php artisan serve        # http://localhost:8000
```

## 1. Stripe (test mode)

1. Create a free account at https://dashboard.stripe.com and leave **Test mode** on.
2. Go to *Developers → API keys* and copy the keys into `.env`:
   `STRIPE_KEY=pk_test_...`, `STRIPE_SECRET=sk_test_...`
3. (Optional, recommended) Receive webhooks locally with the [Stripe CLI](https://docs.stripe.com/stripe-cli):
   ```bash
   stripe listen --forward-to localhost:8000/webhooks/stripe
   ```
   Copy the `whsec_...` it prints into `STRIPE_WEBHOOK_SECRET`.
4. Pay with card `4242 4242 4242 4242`, any future date, any CVC.
   Other cards to try: `4000 0000 0000 0002` (declined), `4000 0025 0000 3155` (3-D Secure).

## 2. PayPal (sandbox)

1. Log in at https://developer.paypal.com → *Apps & Credentials* → **Sandbox** → create an app.
2. Copy the **Client ID** and **Secret** into `PAYPAL_CLIENT_ID` and `PAYPAL_SECRET`.
3. Under *Testing Tools → Sandbox Accounts* there's a **Personal** account. Use its email and password
   when PayPal asks you to log in at checkout.
4. Check the payments in your sandbox **Business** account at https://www.sandbox.paypal.com.

After editing `.env`, run `php artisan config:clear` if you've cached config.

## How it works

| Step | Stripe (`StripeController`) | PayPal (`PayPalController`) |
|---|---|---|
| 1 | Create a local `Order` (pending) | Create a local `Order` (pending) |
| 2 | Create a Checkout Session with the SDK | Get an OAuth token, then `POST /v2/checkout/orders` |
| 3 | Redirect to `session->url` | Redirect to the `payer-action` link |
| 4 | Back on `success_url`, **re-fetch the session** to confirm it's paid | Back on `return_url`, call `/capture` to take the money |
| 5 | Webhook `checkout.session.completed` confirms the payment (signature-verified) | (Webhooks possible too, but not set up here) |

- Routes: `routes/web.php`
- Products and prices (in cents): `config/shop.php`
- API keys: `config/services.php`, which reads them from `.env`
- Each order page shows the raw API response, so you can see what the provider sent back.

## Tests

```bash
php artisan test
```
The tests fake the PayPal API with `Http::fake()` and sign a Stripe webhook, so they run without any keys.

## Ideas to extend
- Add PayPal webhooks (`PAYMENT.CAPTURE.COMPLETED`) and verify them.
- Issue refunds from the order page (`$stripe->refunds->create`, PayPal `/v2/payments/captures/{id}/refund`).
- Switch to Stripe Elements / PayPal JS Buttons for an on-page checkout instead of a redirect.
- Try Laravel Cashier for Stripe subscriptions.
