# PRD.md

# Whop Payment Gateway for WooCommerce

## 1. Project Overview

Build a production-ready custom WordPress/WooCommerce payment gateway plugin that integrates Whop as a payment processor.

The plugin must allow WooCommerce to remain the primary ecommerce and subscription-management system while Whop handles payment processing.

### Target architecture

```text
Customer
   |
   v
WooCommerce Checkout
   |
   v
Whop Payment Checkout
   |
   v
Whop Payment Processing
   |
   v
Whop Webhooks
   |
   v
WooCommerce Order / Subscription
```

WooCommerce should remain the source of truth for:

* Orders
* Customers
* Products
* Subscriptions
* Subscription statuses
* Renewal schedules
* Payment retry schedules
* Customer payment-token references
* Admin management

Whop should handle:

* Payment collection
* Secure payment-method storage
* Off-session payment attempts
* Payment processing
* Payment result events
* Payment-related webhooks

Do not store raw card information anywhere in WordPress.

---

# 2. Official Documentation

The implementation must follow current official documentation rather than relying on assumptions.

### WooCommerce

https://developer.woocommerce.com/docs/

Important documentation:

* Payment Gateway API
* Payment Token API
* WooCommerce REST/API documentation
* WooCommerce Subscriptions developer documentation

### Whop

https://docs.whop.com/developer/api/getting-started

Important Whop functionality:

* API authentication
* Checkout configurations
* Embedded checkout
* Payment methods
* Saved payment methods
* Off-session payments
* Setup intents
* Webhooks
* Payments
* Memberships
* Refunds

### Existing third-party references

These sites may be inspected for UX/feature inspiration only:

https://whopwoocommerce.com/

https://whop-woocommerce.com/

Do NOT copy proprietary code.

Do NOT depend on these plugins.

The goal is to build our own implementation using official WooCommerce and Whop APIs.

---

# 3. Primary Goals

The plugin must:

1. Add Whop as a WooCommerce payment method.
2. Allow customers to pay through Whop during WooCommerce checkout.
3. Support WooCommerce orders.
4. Support WooCommerce Subscriptions.
5. Save a Whop payment-method reference for future payments.
6. Allow WooCommerce to trigger recurring payments through Whop.
7. Synchronize Whop payment results back to WooCommerce.
8. Support failed payment handling.
9. Support WooCommerce-controlled retry schedules.
10. Support subscription cancellation synchronization.
11. Store Whop identifiers against WooCommerce orders/subscriptions.
12. Provide an admin settings page.
13. Provide detailed logging for debugging.
14. Prevent duplicate webhook processing.
15. Follow WordPress/WooCommerce security conventions.

---

# 4. Non-Goals

The plugin must NOT:

* Store raw card numbers.
* Store CVV/CVC.
* Store sensitive payment credentials.
* Reimplement Whop's card-processing infrastructure.
* Build a separate customer database.
* Build a separate subscription engine.
* Replace WooCommerce Subscriptions.
* Modify WordPress core.
* Modify WooCommerce core.
* Require users to manually create a Whop checkout plan for every WooCommerce order if dynamic checkout configuration is possible.

---

# 5. Technology Stack

## WordPress

PHP 8.1+ where supported by the client's hosting.

## WooCommerce

Latest stable version compatible with the target WordPress installation.

## WooCommerce Subscriptions

Required for recurring subscriptions.

## Whop

Official Whop REST API and official JavaScript/checkout components where appropriate.

## Database

Use WordPress/WooCommerce database APIs.

Do not introduce a separate database unless there is a compelling technical reason.

---

# 6. Plugin Structure

Use a modular plugin structure.

```text
whop-woocommerce/
│
├── whop-woocommerce.php
│
├── readme.txt
│
├── uninstall.php
│
├── includes/
│   ├── class-wc-whop-gateway.php
│   ├── class-whop-api.php
│   ├── class-whop-webhooks.php
│   ├── class-whop-checkout.php
│   ├── class-whop-subscriptions.php
│   ├── class-whop-payment-tokens.php
│   ├── class-whop-admin.php
│   ├── class-whop-logger.php
│   └── class-whop-order-meta.php
│
├── admin/
│   ├── class-whop-admin-settings.php
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
│
├── public/
│   ├── css/
│   │   └── checkout.css
│   └── js/
│       └── checkout.js
│
└── tests/
    ├── unit/
    └── integration/
```

