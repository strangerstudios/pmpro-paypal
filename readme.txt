=== Paid Memberships Pro - PayPal Gateway ===
Contributors: strangerstudios, paidmembershipspro
Tags: paypal, paid memberships pro, pmpro, payments, subscriptions
Requires at least: 5.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern PayPal integration for Paid Memberships Pro using PayPal's REST APIs with offsite redirect checkout.

== Description ==

This plugin adds PayPal as a payment gateway for Paid Memberships Pro using PayPal's modern REST APIs:

* **One-time payments** via Orders V2 API
* **Recurring subscriptions** via Subscriptions API v1 (gateway-managed)
* **Offsite redirect checkout** — form submits, PMPro creates the user and order, then redirects to PayPal for approval
* **Webhooks** for automated checkout completion, renewal payments, cancellations, refunds, and other events
* **Token order fallback** — if the user returns from PayPal before the webhook fires, the plugin polls PayPal directly to complete checkout

The old PayPal Express and Website Payments Pro gateways in PMPro core use PayPal's deprecated NVP/SOAP API. This plugin replaces them with PayPal's current REST API platform.

= How Checkout Works =

1. The member fills out the PMPro checkout form and clicks "Check Out with PayPal".
2. PMPro creates the user account and order (all checkout hooks and filters run normally).
3. The plugin saves the order as a "token" order, creates a PayPal order or subscription via API, and redirects to PayPal.
4. The member approves payment at PayPal and is redirected back to the confirmation page.
5. A PayPal webhook (or the token order fallback) captures the payment and completes checkout via `pmpro_complete_async_checkout()`.

This offsite redirect approach ensures that discount codes, add-on pricing, tax calculations, and custom profile start dates are all applied before PayPal is contacted.

= Requirements =

* Paid Memberships Pro 3.7.1 or later
* PayPal Business account
* HTTPS on your site (required by PayPal for webhooks)

== Installation ==

= 1. Get Your PayPal API Credentials =

You need a **Client ID** and **Client Secret** from the PayPal Developer Dashboard.

**For Sandbox (testing):**

1. Go to [developer.paypal.com](https://developer.paypal.com/) and log in with your PayPal account.
2. Navigate to **Apps & Credentials** (under the Dashboard menu).
3. Make sure the toggle at the top is set to **Sandbox**.
4. Click **Create App** (or use the "Default Application" if one exists).
5. Give it a name like "PMPro" and click **Create App**.
6. On the app detail page, copy the **Client ID** (shown in full) and the **Secret** (click "Show" to reveal it).

**For Live (production):**

1. Same steps as above, but toggle to **Live** at the top of the Apps & Credentials page.
2. You may need to complete PayPal's account verification before live credentials are available.

= 2. Configure the Plugin =

1. Install and activate this plugin.
2. Go to **PMPro > Settings > Payment Gateway**.
3. Select **PayPal** from the gateway dropdown.
4. Set the **Payment Gateway Environment** to Sandbox or Live.
5. Enter your **Client ID** and **Client Secret** for both the Live and Sandbox environments.
6. Click **Save Settings**.

= 3. Webhook (Automatic) =

The webhook is registered automatically via the PayPal API when you save valid credentials. You do not need to manually configure a webhook URL in the PayPal dashboard.

After saving, the settings page will display the Webhook ID confirming it was registered. The webhook URL is your site's REST API endpoint at:

`https://yoursite.com/wp-json/pmpro-paypal/v1/webhook`

PayPal will send notifications to this URL for subscription payments, cancellations, refunds, and other events.

**Troubleshooting webhooks:**

* Your site must be served over **HTTPS** — PayPal will not deliver webhooks to HTTP URLs.
* If you're testing on localhost, PayPal cannot reach your webhook URL. Use a tunnel service like ngrok to expose your local site, or test webhook handling separately.
* If the Webhook ID is not showing after saving, check that your Client ID and Secret are correct and that your site is accessible over HTTPS.
* You can verify the webhook exists by going to your app in the [PayPal Developer Dashboard](https://developer.paypal.com/) > **Apps & Credentials** > your app > **Webhooks** tab.

== Frequently Asked Questions ==

= What happens to existing PayPal Express subscribers? =

They continue working. The old PayPal Express gateway (slug: `paypalexpress`) runs side-by-side with this new gateway. Existing subscribers keep their current gateway and IPN handler. Only new sign-ups use the new `paypal` gateway.

= Can I test in sandbox before going live? =

Yes. Set the Payment Gateway Environment to "Sandbox" in PMPro settings and use your sandbox Client ID and Secret. PayPal provides sandbox buyer accounts at developer.paypal.com > Sandbox > Accounts that you can use to complete test checkouts.

= Do I need to set up IPN? =

No. This plugin uses PayPal Webhooks (the modern replacement for IPN), and they are registered automatically. The old IPN handler in PMPro core continues to work for legacy PayPal Express and PayPal Standard subscriptions.

= What PayPal APIs does this plugin use? =

* **Orders V2** (`/v2/checkout/orders`) for one-time payments
* **Subscriptions API v1** (`/v1/billing/subscriptions`) for recurring
* **Catalog Products** (`/v1/catalogs/products`) and **Billing Plans** (`/v1/billing/plans`) for subscription plan management
* **Webhooks** (`/v1/notifications/webhooks`) for event notifications
* **OAuth2** (`/v1/oauth2/token`) for authentication

== Changelog ==

= TBD =
* BUG FIX: Fixed the subscription `start_time` sent to PayPal being offset by the site's timezone (e.g. 9 hours early on Asia/Tokyo sites). The profile start date is now formatted with a literal UTC designator like the legacy gateways. #14 (@flintfromthebasement)

= 1.1.1 - 2026-05-07 =
* BUG FIX: Fixed a `DECIMAL_PRECISION` error from the PayPal billing API when checking out a recurring level whose initial payment is 0 on a zero-decimal currency (JPY, KRW, VND, UAH, ALL). The setup fee is now formatted via `pmpro_round_price_as_string()` so it matches the site currency. #12 (@dparker1005)

= 1.1 - 2026-04-27 =
* FEATURE: Added support for offering PayPal as a secondary payment gateway alongside another primary gateway at checkout. #10 (@dparker1005)
* ENHANCEMENT: Added a setting to manually edit the PayPal Webhook ID for both the Sandbox and Live environments. #9 (@andrewlimaza)
* ENHANCEMENT: The PayPal webhook REST endpoint now accepts GET requests so site owners can confirm the URL is reachable from a browser. #11 (@dparker1005)
* BUG FIX: Fixed PayPal webhook signature verification by sending the original raw webhook body to PayPal instead of a re-encoded version. #8 (@ideadude)

= 1.0 - 2026-03-30 =
* Initial release.
