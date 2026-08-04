<?php

/**
 * The two-minute CRON sweep is the safety net for shoppers who never make it
 * back from the Payflex checkout: it reconciles order status against the API.
 */
final class CronTest extends PF_TestCase
{
    private const MINUTE = 60;

    /**
     * An order that started Payflex checkout $minutes_ago and is still waiting.
     */
    private function initiatedOrder(int $minutes_ago, array $props = []): WC_Order
    {
        static $sequence = 0;
        $sequence++;

        return $this->order(array_merge([
            'id'           => (string) (5000 + $sequence),
            'date_created' => time() - ($minutes_ago * self::MINUTE),
            'meta'         => [
                '_payflex_order_id'        => 'PF-' . (5000 + $sequence),
                '_payflex_order_token'     => 'tok-' . (5000 + $sequence),
                '_payflex_workflow_status' => 'initiated',
            ],
        ], $props));
    }

    private function notes(WC_Order $order): string
    {
        return implode("\n", array_column($order->pf_data()->notes, 'content'));
    }

    /* --------------------------------------------------------------------- */

    /**
     * Orders under 30 minutes old are "new" — the shopper may still be paying,
     * so they are not reconciled yet.
     */
    public function test_recent_orders_are_queued_as_new_not_scheduled(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(5);

        $new = $gateway->get_pending_abandoned_orders('new');
        $scheduled = $gateway->get_pending_abandoned_orders('scheduled');

        $this->assertSame([$order->get_id()], array_map(fn($o) => $o->get_id(), $new));
        $this->assertSame([], $scheduled);
    }

    public function test_orders_between_thirty_minutes_and_two_hours_old_are_scheduled(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);