Do not put the implementation inside `functions.php`.

WooCommerce's own developer documentation recommends implementing gateways as plugins.

---

# 7. Plugin Bootstrap

The main plugin file must:

1. Verify WordPress is loaded.
2. Verify WooCommerce exists.
3. Load dependencies.
4. Register the payment gateway.
5. Register webhook endpoints.
6. Register admin settings.
7. Register subscription integration.
8. Register activation/deactivation hooks.

Basic architecture:

```php
add_action('plugins_loaded', 'whop_woocommerce_init');

function whop_woocommerce_init() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    // Load classes.
}
```

The gateway should extend:

```php
WC_Payment_Gateway
```

and register itself through:

```php
woocommerce_payment_gateways
```

This follows the WooCommerce Payment Gateway API.

---

# 8. WooCommerce Gateway

Create:

```text
WC_Gateway_Whop
```

extending:

```php
WC_Payment_Gateway
```

Gateway ID:

```text
whop
```

Gateway title:

```text
Whop
```

Gateway description:

```text
Pay securely using Whop.
```

The gateway must appear under:

```text
WooCommerce
    >
Settings
    >
Payments
```

---

# 9. Gateway Settings

Create configurable settings:

```text
Enabled
Title
Description
Company ID
API Key
Webhook Secret
Test Mode
Debug Logging
```

Example:

```text
Whop

Enabled:
[Yes]

Title:
[Whop]

Description:
[Pay securely with Whop]

Company ID:
[biz_xxxxxxxxx]

API Key:
[**************]

Webhook Secret:
[**************]

Test Mode:
[Yes]

Debug Logging:
[Yes]
```

Secrets must never be displayed in plaintext after initial configuration.

Do not hardcode credentials.

---

# 10. Whop API Client

Create a dedicated:

```text
Whop_API
```

class.

The class should encapsulate all Whop API communication.

Example responsibilities:

```text
createCheckoutConfiguration()
retrievePayment()
createOffSessionPayment()
listPaymentMethods()
retrievePaymentMethod()
createSetupIntent()
retrieveMember()
retrieveMembership()
cancelMembership()
refundPayment()
```

Do not scatter raw HTTP calls throughout WooCommerce hooks.

All Whop communication should go through the API client.

Whop's API is available at:

```text
https://api.whop.com/api/v1
```

and authenticated requests use Bearer API keys.

---

# 11. Initial WooCommerce Checkout

The customer must be able to select:

```text
Whop
```

as the payment method.

The initial payment flow should be:

```text
Customer
   |
   v
WooCommerce Checkout
   |
   v
Create Whop checkout configuration/session
   |
   v
Render Whop checkout
   |
   v
Customer enters payment details
   |
   v
Whop processes payment
   |
   v
Webhook
   |
   v
WooCommerce order updated
```

Whop supports both checkout links and embedded checkout. Embedded checkout should be preferred where technically compatible with WooCommerce checkout.

---

# 12. Do Not Process Card Details Through PHP

The plugin must never receive:

```text
card number
expiry
CVC
```

directly.

Payment details must be collected by Whop's secure checkout component.

WooCommerce should receive only safe references/results such as:

```text
Whop payment ID
Whop member ID
Whop payment method ID
payment status
```

This reduces PCI/security exposure.

WooCommerce's documentation distinguishes direct gateways from iframe/form-based integrations and notes that direct gateways have additional security/PCI considerations.

---

# 13. Metadata

Every Whop checkout/payment should contain enough metadata to map it back to WooCommerce.

Recommended metadata:

```json
{
  "woocommerce_order_id": "1234",
  "woocommerce_subscription_id": "5678",
  "woocommerce_customer_id": "42"
}
```

If available, also include:

```text
product_id
variation_id
site_identifier
```

Never rely exclusively on email addresses to identify the WooCommerce order.

---

# 14. Initial Payment Success

When the initial payment succeeds:

