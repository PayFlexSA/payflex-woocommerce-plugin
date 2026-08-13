<?php

/**
 * process_payment() — builds the order payload, calls /order/productSelect and
 * hands WooCommerce the redirect to the Payflex hosted checkout.
 *
 * The browser leg of checkout cannot be exercised here, but everything the
 * plugin itself decides (payload contents, meta written, re-checkout guards,
 * error handling) can be.
 */
final class ProcessPaymentTest extends PF_TestCase
{
    private const CREATED = [
        'orderId'     => 'PF-ORDER-1',
        'token'       => 'PF-TOKEN-1',
        'redirectUrl' => 'https://checkout.payflex.co.za/pay/PF-TOKEN-1',
    ];

    private function queueCreated(array $overrides = []): void
    {
        PF_State::queue_json(200, array_merge(self::CREATED, $overrides), '/productSelect');
    }

    /* --------------------------------------------------------------------- */

    public function test_successful_creation_redirects_to_the_payflex_checkout(): void
    {
        $gateway = $this->gateway();
        $this->order();
        $this->queueCreated();

        $this->assertSame([
            'result'   => 'success',
            'redirect' => 'https://checkout.payflex.co.za/pay/PF-TOKEN-1',
        ], $gateway->process_payment('1001'));
    }

    public function test_posts_to_the_product_select_endpoint_with_the_bearer_token(): void
    {
        $gateway = $this->gateway();
        $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');
        $request = PF_State::$http_log[0];

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.payflex.co.za/order/productSelect', $request['url']);
        $this->assertSame('Bearer cached-access-token', $request['args']['headers']['Authorization']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);
        $this->assertSame(30, $request['args']['timeout']);
    }

    public function test_sends_the_order_total_formatted_to_two_decimals(): void
    {
        $gateway = $this->gateway();
        $this->order(['total' => 1234.5]);
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertSame('1234.50', $this->requestBody()['amount']);
    }

    public function test_sends_the_billing_and_shipping_details(): void
    {
        $gateway = $this->gateway();
        $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');
        $body = $this->requestBody();

        $this->assertSame([
            'phoneNumber' => '0821234567',
            'givenNames'  => 'Test',
            'surname'     => 'Customer',
            'email'       => 'test@example.test',
        ], $body['consumer']);

        $this->assertSame([
            'addressLine1' => '1 Test Street',
            'addressLine2' => 'Unit 2',
            'suburb'       => 'Cape Town',
            'postcode'     => '8001',
        ], $body['billing']);

        $this->assertSame($body['billing'], $body['shipping']);
    }

    public function test_sends_tax_and_shipping_amounts(): void
    {
        $gateway = $this->gateway();
        $this->order(['total' => 600.0, 'total_tax' => 78.26, 'shipping_total' => 100.0]);
        $this->queueCreated();

        $gateway->process_payment('1001');
        $body = $this->requestBody();

        $this->assertEquals(78.26, $body['taxAmount']);
        $this->assertEquals(100.0, $body['shippingAmount']);
    }

    public function test_sends_line_items_with_unit_price_and_sku(): void
    {
        $gateway = $this->gateway();
        $this->order([
            'total' => 300.0,
            'items' => [new WC_Order_Item('Widget', 3, 300.00, 101, 0)],
        ]);
        $this->queueCreated();

        $gateway->process_payment('1001');
        $items = $this->requestBody()['items'];

        $this->assertCount(1, $items);
        $this->assertSame('3', $items[0]['quantity']);
        $this->assertSame('100.00', $items[0]['price'], 'Unit price is line subtotal / quantity');
        $this->assertSame('SKU-101', $items[0]['sku']);
    }

    public function test_uses_the_variation_sku_for_variable_products(): void
    {
        $gateway = $this->gateway();
        $this->order(['items' => [new WC_Order_Item('Shirt - Large', 1, 500.00, 101, 555)]]);
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertSame('SKU-555', $this->requestBody()['items'][0]['sku']);
    }

