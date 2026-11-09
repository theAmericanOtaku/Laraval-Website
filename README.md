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

1. Create a free account at https://dashboard.stripe.com and stay in **Test mode** (or create a
   [sandbox](https://docs.stripe.com/sandboxes), which keeps this project's test data separate).
2. Go to *Developers → API keys* and copy the **publishable key** into `STRIPE_KEY=pk_test_...`.
3. On the same page, click **Create restricted key**. Give it a name like "Payments Lab", set
   **Checkout Sessions** to **Write** and leave everything else as **None**. Put it in
   `STRIPE_SECRET=rk_test_...`.
   A restricted key can only do what you allowed, so a leaked key does far less damage than the full
   secret key (`sk_test_...`). The secret key still works if you want to skip this step while learning.
4. **Set up the webhook (required).** This app only marks a Stripe order as paid when Stripe's webhook
   says so, because customers can close the tab before they get back to your site. Install the
   [Stripe CLI](https://docs.stripe.com/stripe-cli), then in a second terminal run:
   ```bash
   stripe login
   stripe listen --forward-to localhost:8000/webhooks/stripe
   ```
   Copy the `whsec_...` it prints into `STRIPE_WEBHOOK_SECRET`, and keep it running while you test.
   Without it, Stripe orders stay **pending** forever. On a live site you'd add the endpoint under
   *Developers → Webhooks* in the Dashboard instead.
5. Pay with card `4242 4242 4242 4242`, any future date, any CVC.
   Other cards to try: `4000 0000 0000 0002` (declined), `4000 0025 0000 3155` (3-D Secure).

Never commit your keys. They belong in `.env`, which git ignores. If one leaks, click **Roll key**
on the API keys page.

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
| 4 | Back on `success_url`, show what Stripe reports (doesn't change the order) | Back on `return_url`, call `/capture` to take the money |
| 5 | **Webhook** (signature-verified) marks the order paid or failed: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` | Not set up yet |

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
