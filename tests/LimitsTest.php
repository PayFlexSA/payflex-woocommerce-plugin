<?php

/**
 * Payment limits come from the Payflex /configuration endpoint and decide
 * whether the gateway is offered at checkout at all.
 */
final class LimitsTest extends PF_TestCase
{
    public function test_update_does_nothing_without_credentials(): void
    {
        $gateway = $this->gateway(['client_id' => '', 'client_secret' => '']);

        $this->assertFalse($gateway->update_payment_limits());
        $this->assertSame([], PF_State::$http_log);
    }

    public function test_update_stores_the_limits_returned_by_the_api(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_json(200, [
            'minimumAmount'     => 50,
            'maximumAmount'     => 20000,
            'enabledForRefunds' => true,
        ], '/configuration');

        $gateway->update_payment_limits();
        $settings = get_payflex_option();

        $this->assertSame(50, $settings['payflex_limit_amount_minimum']);
        $this->assertSame(20000, $settings['payflex_limit_amount_maximum']);
        $this->assertTrue($settings['payflex_limit_refunds_enabled']);
        $this->assertGreaterThanOrEqual(PF_State::$now, $settings['payflex_limit_last_updated']);
    }

    public function test_update_requests_the_configuration_endpoint_with_a_bearer_token(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_json(200, ['minimumAmount' => 50, 'maximumAmount' => 20000], '/configuration');

        $gateway->update_payment_limits();

        $request = PF_State::$http_log[0];

        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://api.payflex.co.za/configuration', $request['url']);
        $this->assertSame('Bearer cached-access-token', $request['args']['headers']['Authorization']);
    }

    public function test_missing_amounts_in_the_api_response_become_zero(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_json(200, ['somethingElse' => true], '/configuration');

        $gateway->update_payment_limits();
        $settings = get_payflex_option();

        $this->assertSame(0, $settings['payflex_limit_amount_minimum']);
        $this->assertSame(0, $settings['payflex_limit_amount_maximum']);
        $this->assertFalse($settings['payflex_limit_refunds_enabled']);
    }

    /**
     * A transient API failure must not wipe limits that are already known, or
     * the gateway would disappear from checkout until the next success.
     */
    public function test_a_failed_update_leaves_existing_limits_intact(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$http_standing = [];

        PF_State::queue_json(503, ['message' => 'Service unavailable'], '/configuration');
        $gateway->update_payment_limits();

        $settings = get_payflex_option();

        $this->assertSame(50.0, $settings['payflex_limit_amount_minimum']);
        $this->assertSame(20000.0, $settings['payflex_limit_amount_maximum']);
    }

    public function test_a_network_error_leaves_existing_limits_intact(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$http_standing = [];

        PF_State::queue_response(new WP_Error('http_request_failed', 'timeout'), '/configuration');
        $gateway->update_payment_limits();

        $this->assertSame(50.0, get_payflex_option('payflex_limit_amount_minimum'));
    }

    /**
     * Versions before 2.7 stored limits under dash-separated keys; the update
     * routine cleans those up so stale values cannot be read back.
     */
    public function test_update_removes_the_legacy_dash_separated_limit_keys(): void
    {
        $gateway = $this->gateway([
            'payflex-amount-minimum' => 100,
            'payflex-amount-maximum' => 5000,
        ]);
        PF_State::queue_json(200, ['minimumAmount' => 50, 'maximumAmount' => 20000], '/configuration');

        $gateway->update_payment_limits();
        $settings = get_payflex_option();

        $this->assertArrayNotHasKey('payflex-amount-minimum', $settings);
        $this->assertArrayNotHasKey('payflex-amount-maximum', $settings);
    }

    /* --------------------------------------------------------------------- */

    public function test_get_limits_returns_minimum_maximum_and_refund_flag(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0, true);

        // assertEquals, not assertSame: the values round-trip through JSON, so
        // int/float is not a contract worth pinning.
        $this->assertEquals([
            'minimum'         => 50.0,
            'maximum'         => 20000.0,
            'refunds_enabled' => true,
        ], $gateway->get_payflex_limits());
    }

    public function test_get_limits_can_return_a_single_field(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0, false);

        $this->assertEquals(50.0, $gateway->get_payflex_limits('amount_minimum'));
        $this->assertEquals(20000.0, $gateway->get_payflex_limits('amount_maximum'));
        $this->assertFalse($gateway->get_payflex_limits('refunds_enabled'));
    }

    public function test_get_limits_returns_false_for_each_field_when_nothing_is_stored(): void
    {
        $gateway = $this->gateway(['client_id' => '', 'client_secret' => '']);

        $this->assertSame([
            'minimum'         => false,
            'maximum'         => false,
            'refunds_enabled' => false,
        ], $gateway->get_payflex_limits());

        $this->assertFalse($gateway->get_payflex_limits('amount_minimum'));
    }

    /**
     * KNOWN DEFECT — characterisation test, not an endorsement.
     *
     * get_payflex_limits() tests $settings['payflex_limit_last_updated'] before
     * $settings is assigned (the assignment is on the next line), so the
     * staleness check is always true and every call re-hits /configuration.
     * The 86400-second cache it intends to implement never takes effect.
     *
     * get_payflex_limits() is called from check_cart_within_limits(), which
     * runs on the woocommerce_available_payment_gateways filter — i.e. on cart
     * and checkout page loads.
     */
    public function test_get_limits_refreshes_from_the_api_on_every_call(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);

        $gateway->get_payflex_limits();
        $gateway->get_payflex_limits();
        $gateway->get_payflex_limits();

        $configuration_calls = array_filter(
            PF_State::requested_urls(),
            fn($url) => str_contains($url, '/configuration')
        );

        $this->assertCount(3, $configuration_calls, 'The 24h limit cache is not being honoured');
    }

    /* --------------------------------------------------------------------- */

    private function gateways(): array
    {
        return ['payflex' => 'Payflex', 'cod' => 'Cash on delivery'];
    }

    public function test_gateway_is_offered_when_the_cart_total_is_within_limits(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 750.0;

        $this->assertArrayHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
    }

    public function test_gateway_is_removed_when_the_cart_total_is_below_the_minimum(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 49.99;

        $result = $gateway->check_cart_within_limits($this->gateways());

        $this->assertArrayNotHasKey('payflex', $result);
        $this->assertArrayHasKey('cod', $result, 'Other gateways must be left alone');
    }

    public function test_gateway_is_removed_when_the_cart_total_is_above_the_maximum(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 20000.01;

        $this->assertArrayNotHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
    }

    public function test_the_limits_are_inclusive_at_both_boundaries(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);

        PF_State::$cart_total = 50.0;
        $this->assertArrayHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));

        PF_State::$cart_total = 20000.0;
        $this->assertArrayHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
    }

    /**
     * With no limits known, the gateway is left in place rather than silently
     * removed — better to attempt a payment than to hide the method entirely.
     */
    public function test_gateway_is_left_alone_when_limits_are_unknown(): void
    {
        $gateway = $this->gateway(['client_id' => '', 'client_secret' => '']);
        PF_State::$cart_total = 750.0;

        $this->assertArrayHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
    }

    public function test_an_empty_cart_total_is_treated_as_zero_and_removes_the_gateway(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = null;

        $this->assertArrayNotHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
    }
}
