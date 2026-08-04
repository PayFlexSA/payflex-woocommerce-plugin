<?php

/**
 * payment_callback() runs when Payflex redirects the shopper back to the store.
 * It re-checks the order against the API before touching order status, so a
 * tampered return URL cannot mark an order paid.
 */
final class PaymentCallbackTest extends PF_TestCase
{
    /**
     * Arrange a valid return: order with Payflex meta, matching remote record,
     * and the query string Payflex sends back.
     */
    private function arriveFromPayflex(string $status, array $remote = [], array $order_props = []): WC_Gateway_PartPay
    {
        $gateway = $this->gateway();

        $order = $this->order(array_merge([
            'meta' => [
                '_payflex_order_id'    => 'PF-ORDER-1',
                '_payflex_order_token' => 'PF-TOKEN-1',
            ],
        ], $order_props));

        PF_State::queue_json(200, array_merge([
            'orderStatus' => $status,
            'token'       => 'PF-TOKEN-1',
            'amount'      => $order->get_total(),
            'orderId'     => 'PF-ORDER-1',
        ], $remote), '/order/PF-ORDER-1');

        $_COOKIE = ['woocommerce_items_in_cart' => '1'];
        $_GET    = ['order_id' => '1001', 'status' => 'confirmed', 'token' => 'PF-TOKEN-1'];

        return $gateway;
    }

    /* --------------------------------------------------------------------- */

    /**
     * Link prefetchers and cookie-less clients hit this endpoint without a
     * session; completing an order for them would fire order emails early.
     */
    public function test_bails_out_silently_when_the_request_has_no_cookies(): void
    {
        $gateway = $this->gateway();
        $_COOKIE = [];

        $this->assertNull($gateway->payment_callback('1001'));
        $this->assertLogged('No cookies detected');
        $this->assertSame([], PF_State::$redirects);
    }

    public function test_redirects_without_touching_the_order_when_query_parameters_are_missing(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();
        $_COOKIE = ['woocommerce_items_in_cart' => '1'];
        $_GET    = ['order_id' => '1001'];

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertLogged('Invalid callback data received');
        $this->assertSame('pending', $order->get_status());
    }

    public function test_redirects_when_the_order_id_is_empty(): void
    {
        $gateway = $this->gateway();
        $this->order();
        $_COOKIE = ['woocommerce_items_in_cart' => '1'];
        $_GET    = ['order_id' => '', 'status' => 'confirmed', 'token' => 'PF-TOKEN-1'];

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertLogged('Invalid callback data received');
    }

    /* --------------------------------------------------------------------- */

    public function test_an_approved_payment_completes_the_order(): void
    {
        $gateway = $this->arriveFromPayflex('Approved');
        $order   = wc_get_order('1001');

        $redirect = $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertTrue($order->pf_data()->payment_completed);
        $this->assertSame('processing', $order->get_status());
        $this->assertSame('PF-ORDER-1', $order->get_transaction_id());
        $this->assertSame('completed', $order->get_meta('_payflex_workflow_status'));
        $this->assertStringContainsString('order-received', $redirect);
    }

    public function test_an_approved_payment_empties_the_cart(): void
    {
        $gateway = $this->arriveFromPayflex('Approved');
        PF_State::$cart_total = 500.0;

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertTrue(PF_State::$cart_emptied);
    }