        $this->assertSame([], $gateway->get_pending_abandoned_orders('new'));
        $this->assertSame(
            [$order->get_id()],
            array_map(fn($o) => $o->get_id(), $gateway->get_pending_abandoned_orders('scheduled'))
        );
    }

    public function test_orders_older_than_two_hours_are_no_longer_checked(): void
    {
        $gateway = $this->gateway();
        $this->initiatedOrder(150);

        $this->assertSame([], $gateway->get_pending_abandoned_orders('new'));
        $this->assertSame([], $gateway->get_pending_abandoned_orders('scheduled'));
    }

    public function test_orders_that_are_not_initiated_are_ignored(): void
    {
        $gateway = $this->gateway();
        $this->initiatedOrder(45, ['meta' => ['_payflex_workflow_status' => 'completed']]);

        $this->assertSame([], $gateway->get_pending_abandoned_orders('scheduled'));
    }

    public function test_calling_without_an_argument_returns_both_queues(): void
    {
        $gateway = $this->gateway();
        $this->initiatedOrder(5);
        $this->initiatedOrder(45);

        $queues = $gateway->get_pending_abandoned_orders();

        $this->assertSame(['new', 'scheduled'], array_keys($queues));
        $this->assertCount(1, $queues['new']);
        $this->assertCount(1, $queues['scheduled']);
    }

    /**
     * "all" backs the Force Check button on the support page — every unfinished
     * Payflex order from the last two hours regardless of workflow state.
     */
    public function test_all_returns_unfinished_payflex_orders_from_the_last_two_hours(): void
    {
        $gateway = $this->gateway();
        $this->initiatedOrder(10, ['status' => 'pending']);
        $this->initiatedOrder(10, ['status' => 'failed']);
        $this->initiatedOrder(10, ['status' => 'cancelled']);
        $this->initiatedOrder(10, ['status' => 'processing']);

        $this->assertCount(3, $gateway->get_pending_abandoned_orders('all'));
    }

    public function test_orders_paid_with_another_gateway_are_never_returned(): void
    {
        $gateway = $this->gateway();
        $this->initiatedOrder(45, ['payment_method' => 'stripe']);

        $this->assertSame([], $gateway->get_pending_abandoned_orders('scheduled'));
        $this->assertSame([], $gateway->get_pending_abandoned_orders('all'));
    }

    /* --------------------------------------------------------------------- */

    public function test_an_approved_order_is_completed_by_the_sweep(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');

        $gateway->check_pending_abandoned_orders();

        $this->assertTrue($order->pf_data()->payment_completed);
        $this->assertSame('processing', $order->get_status());
        $this->assertSame('completed', $order->get_meta('_payflex_workflow_status'));
        $this->assertStringContainsString('Payment approved via CRON', $this->notes($order));
    }

    public function test_an_order_still_pending_approval_is_noted_but_left_alone(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(200, ['orderStatus' => 'Created'], '/order/');

        $gateway->check_pending_abandoned_orders();

        $this->assertSame('pending', $order->get_status());
        $this->assertFalse($order->pf_data()->payment_completed);
        $this->assertSame('Created_cron_checked', $order->get_meta('_payflex_workflow_status'));
        $this->assertStringContainsString('Still pending approval', $this->notes($order));
    }

    public function test_a_declined_order_is_cancelled(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(200, ['orderStatus' => 'Declined'], '/order/');

        $gateway->check_pending_abandoned_orders();

        $this->assertSame('cancelled', $order->get_status());
        $this->assertSame('Declined_cron_checked', $order->get_meta('_payflex_workflow_status'));
        $this->assertStringContainsString('Order Declined', $this->notes($order));
    }

    public function test_an_abandoned_order_is_cancelled(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(200, ['orderStatus' => 'Abandoned'], '/order/');

        $gateway->check_pending_abandoned_orders();

        $this->assertSame('cancelled', $order->get_status());
        $this->assertSame('Abandoned_cron_checked', $order->get_meta('_payflex_workflow_status'));
    }

    /**
     * "Initiated" means the shopper has not reached Payflex yet, so the order is
     * left completely untouched.
     */
    public function test_an_initiated_remote_order_is_skipped(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(200, ['orderStatus' => 'Initiated'], '/order/');

        $gateway->check_pending_abandoned_orders();

        $this->assertSame('pending', $order->get_status());
        $this->assertSame('initiated', $order->get_meta('_payflex_workflow_status'));
        $this->assertSame('', $this->notes($order));
        $this->assertLogged('Intiated by customer but not logged in with Payflex');
    }

    public function test_an_unreachable_api_leaves_the_order_untouched(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(503, ['message' => 'Service unavailable'], '/order/');

        $gateway->check_pending_abandoned_orders();

        $this->assertSame('pending', $order->get_status());
        $this->assertSame('initiated', $order->get_meta('_payflex_workflow_status'), 'The order must stay in the queue');
        $this->assertSame('', $this->notes($order));
    }

    public function test_an_order_without_a_payflex_id_is_logged_and_skipped(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45, ['meta' => ['_payflex_workflow_status' => 'initiated']]);

        $gateway->check_pending_abandoned_orders();

        $this->assertLogged('No Payflex OrderId for Order ' . $order->get_id());
        $this->assertSame([], PF_State::$http_log);
    }

    /**
     * The sweep runs every two minutes for up to two hours, so a repeated
     * status must not append the same note over and over.
     */
    public function test_repeated_sweeps_do_not_add_duplicate_notes(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(200, ['orderStatus' => 'Created'], '/order/');

        $gateway->check_pending_abandoned_orders();
        $first_pass = count($order->pf_data()->notes);

        $gateway->check_pending_abandoned_orders();

        $this->assertSame($first_pass, count($order->pf_data()->notes));
    }

    public function test_an_already_completed_order_is_not_completed_again(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(45);
        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');

        $gateway->check_pending_abandoned_orders();
        $order->pf_data()->payment_completed = false;

        // The order has left the 'initiated' queue, so a second sweep skips it.
        $gateway->check_pending_abandoned_orders();

        $this->assertFalse($order->pf_data()->payment_completed);
    }

    public function test_the_sweep_logs_how_many_orders_it_checked(): void
    {
        $gateway = $this->gateway();
        $this->initiatedOrder(45);
        $this->initiatedOrder(50);
        PF_State::stub_json(200, ['orderStatus' => 'Created'], '/order/');

        $gateway->check_pending_abandoned_orders();

        $this->assertLogged('Checking 2 orders');
        $this->assertLogged('Order CRON Finished');
    }

    public function test_the_sweep_is_quiet_when_there_is_nothing_to_do(): void
    {
        $gateway = $this->gateway();

        $gateway->check_pending_abandoned_orders();

        $this->assertStringNotContainsString('No orders to check', PF_State::log_text());
    }

    public function test_debug_mode_reports_an_empty_sweep(): void
    {
        $gateway = $this->gateway(['payflex_debug' => 'yes']);

        $gateway->check_pending_abandoned_orders();

        $this->assertLogged('Order CRON running');
        $this->assertLogged('No orders to check');
    }

    /**
     * The support page's Force Check bypasses the 30-minute wait.
     */
    public function test_force_check_includes_orders_that_are_not_yet_scheduled(): void
    {
        $gateway = $this->gateway();
        $order   = $this->initiatedOrder(2);
        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');

        $gateway->check_pending_abandoned_orders();
        $this->assertFalse($order->pf_data()->payment_completed, 'Not due yet on a normal sweep');

        $gateway->check_pending_abandoned_orders(true);
        $this->assertTrue($order->pf_data()->payment_completed);
    }

    /* --------------------------------------------------------------------- */

    public function test_the_cron_hook_is_registered_on_a_two_minute_schedule(): void
    {
        $schedules = apply_filters('cron_schedules', []);

        $this->assertArrayHasKey('twominutes', $schedules);
        $this->assertSame(120, $schedules['twominutes']['interval']);
    }

    public function test_the_init_hook_schedules_the_cron_job_when_it_is_not_yet_scheduled(): void
    {
        $this->set_settings();

        do_action('init');

        $this->assertContains('payflex_do_cron_jobs', array_column(PF_State::$schedule_calls, 'hook'));
        $this->assertSame('twominutes', PF_State::$schedule_calls[0]['recurrence']);
    }

    public function test_the_init_hook_does_not_reschedule_an_existing_cron_job(): void
    {
        $this->set_settings();
        PF_State::$scheduled['payflex_do_cron_jobs'] = time() + 60;

        do_action('init');

        $this->assertSame([], PF_State::$schedule_calls);
    }

    /**
     * The hook was renamed from partpay_* to payflex_* — the old one is cleared
     * so it cannot keep firing against a handler that no longer exists.
     */
    public function test_the_legacy_partpay_cron_hook_is_cleared(): void
    {
        $this->set_settings();
        PF_State::$scheduled['partpay_do_cron_jobs'] = time() + 60;

        do_action('init');

        $this->assertContains('partpay_do_cron_jobs', PF_State::$cleared_hooks);
    }

    public function test_the_cron_job_is_not_scheduled_when_the_plugin_is_inactive(): void
    {
        $this->set_settings();
        PF_State::$active_plugins = ['woocommerce/woocommerce.php'];

        do_action('init');

        $this->assertSame([], PF_State::$schedule_calls);
    }

    /**
     * Running the sweep during checkout would slow the shopper's page load, so
     * the handler bails out there.
     */
    public function test_the_cron_handler_does_nothing_on_the_checkout_page(): void
    {
        $this->gateway();
        $this->initiatedOrder(45);
        PF_State::$is_checkout = true;
        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');

        do_action('payflex_do_cron_jobs');

        $this->assertSame([], PF_State::$http_log);
    }

    public function test_the_cron_handler_runs_the_sweep_off_the_checkout_page(): void
    {
        $this->gateway();
        $order = $this->initiatedOrder(45);
        PF_State::$is_checkout = false;
        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');

        do_action('payflex_do_cron_jobs');

        $this->assertTrue($order->pf_data()->payment_completed);
    }

    public function test_activation_schedules_and_deactivation_clears_the_cron_job(): void
    {
        payflex_create_wpcronjob();
        $this->assertContains('payflex_do_cron_jobs', array_column(PF_State::$schedule_calls, 'hook'));

        payflex_delete_wpcronjob();
        $this->assertContains('payflex_do_cron_jobs', PF_State::$cleared_hooks);
    }

    public function test_activation_does_not_double_schedule(): void
    {
        PF_State::$scheduled['payflex_do_cron_jobs'] = time() + 60;

        payflex_create_wpcronjob();

        $this->assertSame([], PF_State::$schedule_calls);
    }
}