```text
Whop
  |
  | payment.succeeded
  v
Webhook
  |
  v
Find WooCommerce Order
  |
  v
Mark order paid
  |
  v
Store Whop payment information
```

Store:

```text
_whop_payment_id
_whop_member_id
_whop_payment_method_id
```

as WooCommerce order metadata.

The payment webhook is the authoritative confirmation of successful payment.

Do not mark an order paid merely because the customer returned to the success URL.

Whop explicitly documents `payment.succeeded` as a payment webhook.

---

# 15. Saved Payment Methods

This is a critical feature.

For subscription products, the initial checkout should request that the payment method be saved for future off-session use.

Whop supports:

```text
setupFutureUsage = "off_session"
```

during embedded checkout.

After successful payment, Whop can provide the saved payment-method reference through the payment result/webhook.

Store only:

```text
_whop_payment_method_id
```

Never store the underlying payment credentials.

---

# 16. WooCommerce Payment Token Integration

Integrate with WooCommerce's Payment Token API where appropriate.

WooCommerce provides a Payment Token API specifically for gateways to store and manage references to saved payment methods.

The token should contain a reference such as:

```text
Whop payment method ID
```

rather than payment credentials.

Example conceptual structure:

```text
WooCommerce Customer
    |
    +-- Payment Token
           |
           +-- Gateway: whop
           +-- Token: payt_xxxxx
```

Customers should be able to see/remove their saved Whop payment method if the implementation supports the required Whop functionality.

---

# 17. Subscription Products

The plugin must work with WooCommerce Subscriptions.

Example:

```text
Instagram Growth
$29 / month
```

WooCommerce Subscriptions remains responsible for:

```text
subscription creation
subscription status
renewal dates
renewal orders
cancellation
failed renewal state
```

The Whop gateway is responsible for actually processing the payment.

Do not create a second independent subscription engine inside the plugin.

---

# 18. Recurring Payment Flow

When WooCommerce creates a renewal order:

```text
WooCommerce Subscription
        |
        v
Renewal order
        |
        v
Whop Gateway
        |
        v
Whop API
        |
        v
Saved Whop Payment Method
        |
        v
Off-session payment
```

Whop supports creating an off-session payment using:

```text
company_id
member_id
payment_method_id
```

The API responds with a payment object immediately, while processing occurs asynchronously. Webhooks must therefore be used to determine the final payment outcome.

---

# 19. Renewal Payment Mapping

Each renewal order must contain:

```text
_whop_payment_id
_whop_payment_method_id
_whop_member_id
```

and:

```text
_parent_subscription_id
```

The webhook handler must use metadata/payment references to identify the correct renewal order.

Do not match renewals only by:

```text
email
amount
date
```

because those are not guaranteed to be unique.

---

# 20. Payment Status Mapping

Create a deterministic mapping.

Example:

```text
Whop succeeded
        ↓
WooCommerce payment complete
        ↓
Subscription remains Active
```

```text
Whop failed
        ↓
WooCommerce renewal payment failed
        ↓
WooCommerce Subscriptions handles retry/status
```

Possible Whop payment states include successful, pending, failed, past_due, canceled, refunded and other states. The plugin must map only the states relevant to WooCommerce rather than blindly mapping every Whop status.

---

# 21. Failed Payments

When:

```text
payment.failed
```

is received:

```text
Whop
  |
  v
Webhook
  |
  v
Find renewal order
  |
  v
Mark payment attempt failed
  |
  v
WooCommerce Subscriptions
```

The plugin must not immediately cancel the subscription.

WooCommerce Subscriptions should control the subscription's retry lifecycle.

---

# 22. Custom Retry Schedule

The target schedule should support configurable retries such as:

```text
Retry 1: 1 day
Retry 2: 3 days
Retry 3: 5 days
Retry 4: 7 days
```

The schedule must be configurable from WooCommerce/admin settings rather than hardcoded.

Example:

```text
Retry Schedule:

[1]
[3]
[5]
[7]
```

Meaning:

```text
Payment failure
      |
      +-- after 1 day
      |
      +-- after 3 days
      |
      +-- after 5 days
      |
      +-- after 7 days
      |
      +-- final failure
```