    /**
     * Zero-quantity lines would make the unit-price division a divide-by-zero,
     * so they are skipped entirely.
     */
    public function test_zero_quantity_line_items_are_skipped(): void
    {
        $gateway = $this->gateway();
        $this->order([
            'items' => [
                new WC_Order_Item('Real item', 1, 500.00, 101, 0),
                new WC_Order_Item('Zero qty', 0, 0.00, 102, 0),
            ],
        ]);
        $this->queueCreated();

        $gateway->process_payment('1001');
        $items = $this->requestBody()['items'];

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Real item', $items[0]['name']);
    }

    public function test_an_order_with_no_line_items_still_creates_a_payment(): void
    {
        $gateway = $this->gateway();
        $this->order(['items' => []]);
        $this->queueCreated();

        $result = $gateway->process_payment('1001');

        $this->assertSame('success', $result['result']);
        $this->assertSame([], $this->requestBody()['items']);
    }

    /**
     * Custom order-number plugins change get_order_number(); the merchant
     * reference must follow it so reconciliation matches the store's numbering.
     */
    public function test_merchant_reference_uses_the_order_number_not_the_id(): void
    {
        $gateway = $this->gateway();
        $this->order(['order_number' => 'INV-2026-0042']);
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertSame('INV-2026-0042', $this->requestBody()['merchantReference']);
    }

    public function test_sends_platform_diagnostics_matching_the_plugin_version(): void
    {
        $gateway = $this->gateway();
        $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');
        $info = $this->requestBody()['merchantSystemInformation'];

        $this->assertSame(PF_PluginMeta::headerVersion(), $info['plugin_version']);
        $this->assertSame(PHP_VERSION, $info['php_version']);
        $this->assertStringContainsString('WooCommerce', $info['ecommerce_platform']);
        $this->assertStringContainsString('Wordpress ' . PF_State::$wp_version, $info['ecommerce_platform']);
    }

    public function test_confirm_and_cancel_redirect_urls_point_back_at_the_store(): void
    {
        $gateway = $this->gateway();
        $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');
        $merchant = $this->requestBody()['merchant'];

        $this->assertStringContainsString('status=confirmed', $merchant['redirectConfirmUrl']);
        $this->assertStringContainsString('wc-api=WC_Gateway_PartPay', $merchant['redirectConfirmUrl']);
        $this->assertStringContainsString('order_id=1001', $merchant['redirectConfirmUrl']);
        $this->assertStringContainsString('status=cancelled', $merchant['redirectCancelUrl']);
    }

    /**
     * Payflex validates the redirect URLs as absolute URIs and rejects the whole
     * request with CRM006 if either is relative, so a site whose home_url() has
     * been made root-relative (WP_HOME = '/', or a portability plugin) must still
     * send a fully-qualified URL.
     */
    public function test_redirect_urls_are_absolute_even_when_home_url_is_relative(): void
    {
        PF_State::$home_url = '/';
        PF_State::$site_url = '/';

        $gateway = $this->gateway();
        $this->order();
        $this->queueCreated();
        $_SERVER['HTTP_HOST'] = 'relative.example.test';

        $gateway->process_payment('1001');
        $merchant = $this->requestBody()['merchant'];

        $this->assertStringStartsWith('https://relative.example.test/', $merchant['redirectConfirmUrl']);
        $this->assertStringStartsWith('https://relative.example.test/', $merchant['redirectCancelUrl']);
        $this->assertStringContainsString('order-received/1001/?key=', $merchant['redirectConfirmUrl']);
        $this->assertStringContainsString('status=confirmed', $merchant['redirectConfirmUrl']);
        $this->assertLogged('was not absolute');
    }

    public function test_a_relative_home_url_falls_back_to_site_url_for_the_host(): void
    {
        PF_State::$home_url = '/';
        PF_State::$site_url = 'https://canonical.example.test/';

        $gateway = $this->gateway();
        $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertStringStartsWith(
            'https://canonical.example.test/',
            $this->requestBody()['merchant']['redirectConfirmUrl']
        );
    }

