# OleKard Events local POC

This opt-in local mode is for demonstrating event authoring, sponsor branding/report access, ticket purchase, admission, cancellation recovery and maker-checker settlement. It is not a production configuration and must never be used with live payments or external sponsor accounts.

## Enable locally

In `services/api/.env`, keep `APP_ENV=local` and set:

```dotenv
EVENTS_POC_ENABLED=true
EVENTS_POC_DEMO_SPONSORS_ENABLED=true
EVENTS_POC_DEMO_NOTIFICATIONS_ENABLED=true
EVENTS_LOCAL_DEMO_PRICING_ENABLED=true
EVENTS_LOCAL_DEMO_PAYMENT_FULFILMENT_ENABLED=true
```

Then clear config cache and restart the API:

```powershell
php artisan config:clear
php artisan migrate
php artisan db:seed --class=DevEventWorkflowUsersSeeder
php artisan db:seed --class=DevFiveOrganisationEventsSeeder
php artisan db:seed --class=DevPaidEventSeeder
```

The POC sponsor APIs and local publication providers return unavailable outside `APP_ENV=local` with the POC flag on. Local cancellation notices are written to the POC notification table and never sent to SMS, email, push, or an external provider.

## Demo journey

1. Sign in to Admin as the local event creator, select the local demo organisation, create an event, and create a structured sponsor record. Add a brand logo URL/description and select the report-viewer member by name. Link the sponsor to the event.
2. Assign the event currency using the active local currency list and a visibly POC reference. Configure paid ticket categories and submit the current event version.
3. Sign in as the reviewer to approve the event, then as the finance approver to approve prices and publish. Sales start paused; resume them explicitly.
4. In the customer app, open Events, review sponsor branding and ticket terms, reserve tickets, confirm the price, then complete checkout through the configured local mock-bank provider. The payment and ticket issuance still use the existing Payment/Ledger/Events path; do not use real provider credentials.
5. Open My Event Tickets and present the QR credential to the Agent app. Validate online at the assigned gate. Offline admission remains denied.
6. The assigned sponsor report viewer can load category aggregates. The report contains no attendee names or ticket identifiers.
7. For a cancellation, request and approve through different event actors. Eligible paid orders receive a clearly labelled `simulated_refunded` POC record; the API does not call a refund provider or post a compensating Ledger journal. Local cancellation notices are recorded as `simulated_delivered` only.
8. For a fast local demo, the assigned payout requester can choose **Simulate event end (POC only)** after resolving active ticket holds and pending payments. This pauses ticket sales and records an auditable simulated end; it does not alter the event dates or claim the event occurred. The requester then creates a settlement request, and a different assigned approver marks it `simulated_paid`. This is a demo status/reference only; it does not transfer funds or post a settlement journal.

## Boundaries and production replacement

- The sponsor record and report scopes are real local data, but there is no BTC API connection, BTC authentication, webhook, sponsor SSO, or external report delivery. BTC API documentation and a signed integration agreement are still needed.
- Organiser identity, bootstrap approval and publication readiness use synthetic evidence only in local POC mode. Do not treat those records as KYC, governance or publication approval.
- POC pricing uses zero fee and zero tax. It demonstrates checkout mechanics, not approved commercial terms. Real fee/tax policies, payment provider configuration, currency approval, terms acceptance and production price readiness must replace it.
- Simulated cancellation refunds and payout statuses are not financial ledger entries and are not proof that money was returned or paid. Production must use approved shared Payments/Refund and Payout/Ledger flows.
- `simulated_delivered` means the POC wrote a local notification record; it does not mean a customer received a message.
- POC seeded roles/assignments are development conveniences. Production access requires actual authority, independent reviews, release acceptance and production identity/onboarding evidence.
