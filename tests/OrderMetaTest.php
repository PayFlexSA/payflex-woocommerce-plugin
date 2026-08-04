<?php

/**
 * Reading and writing the Payflex identifiers on an order, including the
 * fallbacks that keep orders created by pre-2.6 versions working.
 */
final class OrderMetaTest extends PF_TestCase
{
    public function test_reads_the_order_id_from_hpos_order_meta(): void
    {
        $gateway = $this->gateway();
        $this->order(['meta' => ['_payflex_order_id' => 'PF-123']]);

        $this->assertSame('PF-123', $gateway->get_payflex_order_id('1001'));
    }

    public function test_reads_the_order_token_from_hpos_order_meta(): void
    {
        $gateway = $this->gateway();
        $this->order(['meta' => ['_payflex_order_token' => 'tok-abc']]);

        $this->assertSame('tok-abc', $gateway->get_payflex_order_token('1001'));
    }

    public function test_falls_back_to_payflex_post_meta_for_pre_hpos_orders(): void
    {
        $gateway = $this->gateway();
        $this->order();

        update_post_meta('1001', '_payflex_order_id', 'PF-legacy');
        update_post_meta('1001', '_payflex_order_token', 'tok-legacy');

        $this->assertSame('PF-legacy', $gateway->get_payflex_order_id('1001'));
        $this->assertSame('tok-legacy', $gateway->get_payflex_order_token('1001'));
    }

    public function test_returns_false_when_no_identifier_is_stored_anywhere(): void
    {
        $gateway = $this->gateway();
        $this->order();

        $this->assertFalse($gateway->get_payflex_order_id('1001'));
        $this->assertFalse($gateway->get_payflex_order_token('1001'));
    }

    public function test_order_meta_wins_over_post_meta(): void
    {
        $gateway = $this->gateway();
        $this->order(['meta' => ['_payflex_order_id' => 'PF-current']]);
        update_post_meta('1001', '_payflex_order_id', 'PF-old');

        $this->assertSame('PF-current', $gateway->get_payflex_order_id('1001'));
    }

    /**
     * KNOWN DEFECT — characterisation test, not an endorsement.
     *
     * The oldest fallback calls get_post_meta() without $single = true, so it
     * returns an *array* of values rather than a string, and that array is
     * returned to the caller. Callers concatenate the result into a URL
     * ("$orderurl/$payflex_order_id"), which yields ".../Array" plus an
     * "Array to string conversion" warning.
     *
     * Only affects orders whose Payflex ID was stored solely under the very old
     * _partpay_order_id key (pre-2.6, non-HPOS). The redundant no-$single call
     * on the line above the correct one should be removed.
     */
    public function test_the_oldest_partpay_fallback_returns_an_array_not_a_string(): void
    {
        $gateway = $this->gateway();
        $this->order();

        update_post_meta('1001', '_partpay_order_id', 'PP-ancient');
        update_post_meta('1001', '_partpay_order_token', 'tok-ancient');

        $this->assertSame(['PP-ancient'], $gateway->get_payflex_order_id('1001'));
        $this->assertSame(['tok-ancient'], $gateway->get_payflex_order_token('1001'));
    }

    /* --------------------------------------------------------------------- */

    public function test_workflow_status_is_persisted_to_order_meta(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();

        $this->assertTrue($gateway->set_payflex_workflow_status('1001', 'initiated'));
        $this->assertSame('initiated', $order->get_meta('_payflex_workflow_status'));
    }

    public function test_workflow_status_reads_back_from_a_fresh_gateway(): void
    {
        $this->order(['meta' => ['_payflex_workflow_status' => 'completed']]);

        $this->assertSame('completed', $this->gateway()->get_payflex_workflow_status('1001'));
    }

    public function test_workflow_status_is_false_when_never_set(): void
    {
        $gateway = $this->gateway();
        $this->order();

        $this->assertFalse($gateway->get_payflex_workflow_status('1001'));
    }

    public function test_workflow_status_can_be_advanced(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();

        foreach (['initiated', 'completed'] as $status) {
            $gateway->set_payflex_workflow_status('1001', $status);
            $this->assertSame($status, $order->get_meta('_payflex_workflow_status'));
        }
    }