    public function test_an_already_absolute_return_url_is_left_untouched(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertStringStartsWith(
            $order->get_checkout_order_received_url(),
            $this->requestBody()['merchant']['redirectConfirmUrl']
        );
    }

    public function test_a_protocol_relative_url_gets_an_explicit_scheme(): void
    {
        $gateway = $this->gateway();

        $this->assertSame('https://example.test/order-received/1/', $gateway->make_url_absolute('//example.test/order-received/1/'));

        PF_State::$is_ssl = false;
        $this->assertSame('http://example.test/order-received/1/', $gateway->make_url_absolute('//example.test/order-received/1/'));
    }

    /* --------------------------------------------------------------------- */

    public function test_stores_the_payflex_identifiers_and_environment_on_the_order(): void
    {
        $gateway = $this->gateway(['testmode' => 'develop']);
        $order   = $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertSame('PF-ORDER-1', $order->get_meta('_payflex_order_id'));
        $this->assertSame('PF-TOKEN-1', $order->get_meta('_payflex_order_token'));
        $this->assertSame(self::CREATED['redirectUrl'], $order->get_meta('_order_redirectURL'));
        $this->assertSame('develop', $order->get_meta('_payflex_environment'));
    }

    public function test_marks_the_workflow_as_initiated_so_the_cron_sweep_picks_it_up(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertSame('initiated', $order->get_meta('_payflex_workflow_status'));
    }

    public function test_adds_an_order_note_naming_the_transaction_id(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');
        $notes = array_column($order->pf_data()->notes, 'content');

        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('PF-ORDER-1', implode("\n", $notes));
        $this->assertStringContainsString('User attempted Payflex order', implode("\n", $notes));
    }

    public function test_sandbox_mode_is_called_out_in_the_order_note(): void
    {
        $gateway = $this->gateway(['testmode' => 'develop']);
        $order   = $this->order();
        $this->queueCreated();

        $gateway->process_payment('1001');

        $this->assertStringContainsString(
            'Sandbox Mode',
            implode("\n", array_column($order->pf_data()->notes, 'content'))
        );
    }

    /* --------------------------------------------------------------------- */

    public function test_connection_failure_returns_failure_and_warns_the_shopper(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();
        PF_State::queue_response(new WP_Error('http_request_failed', 'Connection timed out'), '/productSelect');

        $result = $gateway->process_payment('1001');

        $this->assertSame('failure', $result['result']);
        $this->assertSame($order->get_checkout_payment_url(true), $result['redirect']);
        $this->assertNotice('Sorry, there was a problem preparing your payment');
        $this->assertLogged('Connection timed out');
    }

    public function test_connection_failure_in_debug_mode_names_payflex_in_the_notice(): void
    {
        $gateway = $this->gateway(['payflex_debug' => 'yes']);
        $this->order();
        PF_State::queue_response(new WP_Error('http_request_failed', 'Connection timed out'), '/productSelect');

        $gateway->process_payment('1001');

        $this->assertNotice('There was an issue connecting to Payflex servers');
    }

    public function test_a_non_json_response_returns_failure(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_json(200, '<html>maintenance</html>', '/productSelect');
        $this->order();

        $this->assertSame('failure', $gateway->process_payment('1001')['result']);
        $this->assertLogged('not a valid object');
    }

    public function test_a_response_without_a_redirect_url_returns_failure(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_json(200, ['message' => 'Merchant not configured'], '/productSelect');
        $this->order();

        $result = $gateway->process_payment('1001');

        $this->assertSame('failure', $result['result']);
        $this->assertLogged('Merchant not configured');
    }

