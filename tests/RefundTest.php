<?php

/**
 * process_refund() posts to /order/{id}/refund and translates the response into
 * the true / false / WP_Error contract WooCommerce expects from a gateway.
 */
final class RefundTest extends PF_TestCase
{
    private function refundableOrder(array $props = []): WC_Order
    {
        return $this->order(array_merge([
            'total' => 500.00,
            'meta'  => ['_payflex_order_id' => 'PF-ORDER-1'],
        ], $props));
    }

    private function notes(WC_Order $order): string
    {
        return implode("\n", array_column($order->pf_data()->notes, 'content'));
    }

    /* --------------------------------------------------------------------- */

    public function test_a_successful_refund_returns_true_and_notes_the_amount(): void
    {
        $gateway = $this->gateway();
        $order   = $this->refundableOrder();
        PF_State::queue_json(201, ['refundId' => 'RF-1'], '/refund');

        $this->assertTrue($gateway->process_refund('1001', 250.00, 'Customer changed their mind'));
        $this->assertStringContainsString('Refund of $250 successfully sent to PayFlex.', $this->notes($order));
    }

    public function test_a_200_response_is_also_treated_as_success(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder();
        PF_State::queue_json(200, ['refundId' => 'RF-1'], '/refund');

        $this->assertTrue($gateway->process_refund('1001', 500.00));
    }

    public function test_posts_the_amount_and_a_unique_request_id_to_the_refund_endpoint(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder();
        PF_State::queue_json(201, ['refundId' => 'RF-1'], '/refund');

        $gateway->process_refund('1001', 250.00);

        $request = PF_State::$http_log[0];
        $body    = $this->requestBody();

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.payflex.co.za/order/PF-ORDER-1/refund', $request['url']);
        $this->assertSame('Bearer cached-access-token', $request['args']['headers']['Authorization']);

        $this->assertEquals(250.00, $body['amount']);
        $this->assertTrue($body['isPlugin']);
        $this->assertStringStartsWith('Order #1001-', $body['requestId']);
        $this->assertSame($body['requestId'], $body['merchantRefundReference']);
    }

    /**
     * Payflex rejects a repeated requestId, so partial refunds of the same
     * amount must not collide.
     */
    public function test_each_refund_attempt_uses_a_different_request_id(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder();
        PF_State::stub_json(201, ['refundId' => 'RF-1'], '/refund');

        $gateway->process_refund('1001', 100.00);
        $gateway->process_refund('1001', 100.00);

        $this->assertNotSame($this->requestBody(0)['requestId'], $this->requestBody(1)['requestId']);
    }

    /* --------------------------------------------------------------------- */

    public function test_refusing_an_order_that_was_never_paid_via_payflex(): void
    {
        $gateway = $this->gateway();
        $order   = $this->order();

        $this->assertFalse($gateway->process_refund('1001', 100.00));
        $this->assertSame([], PF_State::$http_log, 'No request should be made without a Payflex order id');
        $this->assertStringContainsString('There was an error submitting the refund to Payflex.', $this->notes($order));
    }

    public function test_a_404_reports_that_the_order_is_not_on_payflex(): void
    {
        $gateway = $this->gateway();
        $order   = $this->refundableOrder();
        PF_State::queue_json(404, ['message' => 'Not found'], '/refund');

        $this->assertFalse($gateway->process_refund('1001', 100.00));
        $this->assertStringContainsString('Order not found on Payflex.', $this->notes($order));
    }

    public function test_a_500_reports_a_generic_error(): void
    {
        $gateway = $this->gateway();
        $order   = $this->refundableOrder();
        PF_State::queue_json(500, ['message' => 'Boom'], '/refund');

        $this->assertFalse($gateway->process_refund('1001', 100.00));
        $this->assertStringContainsString('There was an error submitting the refund to Payflex.', $this->notes($order));
    }

    /**
     * KNOWN DEFECT — characterisation test, not an endorsement.
     *
     * process_refund() never calls is_wp_error() on the response. When the
     * request fails outright (timeout, DNS, TLS) wp_remote_post() returns a
     * WP_Error object, and the response-code line
     *
     *     isset($refund_response['response']['code'])
     *
     * then array-accesses that object, which is a fatal Error in PHP 8.
     *
     * This is reachable from the admin: create_refund() is hooked to
     * woocommerce_order_status_refunded, so a network blip while an admin marks
     * an order refunded produces a fatal error mid-request rather than the
     * "false" that WooCommerce expects. process_payment() guards for this;
     * process_refund() does not.
     *
     * When an is_wp_error() guard is added, change this to assert `false`.
     */
    public function test_a_network_error_currently_raises_a_fatal_error(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder();
        PF_State::queue_response(new WP_Error('http_request_failed', 'down'), '/refund');

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot use object of type WP_Error as array');

        $gateway->process_refund('1001', 100.00);
    }

    /**
     * MRM007 is Payflex's "refunds not enabled for this merchant" code. It is
     * surfaced as a WP_Error so WooCommerce shows the real reason in the admin
     * rather than a bare failure.
     */
    public function test_the_mrm007_refunds_disabled_code_returns_a_wp_error_with_the_api_message(): void
    {
        $gateway = $this->gateway();
        $order   = $this->refundableOrder();
        PF_State::queue_json(400, [
            'errorCode' => 'MRM007',
            'message'   => 'Refunds are not enabled for this merchant.',
        ], '/refund');

        $result = $gateway->process_refund('1001', 100.00);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('woocommerce_api_create_order_refund_api_failed', $result->get_error_code());
        $this->assertSame('Refunds are not enabled for this merchant.', $result->get_error_message());
        $this->assertStringContainsString('Refunds are not enabled for this merchant.', $this->notes($order));
    }

    public function test_a_400_with_a_different_error_code_returns_false(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder();
        PF_State::queue_json(400, ['errorCode' => 'MRM999', 'message' => 'Something else'], '/refund');

        $this->assertFalse($gateway->process_refund('1001', 100.00));
    }

    /* --------------------------------------------------------------------- */

    /**
     * Marking an order "Refunded" in the admin fires
     * woocommerce_order_status_refunded, which routes through create_refund().
     */
    public function test_the_refunded_status_hook_submits_the_recorded_refund_amount(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder(['refunds' => [new WC_Order_Refund(150.00)]]);
        PF_State::queue_json(201, ['refundId' => 'RF-1'], '/refund');

        $gateway->create_refund('1001');

        $this->assertEquals(150.00, $this->requestBody()['amount']);
    }

    public function test_the_refunded_status_hook_does_nothing_without_a_refund_record(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder(['refunds' => []]);

        $gateway->create_refund('1001');

        $this->assertSame([], PF_State::$http_log);
    }

    public function test_the_refunded_status_hook_ignores_a_zero_amount_refund(): void
    {
        $gateway = $this->gateway();
        $this->refundableOrder(['refunds' => [new WC_Order_Refund(0.00)]]);

        $gateway->create_refund('1001');

        $this->assertSame([], PF_State::$http_log);
    }

    public function test_the_gateway_registers_itself_on_the_refunded_status_hook(): void
    {
        $gateway = $this->gateway();

        $this->assertNotFalse(has_action('woocommerce_order_status_refunded', [$gateway, 'create_refund']));
    }
}