Do not implement a custom cron system until WooCommerce Subscriptions' native retry mechanisms have been evaluated.

Prefer WooCommerce Subscriptions' lifecycle/hooks where possible.

---

# 23. Retry Idempotency

A retry must never accidentally create two payments.

Before creating an off-session Whop payment:

```text
Check renewal order
Check existing Whop payment ID
Check current payment state
```

If an existing payment attempt is already processing or succeeded:

```text
DO NOT create another payment.
```

This is critical for payment safety.

---

# 24. Webhook Endpoint

Create a REST endpoint:

```text
/wp-json/whop/v1/webhook
```

Whop should send events to this endpoint.

The endpoint must:

1. Receive raw request body.
2. Validate Whop webhook signature.
3. Parse event.
4. Check idempotency.
5. Store event ID.
6. Queue expensive processing if necessary.
7. Return HTTP 2xx quickly.

Whop specifically recommends verifying the webhook, doing minimal work before acknowledging it, and not assuming event ordering.

---

# 25. Webhook Events

At minimum support:

```text
payment.succeeded
payment.failed
```

Also design the architecture to support:

```text
setup_intent.succeeded
membership.activated
membership.deactivated
refund.created
dispute.created
```

Whop documents these event types and recommends using webhooks for real-time payment and membership state changes.

---

# 26. Setup Intent Support

Support setup-intent flows if needed.

Whop supports a setup mode that collects payment details without immediately charging the customer.

When successful:

```text
setup_intent.succeeded
        |
        v
payment_method.id
        |
        v
Save against WooCommerce customer/subscription
```

Whop's setup-intent response contains the checkout configuration, member and saved payment method information.

This should be implemented as a reusable service rather than hardcoded into checkout.

---

# 27. Customer Payment Methods

The plugin should expose a customer-facing payment method interface if supported by the WooCommerce account system.

Example:

```text
My Account
    |
    v
Payment Methods

Whop
Visa ending 4242

[Remove]
```

The plugin must never expose sensitive card information.

Whop's Payment Method API provides saved payment-method references and type-specific details.

---

# 28. Subscription Cancellation

When a WooCommerce subscription is cancelled:

```text
Customer/Admin
      |
      v
WooCommerce Subscription
      |
      v
Custom Whop integration
      |
      v
Whop membership/subscription state
```

The plugin must synchronize the appropriate cancellation behavior.

Do not automatically cancel Whop immediately if WooCommerce is configured for:

```text
Cancel at end of billing period
```

The plugin must respect WooCommerce's intended cancellation timing.

---

# 29. Refunds

Implement WooCommerce refund support if Whop's API and payment state permit it.

Flow:

```text
WooCommerce Admin
      |
      v
Refund
      |
      v
Whop refund API
      |
      v
Whop refund result
      |
      v
WooCommerce refund recorded
```

Do not mark a refund complete until the Whop API confirms the request/result.

---

# 30. Webhook Idempotency

Create a database-backed or WordPress-option-backed event registry.

Example:

```text
whop_webhook_events
```

Fields:

```text
event_id
event_type
received_at
processed_at
status
payload_hash
```

Before processing:

```text
Does event_id already exist?
```

If yes:

```text
Return 200
Do not process again.
```

This is required because webhook deliveries can be repeated and ordering is not guaranteed.

---

# 31. Logging

Create a debug logging system.

Use WooCommerce's logging infrastructure where possible.

Logs should include:

```text
Timestamp
Event
WooCommerce Order ID
Subscription ID
Whop Payment ID
Whop Member ID
HTTP status
Error code
Error message
```

Never log:

```text
API keys
webhook secrets
card numbers
CVV
full payment credentials
```

---

# 32. Error Handling

The plugin must gracefully handle:

```text
Whop API unavailable
Invalid API credentials
Invalid webhook signature
Payment declined
Payment pending
Payment failed
Missing payment method
Missing member
Missing WooCommerce order
Duplicate webhook
Malformed webhook
Expired checkout
Network timeout
Rate limit
```

User-facing messages must be safe and understandable.

Example:

```text
Payment could not be completed.
Please try again or use another payment method.
```

Detailed technical information belongs in logs.

---

# 33. Retry API Requests