    public function test_a_response_missing_only_the_token_returns_failure(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_json(200, [
            'orderId'     => 'PF-1',
            'redirectUrl' => 'https://checkout.payflex.co.za/pay/x',
        ], '/productSelect');
        $this->order();

        $this->assertSame('failure', $gateway->process_payment('1001')['result']);
    }

    public function test_no_order_meta_is_written_when_creation_fails(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();
        PF_State::queue_json(200, ['message' => 'nope'], '/productSelect');

        $gateway->process_payment('1001');

        $this->assertSame('', $order->get_meta('_payflex_order_id'));
        $this->assertSame('', $order->get_meta('_payflex_workflow_status'));
    }

    /* --------------------------------------------------------------------- */

    /**
     * Re-checkout guard: an order already approved on Payflex must never be
     * charged a second time.
     */
    public function test_refuses_to_recreate_a_payment_that_payflex_already_approved(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order(['meta' => ['_payflex_order_id' => 'PF-EXISTING']]);
        PF_State::queue_json(200, ['orderStatus' => 'Approved', 'orderId' => 'PF-EXISTING'], '/order/PF-EXISTING');

        $result = $gateway->process_payment('1001');

        $this->assertNull($result);
        $this->assertNotice('already been approved by Payflex');
        $this->assertSame('approved', $order->get_meta('_payflex_workflow_status'));
        $this->assertSame([], array_filter(PF_State::requested_urls(), fn($u) => str_contains($u, 'productSelect')));
    }

    public function test_refuses_to_recreate_a_payment_still_pending_on_payflex(): void
    {
        $gateway = $this->gateway();
        $this->order(['meta' => ['_payflex_order_id' => 'PF-EXISTING']]);
        PF_State::queue_json(200, ['orderStatus' => 'Created', 'orderId' => 'PF-EXISTING'], '/order/PF-EXISTING');

        $this->assertNull($gateway->process_payment('1001'));
        $this->assertNotice('awaiting approval from Payflex');
    }

    public function test_refuses_to_proceed_when_the_existing_transaction_cannot_be_verified(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order(['meta' => ['_payflex_order_id' => 'PF-EXISTING']]);
        PF_State::queue_response(new WP_Error('http_request_failed', 'down'), '/order/PF-EXISTING');

        $this->assertNull($gateway->process_payment('1001'));
        $this->assertNotice('Unable to verify your existing Payflex transaction');
        $this->assertStringContainsString(
            'Unable to verify the status of existing transaction',
            implode("\n", array_column($order->pf_data()->notes, 'content'))
        );
    }

    public function test_an_unknown_remote_status_blocks_a_new_payment(): void
    {
        $gateway = $this->gateway();
        $this->order(['meta' => ['_payflex_order_id' => 'PF-EXISTING']]);
        PF_State::queue_json(200, ['orderStatus' => 'SomethingNew', 'orderId' => 'PF-EXISTING'], '/order/PF-EXISTING');

        $this->assertNull($gateway->process_payment('1001'));
        $this->assertNotice('awaiting approval from Payflex');
    }

    /**
     * A declined or abandoned attempt should not lock the shopper out — the
     * stale identifiers are cleared and a fresh Payflex order is created.
     */
    public function test_a_declined_previous_attempt_allows_a_fresh_payment(): void
    {
        foreach (['Declined', 'Abandoned', 'Cancelled'] as $status) {
            $this->setUp();

            $gateway = $this->gateway();
            $order   = $this->order([
                'meta' => ['_payflex_order_id' => 'PF-OLD', '_payflex_order_token' => 'tok-old'],
            ]);
            PF_State::queue_json(200, ['orderStatus' => $status, 'orderId' => 'PF-OLD'], '/order/PF-OLD');
            $this->queueCreated();

            $result = $gateway->process_payment('1001');

            $this->assertSame('success', $result['result'], "$status should allow a retry");
            $this->assertSame('PF-ORDER-1', $order->get_meta('_payflex_order_id'));
            $this->assertSame('PF-TOKEN-1', $order->get_meta('_payflex_order_token'));
        }
    }
}