    /**
     * KNOWN DEFECT — characterisation test, not an endorsement.
     *
     * set_payflex_workflow_status() stores the status in an instance property,
     * and get_payflex_workflow_status() returns that property — for *any* order
     * id — whenever it is set, ignoring its argument.
     *
     * The gateway is a long-lived singleton, and check_pending_abandoned_orders()
     * loops over orders calling set() then get(). So after the first order in a
     * CRON run is written, every later order in that run reports the first
     * order's status. That drives the "has the workflow changed?" guard, so
     * notes and status transitions can be skipped for orders that needed them.
     *
     * The cache should be keyed by order id, or dropped.
     */
    public function test_workflow_status_cache_leaks_between_orders_after_a_write(): void
    {
        $gateway = $this->gateway();

        $this->order(['id' => '2001']);
        $this->order(['id' => '2002', 'meta' => ['_payflex_workflow_status' => 'initiated']]);

        // Reading alone is safe — the cache is only populated by a write.
        $this->assertSame('initiated', $gateway->get_payflex_workflow_status('2002'));

        $gateway->set_payflex_workflow_status('2001', 'completed');

        // 2002 is still 'initiated' in the database, but not according to the accessor.
        $this->assertSame('initiated', wc_get_order('2002')->get_meta('_payflex_workflow_status'));
        $this->assertSame('completed', $gateway->get_payflex_workflow_status('2002'));
    }

    /* --------------------------------------------------------------------- */

    public function test_get_order_returns_false_for_a_falsy_id(): void
    {
        $gateway = $this->gateway();

        $this->assertFalse($gateway->get_order(0));
        $this->assertFalse($gateway->get_order(''));
        $this->assertFalse($gateway->get_order(false));
    }

    public function test_get_order_caches_the_order_object_per_id(): void
    {
        $gateway = $this->gateway();
        $this->order(['id' => '3001']);
        $this->order(['id' => '3002']);

        $first = $gateway->get_order('3001');

        $this->assertSame($first, $gateway->get_order('3001'), 'Same id should reuse the cached object');
        $this->assertNotSame($first, $gateway->get_order('3002'), 'A different id must not return the cached object');
    }

    public function test_get_order_can_be_forced_to_reload(): void
    {
        $gateway = $this->gateway();
        $this->order(['id' => '3001']);

        $first = $gateway->get_order('3001');

        $this->assertNotSame($first, $gateway->get_order('3001', true));
    }

    /* --------------------------------------------------------------------- */

    public function test_can_refund_order_requires_a_transaction_id(): void
    {
        $gateway = $this->gateway();

        $without = $this->order(['id' => '4001']);
        $this->assertFalse((bool) $gateway->can_refund_order($without));

        $with = $this->order(['id' => '4002', 'transaction_id' => 'PF-999']);
        $this->assertTrue((bool) $gateway->can_refund_order($with));
    }

    public function test_gateway_declares_support_for_products_and_refunds(): void
    {
        $gateway = $this->gateway();

        $this->assertTrue($gateway->supports('products'));
        $this->assertTrue($gateway->supports('refunds'));
        $this->assertFalse($gateway->supports('subscriptions'));
    }

    public function test_gateway_id_and_availability_track_the_enabled_setting(): void
    {
        $this->assertSame('payflex', $this->gateway(['enabled' => 'yes'])->id);
        $this->assertTrue($this->gateway(['enabled' => 'yes'])->is_available());
        $this->assertFalse($this->gateway(['enabled' => 'no'])->is_available());
    }

    public function test_plugin_url_and_dir_helpers_resolve_against_the_plugin_root(): void
    {
        $gateway = $this->gateway();

        $this->assertSame(PAYFLEX_PLUGIN_URL . 'Checkout.png', $gateway->plugin_url('Checkout.png'));
        $this->assertSame(PAYFLEX_PLUGIN_URL . 'Checkout.png', $gateway->plugin_url('/Checkout.png'));
        $this->assertSame(PAYFLEX_PLUGIN_DIR . 'config/config.php', $gateway->plugin_dir('config/config.php'));
        $this->assertFileExists($gateway->plugin_dir('config/config.php'));
    }

    public function test_debug_mode_reflects_the_setting(): void
    {
        $this->assertFalse($this->gateway(['payflex_debug' => 'no'])->get_debug_mode());
        $this->assertTrue($this->gateway(['payflex_debug' => 'yes'])->get_debug_mode());
    }

    public function test_instance_returns_a_singleton(): void
    {
        $this->set_settings();

        $this->assertSame(WC_Gateway_PartPay::instance(), WC_Gateway_PartPay::instance());
    }

    public function test_reports_woocommerce_logging_status(): void
    {
        $gateway = $this->gateway();

        \Automattic\WooCommerce\Internal\Admin\Logging\Settings::$logging_enabled = true;
        $this->assertTrue($gateway->get_wc_logging_status());

        \Automattic\WooCommerce\Internal\Admin\Logging\Settings::$logging_enabled = false;
        $this->assertFalse($gateway->get_wc_logging_status());
    }
}