For safe/idempotent API requests, implement reasonable retry behavior for transient failures.

Do NOT blindly retry payment creation requests.

Payment creation must be idempotent.

The plugin must avoid accidental duplicate charges.

---

# 34. Admin Dashboard

Add a WooCommerce-compatible settings page.

Required sections:

```text
Connection
Checkout
Subscriptions
Retries
Webhooks
Logging
```

Example:

```text
Whop Settings

Connection
--------------------------
Company ID
API Key
Webhook Secret
Test Mode

Checkout
--------------------------
Checkout mode
Embedded checkout
Return URL

Subscriptions
--------------------------
Enable recurring payments
Save payment methods

Retries
--------------------------
Retry 1: 1 day
Retry 2: 3 days
Retry 3: 5 days
Retry 4: 7 days

Logging
--------------------------
Debug logging: Yes/No
```

---

# 35. WooCommerce Order Metadata

Display Whop information inside the WooCommerce order admin page.

Example:

```text
Whop Payment Information

Payment ID:
pay_xxxxx

Member ID:
mber_xxxxx

Payment Method:
payt_xxxxx

Checkout Configuration:
ch_xxxxx

Last Webhook:
payment.succeeded
```

---

# 36. Subscription Metadata

Display:

```text
Whop Subscription Information

Whop Member:
mber_xxxxx

Whop Payment Method:
payt_xxxxx

Last Payment:
pay_xxxxx

Last Payment Status:
Succeeded

Last Attempt:
2026-08-11 20:00

Retry Count:
0
```

---

# 37. Instagram Username

The client's website requires a mandatory Instagram username.

This is not technically part of the Whop gateway itself.

Implement it as a WooCommerce checkout extension.

Field:

```text
Instagram Username *
```

Save it to:

```text
_order_instagram_username
```

and copy it to the subscription metadata.

The value must be searchable from WooCommerce admin.

---

# 38. Admin Search

Add support for searching subscriptions/orders by:

```text
Instagram username
```

Example:

```text
Search:
@john123
```

Result:

```text
John Doe
@john123
Subscription #1234
Status: Active
Plan: Monthly
Next Renewal: Sep 10
```

This functionality should be separate from the payment gateway core.

---

# 39. Product/Plan Mapping

The plugin should support a WooCommerce product-to-Whop mapping where required.

Possible architecture:

```text
WooCommerce Product
        |
        +-- Whop Plan ID
```

Product settings:

```text
Whop Plan ID:
plan_xxxxx
```

However, if Whop's dynamic checkout configuration can correctly create the required checkout from WooCommerce cart/product information, support dynamic checkout instead.

The implementation must not unnecessarily require manually creating a Whop plan for every WooCommerce product.

A third-party Whop/WooCommerce plugin specifically markets dynamic checkout configuration to avoid manually creating a plan ID for every product/variant, which is a useful architectural reference, but this project should implement the behavior using official Whop APIs.

---

# 40. Cart Support

The gateway must correctly calculate:

```text
Subtotal
Discount
Tax
Shipping
Total
Currency
```

and ensure that the amount sent to Whop matches the WooCommerce amount.

Never trust a client-provided amount.

Calculate the amount server-side.

---

# 41. Currency

Initially support:

```text
USD
```

Do not build multi-currency complexity until required.

The gateway should reject unsupported currencies with a clear error.

Whop checkout configurations specify currency, and Whop supports multiple payment methods depending on customer location.

---

# 42. Security Requirements

Must:

* Use HTTPS in production.
* Sanitize all user input.
* Escape all admin output.
* Verify WordPress nonces for admin actions.
* Verify webhook signatures.
* Use capability checks.
* Protect API credentials.
* Never store raw card information.
* Never expose API credentials to frontend JavaScript.
* Never trust webhook data without verification.
* Never trust frontend order totals.
* Prevent duplicate payment attempts.

---

# 43. Checkout Security

Whop checkout should be responsible for collecting sensitive payment data.

WooCommerce should receive references and payment status.

The plugin should not create custom card input fields such as:

```text
Card number
Expiry
CVC
```

inside PHP-rendered WooCommerce fields unless Whop explicitly provides a secure client-side component that supports this integration.