    public function test_an_approved_payment_adds_a_note_with_the_transaction_id(): void
    {
        $gateway = $this->arriveFromPayflex('Approved');
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));
        $notes = implode("\n", array_column($order->pf_data()->notes, 'content'));

        $this->assertStringContainsString('Payment approved', $notes);
        $this->assertStringContainsString('PF-ORDER-1', $notes);
    }

    /**
     * Shoppers refresh the thank-you page. The second visit must not complete
     * the payment again.
     */
    public function test_returning_twice_does_not_complete_the_payment_twice(): void
    {
        $gateway = $this->arriveFromPayflex('Approved', [], [
            'meta' => [
                '_payflex_order_id'        => 'PF-ORDER-1',
                '_payflex_order_token'     => 'PF-TOKEN-1',
                '_payflex_workflow_status' => 'completed',
            ],
        ]);
        $order = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));
        $notes = implode("\n", array_column($order->pf_data()->notes, 'content'));

        $this->assertFalse($order->pf_data()->payment_completed);
        $this->assertStringContainsString('Payment already completed, user returned more than once', $notes);
    }

    public function test_an_already_processing_order_is_left_alone(): void
    {
        $gateway = $this->arriveFromPayflex('Approved', [], ['status' => 'processing']);
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertFalse($order->pf_data()->payment_completed);
        $this->assertSame('processing', $order->get_status());
    }

    /* --------------------------------------------------------------------- */

    public function test_a_declined_payment_fails_the_order(): void
    {
        $gateway = $this->arriveFromPayflex('Declined');
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertSame('failed', $order->get_status());
        $this->assertSame('failed', $order->get_meta('_payflex_workflow_status'));
        $this->assertStringContainsString(
            'Payment declined',
            implode("\n", array_column($order->pf_data()->notes, 'content'))
        );
    }

    public function test_an_abandoned_payment_fails_the_order(): void
    {
        $gateway = $this->arriveFromPayflex('Abandoned');
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertSame('failed', $order->get_status());
        $this->assertSame('abandoned', $order->get_meta('_payflex_workflow_status'));
    }

    public function test_an_unrecognised_remote_status_leaves_the_order_pending(): void
    {
        $gateway = $this->arriveFromPayflex('SomeNewStatus');
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertSame('pending', $order->get_status());
        $this->assertFalse($order->pf_data()->payment_completed);
        $this->assertLogged('Payflex redirect unknown');
    }

    /* --------------------------------------------------------------------- */

    /**
     * The security property that matters: approval is taken from the API
     * response, never from the returned query string.
     */
    public function test_a_forged_approved_status_in_the_url_cannot_complete_an_order(): void
    {
        $gateway = $this->arriveFromPayflex('Declined');
        $order   = wc_get_order('1001');
        $_GET['status'] = 'Approved';

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertFalse($order->pf_data()->payment_completed);
        $this->assertSame('failed', $order->get_status());
    }

    public function test_a_remote_token_mismatch_aborts_before_the_order_is_touched(): void
    {
        $gateway = $this->arriveFromPayflex('Approved', ['token' => 'SOMEONE-ELSES-TOKEN']);
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertFalse($order->pf_data()->payment_completed);
        $this->assertSame('pending', $order->get_status());
    }

    /**
     * Guards against the order total being changed after the Payflex order was
     * created — the amounts must still agree at the point of completion.
     */
    public function test_an_amount_mismatch_aborts_before_the_order_is_touched(): void
    {
        $gateway = $this->arriveFromPayflex('Approved', ['amount' => 5.00]);
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertFalse($order->pf_data()->payment_completed);
        $this->assertSame('pending', $order->get_status());
    }

    public function test_a_missing_remote_record_aborts(): void
    {
        $gateway = $this->arriveFromPayflex('Approved');
        PF_State::$http_queue = [];
        PF_State::queue_json(404, ['message' => 'Not found'], '/order/PF-ORDER-1');

        $order = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertFalse($order->pf_data()->payment_completed);
    }

    public function test_an_order_with_no_stored_payflex_id_aborts(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();
        $_COOKIE = ['woocommerce_items_in_cart' => '1'];
        $_GET    = ['order_id' => '1001', 'status' => 'confirmed', 'token' => 'anything'];

        $this->captureRedirect(fn() => $gateway->payment_callback('1001'));

        $this->assertFalse($order->pf_data()->payment_completed);
    }

    /* --------------------------------------------------------------------- */

    public function test_remote_status_check_returns_the_status_for_a_valid_order(): void
    {
        $gateway = $this->arriveFromPayflex('Approved');

        $this->assertSame('Approved', $gateway->payflex_remote_check_order_status('1001'));
    }

    public function test_remote_status_check_returns_false_when_fields_are_missing(): void
    {
        $gateway = $this->gateway();
        $this->order(['meta' => ['_payflex_order_id' => 'PF-1', '_payflex_order_token' => 'tok']]);

        PF_State::queue_json(200, ['token' => 'tok'], '/order/PF-1');

        $this->assertFalse($gateway->payflex_remote_check_order_status('1001'));
    }

    public function test_remote_get_order_returns_the_decoded_order(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_json(200, [
            'orderStatus' => 'Approved',
            'orderId'     => 'PF-77',
            'amount'      => 500,
        ], '/order/PF-77');

        $order = $gateway->payflex_remote_get_order('PF-77');

        $this->assertSame('Approved', $order->orderStatus);
        $this->assertSame('PF-77', $order->orderId);
    }

    public function test_remote_get_order_returns_false_for_an_empty_id(): void
    {
        $gateway = $this->gateway();

        $this->assertFalse($gateway->payflex_remote_get_order(''));
        $this->assertSame([], PF_State::$http_log);
    }

    public function test_remote_get_order_returns_false_on_error_or_unexpected_body(): void
    {
        $gateway = $this->gateway();

        PF_State::queue_response(new WP_Error('http_request_failed', 'down'), '/order/PF-77');
        $this->assertFalse($gateway->payflex_remote_get_order('PF-77'));

        PF_State::queue_json(200, ['unexpected' => true], '/order/PF-77');
        $this->assertFalse($gateway->payflex_remote_get_order('PF-77'));
    }

    public function test_remote_get_order_sanitises_the_supplied_id(): void
    {
        $gateway = $this->gateway();
        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');

        $gateway->payflex_remote_get_order("PF-77\n<script>");

        $this->assertStringNotContainsString('<script>', PF_State::$http_log[0]['url']);
        $this->assertStringNotContainsString("\n", PF_State::$http_log[0]['url']);
    }

    /* --------------------------------------------------------------------- */

    public function test_remote_status_page_records_the_result_as_an_order_note(): void
    {
        $gateway = $this->arriveFromPayflex('Approved');
        $order   = wc_get_order('1001');

        $this->captureRedirect(fn() => $gateway->page_check_remote_status());

        $this->assertStringContainsString(
            'returned Approved',
            implode("\n", array_column($order->pf_data()->notes, 'content'))
        );
    }
}
