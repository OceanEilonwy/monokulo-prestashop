# Monokulo for PrestaShop

Accept Monero in a PrestaShop shop through your own
[Monokulo](https://monokulo.com/), the self-hosted, non-custodial Monero
payment processor. Monokulo only ever holds a view key: it watches the
chain for payments to your wallet and tells the shop when an order is paid.
No spend key touches the shop or Monokulo.

- PrestaShop **8.2** or **9** (tested on 8.2.8 with PHP 8.1, and on 9.2.0
  with PHP 8.2 and 8.5); PHP **8.1** or newer
- A Monokulo whose operator has set its public address
- GPL-3.0-or-later

## Install

1. Download `monokulo.zip` from the
   [latest release](https://github.com/OceanEilonwy/monokulo-prestashop/releases/latest).
2. In the back office: **Modules > Module Manager**, **Upload a module**,
   and choose the file. PrestaShop installs it.
3. **Configure** next to *Monokulo (Monero)*.

## Connect

On the module's settings page:

1. Enter your **Monokulo address**, as your browser opens it
   (`https://pay.example.com`), and **Save**.
2. Under **Stores**, press **Connect** next to your shop.
3. Sign in to Monokulo (or sign up) in the page that opens. Monokulo finds
   your store by the shop's web address:
   - one of your Monokulo stores has it: confirm;
   - none does: set one up (its name; the address is filled in), choose
     the wallet its money goes to, then **Back to PrestaShop**.
4. You're back on the settings page: *Your store is connected. Monero is
   now offered at checkout.*

Monero is offered at checkout once the shop is connected. Monokulo only
sends the connect flow back to the shop over **https** (plain http only for
`localhost` or a `.onion` address), so the shop's address must be https.

With multistore, each shop connects on its own: a Monokulo store is one
web address. Shops that share an address share one connection. **Connect
again** replaces a shop's keys (Monokulo replaces its webhook too).

Which countries, currencies and customer groups see Monero is up to
PrestaShop's own **Payment > Preferences**, as for any payment module.

## What the customer sees

At checkout's payment step, *Pay with Monero*. Placing the order sends the
customer to the Monokulo checkout page for it: the amount in XMR, the
address, a QR code, and the payment's progress.

The order confirmation email names the payment method with the shop's
payment link (`/module/monokulo/pay?id_cart=…&key=…`), and the order in the
customer's account and the order confirmation page show a **Pay with
Monero** button while the order is unpaid. The link always leads to the
same Monokulo order; if Monokulo couldn't be reached when the order was
placed, it tries again.

The shop asks Monokulo for the order total in the customer's currency;
Monokulo converts it to XMR at its own exchange rate. The currency must be
one your Monokulo prices (or XMR itself).

## Order statuses

Monokulo tells the shop about each change with a signed webhook, and the
module moves the order through PrestaShop's order history, so PrestaShop
does what it always does on a status change (the status's email, the
invoice and payment for a paid status, stock back for a cancelled one).
Each status is configurable under **Order statuses**; the defaults:

| Monokulo event | What happened | PrestaShop status (default) | Order messages |
| --- | --- | --- | --- |
| (order placed) | The customer was sent to pay | **Order placed**: Awaiting Monero payment | The payment link |
| `order.unconfirmed`, `order.confirming` | A payment was seen; waiting for confirmations | **Payment seen**: Awaiting Monero payment | Note |
| `order.partial` | Less than the amount due arrived (underpaid) | **Part paid**: Awaiting Monero payment | Note |
| `order.paid` | Paid in full | **Paid**: Payment accepted (its email tells the customer) | Note with a link to the order in Monokulo |
| `order.overpaid` | Paid with more than was due | **Paid**: Payment accepted | As paid, and that the excess isn't refunded automatically |
| `order.expired` | The payment window closed without full payment | **Expired**: Canceled | Note: any funds received stay at the address |
| `order.double_spend_detected` | A payment was double-spent and no longer counts | unchanged | Fraud alert (the status change, if any, comes as its own event) |
| `order.double_spend_reversed` | That alert was wrong | unchanged | Note |

*Awaiting Monero payment* is the module's own status, made when it is
installed. The notes are private messages on the order, which only you see
on the order's page in the back office.

A paid order stays paid: if Monokulo later reports it expired or part paid
(after a double spend, say), the order keeps its status, and a note says
so. If an order's total changed after a first payment request and the older
request is paid, the order isn't marked paid: a note asks you to check the
amount.

### Refunds

Monokulo is non-custodial: it never holds the money, so there are no
automatic refunds. Refund an overpayment or the funds of an expired order
from your wallet yourself. The customer may have left a refund address on
the Monokulo checkout; it's on the order in your Monokulo dashboard.

## Webhooks

Connecting registers the shop's webhook address with Monokulo:
`https://<your shop>/index.php?fc=module&module=monokulo&controller=webhook`,
PrestaShop's plain module address, which works whatever the shop's
friendly URL and language settings are, now or later. Each delivery is signed (`X-Monokulo-Signature`); the module refuses
a missing or wrong signature and one more than five minutes old. Monokulo
retries a delivery the shop didn't take; a delivery seen before changes
nothing. Deliveries and their answers are listed in Monokulo, in the store's
settings under *Webhooks*. While the shop is in maintenance mode, PrestaShop
answers every request with 503, and Monokulo retries until it's back.

## Logs

The module writes to `var/logs/monokulo.log`, each line with the request's
trace id, which matches Monokulo's own logs; its warnings and errors also
go to **Advanced Parameters > Logs**. **Send errors to Monokulo** also
sends them to your Monokulo, when the store there accepts client logs.

## Uninstall

Uninstalling removes the module's settings and keeps its tables
(`ps_monokulo_*`) and its *Awaiting Monero payment* status: orders refer to
them, and installing again picks your connections back up.

## Development

Everything runs in Docker on the host's network (Linux): the official
PrestaShop image at `http://localhost:8093/` with MariaDB.

```sh
scripts/test up          # build and start PrestaShop (PS_VERSION=9.2.0|8.2.8, PHP_VERSION=8.1…8.5; default 9.2.0 on 8.5)
scripts/test all         # build the zip, upload it through the module manager, run every suite
scripts/test front       # one suite: unit, front, admin or http (extra args go to PHPUnit)
scripts/test lint        # PrestaShop's coding standard (scripts/test fix applies it)
COVERAGE=1 scripts/test all   # and coverage/summary.txt, coverage/html/
scripts/smoke            # against a real Monokulo (MONOKULO_BIN=path or MONOKULO_IMAGE=image)
scripts/test down
```

Back office: `http://localhost:8093/admin-dev/`, `admin@example.test`,
password `admin-password-1`.

- **unit**: the module's library (`src/lib`), which knows nothing of
  PrestaShop (except `src/lib/Shop`, its glue to it), against a stand-in
  Monokulo.
- **front**, **admin**: the installed PrestaShop booted in the test
  process, the way its front office and back office boot, each test in a
  rolled-back database transaction.
- **http**: real HTTP through Apache: the module upload, the settings page
  and the connect flow's redirects, a guest's checkout, webhooks.
- **smoke**: a fresh Monokulo (`docker/monokulo-smoke.toml`): sign up, add
  a watch-only wallet, connect, then a guest checkout lands on the Monokulo
  checkout, whose status is pending. Nothing is paid.

The test shop leaves out PrestaShop's marketing and cloud modules
(`docker/ps-setup`), which call home and take no part in a payment.

Webhook tests use Monokulo's published test vectors, vendored in
`tests/vectors` and pinned to a monokulo commit (`tests/vectors/PIN`).
`scripts/vectors check` compares them with the pinned commit;
`scripts/vectors pin <commit>` moves the pin.

## Release

Bump `VERSION`, the module's version in `src/monokulo.php` and
`Plugin::VERSION` together (a test checks), then push a tag `v<version>`.
The release workflow uploads the zip through PrestaShop's module manager,
runs the tests and publishes `monokulo.zip` and its `.sha256`.
`scripts/build-zip` builds the same zip locally.