---

# 44. WordPress Compatibility

Do not modify:

```text
wp-admin core
WooCommerce core
WooCommerce Subscriptions core
WordPress core
```

Everything must be implemented through:

```text
hooks
filters
APIs
plugin classes
REST endpoints
```

---

# 45. Checkout Blocks Compatibility

WooCommerce has both the classic checkout and the newer Checkout Block architecture.

The initial MVP should target the checkout implementation actually used by the client's website.

However, structure the gateway so Checkout Block support can be added later.

Do not assume the classic gateway API automatically works with the new Checkout Block.

WooCommerce's own gateway documentation explicitly distinguishes classic gateway development from custom payment method integration for the new Checkout block.

---

# 46. MVP Scope

The first working version must support only:

```text
1. WooCommerce gateway appears
2. Whop credentials configurable
3. Customer selects Whop
4. Whop checkout opens/embeds
5. Customer completes payment
6. Whop webhook received
7. WooCommerce order marked paid
8. Whop payment ID stored
9. Whop member ID stored
10. Whop payment method ID stored
11. Basic logging
```

Do not start with subscriptions.

---

# 47. Phase 2

After MVP works:

```text
1. WooCommerce Subscriptions support
2. Save payment method
3. Renewal payment
4. payment.succeeded handling
5. payment.failed handling
6. Renewal order mapping
```

---

# 48. Phase 3

Then:

```text
1. Custom retry schedule
2. Cancellation synchronization
3. Payment-method management
4. Refunds
5. Customer account integration
6. Admin payment information
```

---

# 49. Phase 4

Then:

```text
1. Instagram field
2. Instagram subscription metadata
3. Instagram admin search
4. Status filters
5. CSV export
6. Improved admin UI
```

---

# 50. Testing Environment

Never begin production development directly against the client's live payment account.

Use Whop sandbox/test capabilities where available.

Create:

```text
Local/Staging WordPress
        |
        v
WooCommerce Test
        |
        v
Whop Test/Sandbox
```

Test:

```text
Successful payment
Failed payment
Pending payment
Webhook
Duplicate webhook
Webhook out of order
Saved payment method
Off-session payment
Recurring payment
Retry
Cancellation
Refund
```

---

# 51. Acceptance Criteria

## Initial payment

Given a customer has a WooCommerce product,

When they select Whop,

Then Whop checkout must be displayed/initiated.

When payment succeeds,

Then WooCommerce must mark the order paid.

---

## Failed initial payment

When Whop reports payment failure,

Then WooCommerce must not mark the order paid.

The customer must be able to retry checkout.

---

## Saved payment method

When a subscription customer completes their initial payment,

Then a Whop payment-method reference must be stored against the WooCommerce customer/subscription.

No sensitive payment credentials may be stored.

---

## Renewal

When a subscription renewal becomes due,

Then WooCommerce must initiate the Whop off-session payment.

When Whop succeeds,

Then the renewal order must become paid and the subscription remain active.

---

## Failed renewal

When Whop reports failure,

Then WooCommerce must record the failed renewal.

The subscription must enter the configured retry flow.

---

## Retry

Given:

```text
1, 3, 5, 7 days
```

When a payment fails,

Then retries must occur according to the configured schedule.

No duplicate payment attempts may occur.

---

## Cancellation

When the customer/admin cancels a WooCommerce subscription,

Then the corresponding Whop membership/payment relationship must be synchronized according to the configured cancellation behavior.

---

## Webhooks

When a webhook is received:

```text
signature valid
```

must be required before processing.

If the same webhook is received twice:

```text
process once
return 200 on duplicate
```

---

# 52. Development Rules for AI/Vibe Coding

The AI coding agent must NOT generate the entire plugin in one pass.

Implement incrementally.

Recommended order:

```text
Step 1
Plugin skeleton

Step 2
Gateway registration

Step 3
Gateway settings

Step 4
Whop API client

Step 5
Simple Whop checkout

Step 6
Initial payment

Step 7
Webhook verification

Step 8
Order synchronization

Step 9
Payment method saving

Step 10
WooCommerce Payment Token integration

Step 11
Subscription integration

Step 12
Recurring payment

Step 13
Failed payments

Step 14
Retry schedule

Step 15
Cancellation

Step 16
Refunds

Step 17
Admin UI

Step 18
Instagram functionality

Step 19
Testing

Step 20
Production hardening
```

