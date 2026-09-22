# Payflex WooCommerce Plugin — Agent & Deep-Dive Guide

Detailed internal documentation for the Payflex payment gateway plugin, aimed
at AI coding agents and anyone doing deep work on the repo. Start with
[README.md](README.md) for the basics (requirements, running tests, project
layout, release checklist). This file is excluded from the WordPress.org
deploy via `.distignore`.

- [What the tests cover](#what-the-tests-cover)
- [How the test suite works](#how-the-test-suite-works)
- [CI and the deploy gate](#ci-and-the-deploy-gate)
- [If a release fails because the tests fail](#if-a-release-fails-because-the-tests-fail)
- [Writing new tests](#writing-new-tests)
- [Known defects the suite documents](#known-defects-the-suite-documents)

---

## What the tests cover

414 tests across 17 suites.

| Suite | What it covers |
| --- | --- |
| `OptionsTest` | `payflex_get_option()`, including the corrupted-option guard |
| `GatingTest` | `payflex_enabled()`, admin-only mode, widget-only mode, product/checkout widget gates, environment detection |
| `ConfigTest` | `config/config.php` — required keys, HTTPS, no UAT hosts in production |
| `WidgetTest` | Calculator widget markup, settings→attribute mapping, shortcode, Gutenberg block, variation price script |
| `AuthenticationTest` | Token fetch, transient caching and early expiry, 401 and network failures, credential redaction in logs |
| `LimitsTest` | `/configuration` fetch, limit persistence, `check_cart_within_limits()` boundaries |
| `EligibilityTest` | `Payflex_Eligibility` product/cart/order rules, the shopper message, gateway removal, `process_payment()` refusal, the Store API cart payload, the shipped defaults |
| `OrderMetaTest` | Payflex order id/token reads including pre-HPOS fallbacks, workflow status, gateway helpers |
| `ProcessPaymentTest` | The full `/order/productSelect` payload, meta written on success, every error branch, re-checkout guards |
| `PaymentCallbackTest` | The return-from-Payflex flow: approve/decline/abandon, replay protection, forged-status and amount-mismatch rejection |
| `RefundTest` | Refund request shape, success/404/500 handling, the `MRM007` refunds-disabled path, the `woocommerce_order_status_refunded` hook |
| `CronTest` | Queue windows (new / scheduled / all), status reconciliation, duplicate-note suppression, scheduling and teardown |
| `SettingsFormTest` | Field definitions, custom renderers, the exclusion count rows, credential trimming on save, checkout instalment breakdown |
| `AdminProductsTest` | The products list column and Payflex status filter, and the exclusion counts the settings screen reports |
| `HooksTest` | Gateway registration, HPOS/blocks compatibility flags, block checkout integration, cancel-on-Payflex handler |
| `SupportPageTest` | The support screen renders and reports accurately; `redirect_url` open-redirect rejection |
| `PluginIntegrityTest` | Version consistency across three files, syntax, referenced assets exist, `.distignore` completeness |

### What is deliberately not covered

- **The browser leg of checkout.** Anything that happens on Payflex's hosted
  pages, and the shopper's actual redirect, is outside this suite. What *is*
  covered is everything the plugin decides on either side of that hop: the
  payload it builds, and how it interprets the return.
- **`receipt_page()`** calls `header()` followed by `exit`, which would end the
  PHPUnit process. It is a three-line redirect; test it manually.
- **Real HTTP against Payflex.** All API calls are stubbed. Sandbox credentials
  and a real order are still the only way to verify the API contract itself.
- **JavaScript.** `assets/*.js` has no test coverage. The suite only asserts the
  files exist and are registered, because `filemtime()` on a missing asset is a
  PHP warning.
- **Rendered CSS/visual output.** Markup is asserted, appearance is not.

---

## Eligibility

`Payflex_Eligibility` (`includes/class-payflex-eligibility.php`) is the single
place that decides whether Payflex may be used. Everything else asks it:

| Caller | Method | Effect |
| --- | --- | --- |
| `check_cart_within_limits()` on `woocommerce_available_payment_gateways` | `evaluate_cart()` | Removes `payflex` from the gateway list. This one filter covers the classic checkout, the Cart/Checkout blocks and the Store API, because the blocks intersect their payment methods with the Store API cart's `payment_methods`. |
| `process_payment()` | `evaluate_order($order)` | Refuses a payment whose order picked up an ineligible line after the page was rendered. Product rules only — WooCommerce re-runs the gateway filter on submission, so the amount limits have already been applied to the cart, and an order placed inside the limits should stay payable if the merchant later changes them. |
| `payflex_frontend_widget()` | `is_product_eligible()` | Returns nothing, so the product page, the `[payflex_widget]` shortcode and the Gutenberg block all render no widget. `payflex_update_price_on_variation()` bails on the same check, since there is no widget left to update. Called `woo_payflex_frontend_widget()` before the global-prefix pass; that name survives as a thin alias for merchant themes and is covered by `WidgetTest::test_the_legacy_widget_function_name_still_works`. |
| `payflex_checkout_widget_enabled()` | `evaluate_cart()` | Hides the checkout instalment stepper. |
| `payflex_cart_eligibility_data()` | `evaluate_cart()` | Ships `eligible` and `message` on the Store API cart response, which `assets/payflex-eligibility.js` renders through `ExperimentalOrderMeta`. That script registers once per block scope (`woocommerce-cart` *and* `woocommerce-checkout`) — a plugin only fills the slots of the scope it was registered for, so one registration leaves the other block silent. |
| `render_eligibility_notice()` | `evaluate_cart()` | Prints the reason on the classic cart and checkout. Static, and registered from the `plugins_loaded` bootstrap in `partpay.php` rather than the gateway constructor — see the double-instantiation note below. |

Three details are easy to get wrong:

- **Every exclusion ships switched off, and there is no migration.**
  `exclude_subscriptions` and `enable_product_exclusions` both default to `'no'`
  and `excluded_product_cats` to `[]`, so the feature does nothing until a
  merchant asks for it. Because nothing backfills the option, a store that has
  never opened the settings screen has no value written at all — so every reader
  tests for `=== 'yes'` rather than `!== 'no'`, and an unset option behaves
  exactly like an unticked box. Flipping any of those comparisons back would
  start hiding Payflex on carts nobody opted in. Pinned by
  `EligibilityTest::test_settings_that_predate_the_feature_exclude_nothing`.
- **Category exclusions cover children.** `excluded_by_category()` adds every
  ancestor of the product's own terms before intersecting, because
  `wc_get_product_term_ids()` returns only directly assigned terms while the
  settings count's `tax_query` leaves `include_children` at the WP default of
  true. Excluding a parent category has to mean the same thing on both sides.
- **The amount limits are read once per request.** `Payflex_Eligibility::limits()`
  memoises them, and `check_cart_within_limits()` calls `use_gateway($this)` first.
  Resolving `WC_Gateway_PartPay::instance()` from inside
  `woocommerce_available_payment_gateways` constructs a second gateway, whose
  constructor adds that same callback to the hook being iterated.
- **A failed limits refresh is not a successful one.**
  `payflex_limit_last_updated` is stamped only on a 200 and holds for
  `LIMIT_REFRESH_INTERVAL`; every attempt stamps `payflex_limit_last_attempt`,
  which backs off for the much shorter `LIMIT_RETRY_INTERVAL`. Treating a failure
  as fresh would leave an install that has never stored limits reporting every
  cart as inside them for a whole day.

Two polarities are deliberate and are pinned by tests: **no cart** counts as a
zero total and *removes* the gateway (`EligibilityTest::test_no_cart_is_treated_as_a_zero_total`),
but *enables* the checkout widget (`GatingTest::test_checkout_widget_enabled_when_there_is_no_cart`).

Filters: `payflex_ineligible_product_types`, `payflex_product_reason`,
`payflex_eligibility_result`. Note that `PF_State::reset()` deliberately keeps
`$hooks`, so a test that adds one must restore the registry — see
`EligibilityTest::withFilter()`.

`Payflex_Admin_Products` (`includes/class-payflex-admin-products.php`) is the admin
side of the same feature: the products list column and status filter, and the two counts
the settings screen shows. It covers the per product checkbox only — category and
subscription exclusions are not editable from a product, so showing them there would be
misleading.

Two things about that class are load-bearing and easy to undo by accident:

- **The column needs an explicit width.** `render_column_style()` prints
  `.column-payflex_excluded{width:150px}` on `admin_head-edit.php`. The list table uses
  `table-layout: fixed` and every other column already claims a width, so without it the
  column collapses to zero and the header label wraps one character per line — a 550px
  tall header that pushes the product list off screen.
- **The counts are gated to the settings screen.** They are appended to the
  `description` of `enable_product_exclusions` and `excluded_product_cats` so they render
  inside the same block as the setting they describe, which means they are built in
  `form_fields()` — and that runs from the gateway constructor on *every* admin request.
  `on_settings_screen()` (`page=wc-settings`) keeps the counting queries off every
  other page. The `get_terms()` call behind the *Excluded Categories* dropdown is gated
  on the same check, for the same reason.
  `SettingsFormTest::test_no_products_are_counted_away_from_the_settings_screen` and
  `test_no_categories_are_queried_away_from_the_settings_screen` pin it.
- **The counts are totals, not lists.** `count_products()` runs a `WP_Query` with
  `posts_per_page => 1` and reads `found_posts`. Asking for every id with
  `posts_per_page => -1` and `count()`ing it pulls a whole catalogue into memory to
  render one number. `COUNTED_STATUSES` includes `future`, because scheduled products
  appear in the products list the badge links to.

`add_script_to_settings_page()` is gated on the same check. It is hooked to `admin_footer`,
which fires everywhere, and its ~7KB of CSS restyles tables and inputs. It still prints
twice on the settings screen itself, because the gateway is instantiated twice and each
instance registers its own callback.

**That double instantiation is a trap for any new hook.** `WC_Payment_Gateways::init()`,
`WC_Payflex_Blocks::initialize()` and `WC_Gateway_PartPay::instance()` each build their
own gateway, so `add_action(..., [$this, ...])` in the constructor stores the callback
two or three times and the output repeats. Anything shopper-facing belongs in the
`plugins_loaded` bootstrap as a static callback, which is where the eligibility notice is
registered. `HooksTest::test_the_eligibility_notice_is_registered_once_however_many_gateways_exist`
pins it.

Shopper-facing messages must stay plain text. The classic notice runs them
through `esc_html()` and the Store API types the field as a string, so
`plain_price()` strips what `wc_price()` wraps around an amount.

---

## How the test suite works

The plugin is loaded against hand-written WordPress and WooCommerce stubs
instead of a real install. That keeps CI to a single `composer install` with no
MySQL service, and keeps the suite fast enough to run on every save.

```
tests/
├── bootstrap.php                    # defines ABSPATH, loads stubs, then loads the plugin
├── PF_TestCase.php                  # base class: state reset + arrangement helpers
├── PF_PluginMeta.php                # reads version/header data straight from source
├── stubs/
│   ├── State.php                    # PF_State — all mutable test state
│   ├── wordpress.php                # WP functions: options, transients, hooks, HTTP, escaping
│   ├── woocommerce.php              # WC_Payment_Gateway, WC_Order, cart, wc_* functions
│   └── woocommerce-namespaced.php   # FeaturesUtil, AbstractPaymentMethodType, etc.
└── *Test.php
```

`bootstrap.php` loads the plugin the way WordPress does — `partpay.php` first,
then `do_action('plugins_loaded')`, which is what defines `PAYFLEX_PLUGIN_URL`
and requires the gateway class. Hooks registered at load time stay registered
for the whole run, so tests can fire them with `do_action()` /
`apply_filters()`.

### Key conventions

**All stub state lives in `PF_State`** and is reset in `setUp()`, along with the
gateway singleton and its static logger.

**HTTP is queued, not mocked.** Nothing reaches the network; an unstubbed call
returns a `WP_Error` so it fails loudly rather than looking like an empty
response.

```php
// Consumed once, matched on a URL substring
PF_State::queue_json(200, ['orderStatus' => 'Approved'], '/order/PF-1');

// Standing response — never consumed, for endpoints polled repeatedly
PF_State::stub_json(200, ['minimumAmount' => 50], '/configuration');

// Simulate a transport failure
PF_State::queue_response(new WP_Error('http_request_failed', 'timeout'), '/refund');
```

**Helpers on `PF_TestCase`:**

| Helper | Purpose |
| --- | --- |
| `gateway([$overrides])` | Settings + a pre-cached access token, then a fresh gateway. Pass `false` as the second argument when the test is *about* authentication. |
| `set_settings([$overrides])` | Write `woocommerce_payflex_settings` (merged over `VALID_SETTINGS`) |
| `withLimits($min, $max)` | Store limits *and* stub `/configuration` to return them |
| `order([$props])` | Register a fake order with one line item |
| `captureRedirect($fn)` | Run code that ends in `wp_redirect()` + `exit` and return the target |
| `requestBody($n)` | JSON-decode the nth recorded request body |
| `assertLogged($needle)` | Assert a `WC_Logger` message contains `$needle` |
| `assertNotice($needle)` | Assert a `wc_add_notice()` message contains `$needle` |

**`wp_redirect()` throws `PF_RedirectException`** instead of the `exit` that
follows it in production. That is what makes the return-from-Payflex flow
testable — use `captureRedirect()`.

**`WC_Order` is a facade** over shared data in `PF_State::$orders`, so
`new WC_Order($id)` and `wc_get_order($id)` see the same state. The plugin mixes
both. Use `$order->pf_data()` to reach the backing store for assertions.

### "Payflex: orderId2…" lines in the output

Those are not test failures. `process_refund()` contains three leftover debug
`error_log()` calls, and PHPUnit surfaces anything written to the error log.
`PluginIntegrityTest::test_leftover_debug_error_log_calls_are_still_present`
tracks them; the noise disappears when they are removed from the gateway.

### Limits of the approach

The stubs are a model of WordPress, not WordPress. Where behaviour matters they
mirror core deliberately (transient expiry semantics, `init_settings()` only
applying defaults when no option row exists, `get_customer_order_notes()`
returning only customer notes). Anywhere they drift, a test can pass while
production breaks. If a bug slips through, fix the stub as well as the code, and
say so in the commit.

---

## CI and the deploy gate

Three workflows in `.github/workflows/`:

**`tests.yml`** — PHPUnit on PHP 8.3 and 8.4, plus a `php -l` syntax check on
7.4, 8.1 and 8.4. Runs on pushes to `main`, on every pull request, on demand,
and is callable by the other two workflows.

**`deploy-to-wordpress.yml`** — triggered by publishing a GitHub release. Three
jobs:

1. `test` — calls `tests.yml` against the released commit.
2. `version-check` — the release tag must match the `Version` header in
   `partpay.php` (a leading `v` is stripped, so `v2.7.1` matches `2.7.1`).
3. `deploy` — `needs: [test, version-check]`. Because it *needs* both, the job
   is **skipped entirely** if either fails. Nothing reaches WordPress.org SVN.

**`update-wordpress-assets.yml`** — pushes `readme.txt` and marketplace assets
when they change on `main`. Also gated on `tests.yml`, because `readme.txt`
carries the `Stable tag` that the suite checks against the plugin header.

---

## If a release fails because the tests fail

The deploy job is skipped, so **nothing was published**. WordPress.org still
serves the previous version and merchants are unaffected. There is no partial
state to unwind and no rollback to perform.

You do **not** need to delete the release to fix this — but you do need to move
the tag, because a published release points at a specific commit.

1. **Read the failure.** Actions → the failed run → the failing job. Reproduce
   locally with `composer install && composer test`; the suite needs no
   credentials, so it fails the same way on your machine.

2. **Decide what is actually wrong:**

   - **A real regression** — fix the code. Normal work.
   - **Intended behaviour changed** — update the test in the same commit as the
     code change, and say why in the commit message. Never weaken an assertion
     to get a release out.
   - **A version mismatch** (`version-check` failed) — the three version
     locations disagree, or they disagree with the tag. See the release
     checklist in [README.md](README.md).
   - **A stub gap** — the plugin is fine, the stub is wrong. Fix the stub, and
     note it in the commit so the next person does not treat it as coverage.

3. **Push the fix to `main`** and confirm the `Tests` workflow is green there
   before touching the release again.

4. **Re-point the release at the fixed commit.** Publishing does not re-fire on
   edit, and the deploy workflow is release-triggered only, so **delete the
   release and its tag, then publish a fresh release** on the fixed commit.
   That re-fires the workflow cleanly.

   From the command line:

   ```bash
   git push --delete origin v2.7.1     # remove the old tag
   git tag -d v2.7.1
   git tag v2.7.1 <fixed-commit>       # or just: git tag v2.7.1 (on main)
   git push origin v2.7.1
   ```

   Then re-create the release from that tag in the GitHub UI.

5. **If you must ship despite a failing test** — for example a genuine
   emergency where the failure is understood and unrelated — do it
   deliberately and visibly, never by deleting the test:

   - Preferred: fix or correctly update the test. It is usually minutes.
   - If truly unavoidable, mark the single test skipped with a reason and a
     ticket reference, ship, then revert the skip:

     ```php
     public function test_something(): void
     {
         $this->markTestSkipped('Blocked by PAYFLEX-123; re-enable before 2.7.2.');
     }
     ```

   Do not remove `needs:` from the deploy job. An ungated deploy path tends to
   stay ungated.

---

## Writing new tests

- Extend `PF_TestCase`, name the file `*Test.php` in `tests/`.
- Name tests as sentences: `test_a_declined_payment_fails_the_order`, not
  `testDecline`. The failure output is read by whoever is mid-release.
- Prefer a real behavioural assertion over a mock expectation. Assert the order
  status, the request body, the notice the shopper sees.
- **Test the code as it is, not as it should be.** When the current behaviour is
  wrong but changing it is out of scope, write a *characterisation* test with a
  `KNOWN DEFECT` docblock explaining the defect, its impact, and what to change
  when it is fixed. Those tests still catch regressions and become the fix's
  to-do list. They are indexed below.
- `phpunit.xml` sets `failOnWarning`, `failOnNotice` and `failOnDeprecation`.
  The suite is currently clean of all three — keep it that way, since a new PHP
  warning on a payment path is a real bug. Note that PHPUnit prints
  *"OK, but there were issues!"* for these while still exiting non-zero, so a
  run that looks passable in the log can still be failing CI. Check the summary
  line for `Warnings:` / `Deprecations:`.

---

## Known defects the suite documents

Found while writing these tests. Each has a characterisation test that will fail
when fixed, prompting the test to be tightened. Roughly highest impact first.

| # | Defect | Where | Test |
| --- | --- | --- | --- |
| 1 | **`process_refund()` fatals on a network error.** No `is_wp_error()` guard; the response-code line array-accesses the `WP_Error` object, which is a fatal `Error` in PHP 8. Reachable from the admin — `create_refund()` is hooked to `woocommerce_order_status_refunded`, so a timeout while marking an order refunded produces a fatal instead of the `false` WooCommerce expects. `process_payment()` guards for this. | `class-wc-gateway-payflex.php` (`$refund_response['response']['code']`) | `RefundTest::test_a_network_error_currently_raises_a_fatal_error` |
| 2 | **Settings screen reports "Connection failed" with valid credentials.** The constructor calls `init_form_fields()` before `init_settings()`, so `form_fields()` asks whether the API is reachable while `$this->settings` is still empty — no auth is attempted and the status pill reads failure. Looks correct only while a token happens to be cached (e.g. right after saving). Fix: `init_settings()` first. | `WC_Gateway_PartPay::__construct()` | `AuthenticationTest::test_connection_status_reports_failure_when_no_token_is_cached` |
| 3 | **Non-401 auth errors are treated as success.** The success branch excludes only HTTP 401, so a 500 caches and returns an empty token, later sent as `Authorization: Bearer `. Should require 2xx *and* a non-empty `access_token`. | `get_payflex_authorization_code()` | `AuthenticationTest::test_non_401_error_responses_are_treated_as_successful_auth` |
| 4 | **Workflow-status cache leaks between orders.** `set_payflex_workflow_status()` caches the value in an instance property that `get_payflex_workflow_status()` returns for *any* order id. The gateway is a singleton and the CRON sweep loops set-then-get, so after the first order every later order in the run reports the first one's status — which drives the "has this changed?" guard. Key the cache by order id, or drop it. | `get_/set_payflex_workflow_status()` | `OrderMetaTest::test_workflow_status_cache_leaks_between_orders_after_a_write` |
| 5 | **Oldest `_partpay_*` fallback returns an array.** `get_post_meta()` is called without `$single = true`, so a string is expected but an array comes back and gets concatenated into a URL. Affects only pre-2.6 non-HPOS orders. The redundant call above the correct one should be deleted. | `get_payflex_order_id()`, `get_payflex_order_token()` | `OrderMetaTest::test_the_oldest_partpay_fallback_returns_an_array_not_a_string` |
| ~~6~~ | **RESOLVED (2.7.1+).** Widget settings were neither `esc_attr()`d into the container data attributes nor encoded into the hosted script's query string, so a double quote broke out of its attribute. Both halves now fixed; the characterisation tests were inverted to assert the encoding. | `payflex_frontend_widget()` | `WidgetTest::test_widget_settings_are_escaped_in_the_container_data_attributes`, `::test_widget_settings_are_url_encoded_in_the_script_query_string` |
| 7 | **Leftover debug `error_log()` calls.** Three `Payflex: orderId2`/`orderId3` writes on every refund attempt, leaking the Payflex order id to the site error log. Remove them, or route through `$this->log()`. Also flagged by Plugin Check as 5 warnings (`WordPress.PHP.DevelopmentFunctions`: 3 × `error_log_error_log`, 2 × `error_log_print_r`) — queued as its own cleanup pass, since the `print_r` sites need checking for what they leak before removal. | `process_refund()` | `PluginIntegrityTest::test_leftover_debug_error_log_calls_are_still_present` |
| 8 | **PHP requirement advertised inconsistently.** `readme.txt` says `Requires PHP: 7.4`; the support page flags anything below 8.1 as unsupported. A merchant on 7.4 can install and is then told their PHP is unsupported. Reconcile — probably by raising `readme.txt` to 8.1. | `readme.txt`, support page | `PluginIntegrityTest::test_the_php_requirement_is_advertised_inconsistently` |

Two more observations without characterisation tests, because the behaviour they
affect is not reachable from the stubs:

- **`process_payment()` returns `null` on the re-checkout guard paths** (already
  approved, still pending, unknown status) rather than a
  `['result' => 'failure', …]` array. WooCommerce expects an array; the notice
  is shown but the surrounding behaviour is undefined. See the assertions in
  `ProcessPaymentTest::test_refuses_to_recreate_a_payment_that_payflex_already_approved`.
- **`checkOrderNotesExistsByOrderId()` cannot see the notes it checks for.** It
  reads `get_customer_order_notes()`, which returns only *customer* notes, while
  the plugin adds private notes. The duplicate-note suppression it backs works
  only because the workflow-status guard in front of it does the real work.
