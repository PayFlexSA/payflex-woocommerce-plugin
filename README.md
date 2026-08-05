# Payflex for WooCommerce

A WooCommerce payment gateway for [Payflex](https://payflex.co.za), letting shoppers
pay in interest-free instalments. The plugin handles the checkout redirect to
Payflex, the payment callback, refunds, and order status reconciliation.

This README is for developers working on the repo. The public WordPress.org
listing lives in `readme.txt`. Deeper documentation (test suite internals,
release recovery, known defects) lives in [AGENTS.md](AGENTS.md); neither file
ships in the WordPress.org release.

## Requirements

- PHP 8.1+
- Composer
- A WordPress + WooCommerce install to run the plugin itself. The test suite
  needs neither.

## Running the tests

```bash
composer install
composer test    # PHPUnit: no database, WordPress install, or network needed
composer lint    # syntax-check every shipped PHP file (same as CI's lint job)
```

The suite runs against custom WordPress and WooCommerce stubs, so it
finishes in under a second. To run a single suite or test, use PHPUnit's
filter:

```bash
vendor/bin/phpunit --filter RefundTest
```

If the output contains "Payflex: orderId2..." lines, those are not failures.
They come from known leftover debug logging in the refund path, and a test
tracks their removal.

## Project layout

- `partpay.php` - plugin entry point; WordPress reads the version from its header
- `includes/` - the gateway class and supporting code
- `config/` - API environment configuration
- `assets/` - checkout and widget JS/CSS
- `tests/` - PHPUnit suite and its WordPress/WooCommerce stubs
- `readme.txt` - the WordPress.org listing

## Releasing

The version appears in three places, and the tests fail if they disagree: the
`Version:` header in `partpay.php`, `private $version` in
`includes/class-wc-gateway-payflex.php`, and `Stable tag:` in `readme.txt`.

1. Update all three, and add a changelog entry to `readme.txt`.
2. Run `composer test` locally, merge to `main`, and confirm the Tests workflow
   is green.
3. Publish a GitHub release tagged `vx.y.z` (or `x.y.z`).

Publishing the release triggers the deploy to WordPress.org. The deploy job is
skipped if the tests fail or the tag doesn't match the plugin version, so a
failed release publishes nothing. Recovery steps are in
[AGENTS.md](AGENTS.md).

## Contributing

- New tests extend `PF_TestCase` and live in `tests/*Test.php`. Name them as
  sentences: `test_a_declined_payment_fails_the_order`, not `testDecline`.
- Prefer behavioural assertions (order status, request body, the notice the
  shopper sees) over mock expectations.
- The suite fails on PHP warnings, notices, and deprecations. Keep it clean; a
  new warning on a payment path is a real bug.

Test suite conventions and helpers are documented in [AGENTS.md](AGENTS.md).