After each step:

```text
Generate
   ↓
Review
   ↓
Run
   ↓
Test
   ↓
Fix
   ↓
Commit
```

Never move to the next major payment feature while the previous feature is untested.

---

# 53. AI Coding Instructions

When generating code:

1. Use official WooCommerce APIs.
2. Use official Whop APIs.
3. Do not invent Whop endpoints.
4. Do not invent WooCommerce hooks when an official API exists.
5. Search the provided official documentation when uncertain.
6. Prefer small classes over one huge PHP file.
7. Add PHPDoc to public methods.
8. Validate all external API responses.
9. Log errors safely.
10. Never expose secrets.
11. Never store raw payment data.
12. Use WordPress HTTP APIs or the official Whop SDK where appropriate.
13. Make webhook handlers idempotent.
14. Use server-side amount calculations.
15. Do not modify WordPress/WooCommerce core.
16. Keep payment processing isolated from presentation logic.

---

# 54. Important Architectural Decision

The plugin should treat the systems as:

```text
WooCommerce
= Ecommerce + subscription source of truth

Whop
= Payment processor
```

However, Whop's payment/membership state must still be synchronized through webhooks.

Do not assume WooCommerce and Whop will always agree.

The integration layer exists specifically to reconcile them.

---

# 55. Data Relationship

Recommended conceptual relationship:

```text
WooCommerce Customer
        |
        +---- Whop Member ID
        |
        +---- Whop Payment Method Token
        |
        +---- Instagram Username
        |
        +---- WooCommerce Orders
                     |
                     +---- Whop Payment IDs
                     |
                     +---- WooCommerce Subscriptions
                                  |
                                  +---- Whop Member ID
                                  +---- Whop Payment Method ID
                                  +---- Last Whop Payment ID
```

---

# 56. Important Payment Principle

Never consider the browser redirect/return URL as authoritative payment confirmation.

Correct:

```text
Browser
  ↓
Return page

AND

Whop
  ↓
Verified webhook
  ↓
WooCommerce
```

The webhook determines the final payment state.

---

# 57. Observability

Every payment attempt should have a traceable relationship:

```text
WooCommerce Order
       |
       v
WooCommerce Renewal Order
       |
       v
Whop Payment ID
       |
       v
Webhook Event ID
```

This allows support/debugging to answer:

```text
Why was this customer not renewed?
```

without manually searching multiple systems.

---

# 58. Definition of Done

The plugin is considered production-ready only when:

* Initial Whop payments work.
* Orders synchronize correctly.
* Saved payment methods work.
* Recurring payments work.
* Failed payments work.
* Retry scheduling works.
* Cancellation works.
* Refund behavior is tested.
* Webhooks are verified.
* Duplicate webhooks are safe.
* API failures are handled.
* Secrets are protected.
* No sensitive payment information is stored.
* Admin can inspect Whop payment references.
* Customer payment methods work where supported.
* Instagram information is searchable.
* Full end-to-end subscription lifecycle testing passes.
* Production credentials are configured securely.
* Logs contain enough information for debugging without exposing secrets.

---

# 59. Recommended First Milestone

Do NOT start by implementing subscriptions.

The first milestone is:

```text
WooCommerce Product
       |
       v
WooCommerce Checkout
       |
       v
Whop Checkout
       |
       v
Whop Payment
       |
       v
Whop payment.succeeded
       |
       v
Verified Webhook
       |
       v
WooCommerce Order = Paid
```

Once this works reliably, proceed to saved payment methods and subscriptions.

---

# 60. Final Development Philosophy

The purpose of this project is NOT to recreate Whop.

The purpose is NOT to recreate WooCommerce.

The purpose is to build a thin, reliable bridge:

```text
             WOOCOMMERCE
                  |
                  |
          Custom Whop Gateway
                  |
                  |
                WHOP
```

WooCommerce handles ecommerce state.

Whop handles payment processing.

The plugin connects the two systems safely and deterministically.
