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
     * get_payflex_limits() runs on the woocommerce_available_payment_gateways
     * filter, i.e. on every cart and checkout page load, so a cache miss is a
     * synchronous outbound request on a page the shopper is waiting for.
     */
    public function test_get_limits_uses_the_cache_within_24_hours(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);

        $gateway->get_payflex_limits();
        $gateway->get_payflex_limits();
        $gateway->get_payflex_limits();

        $this->assertCount(0, $this->configurationCalls(), 'A fresh cache must not be refreshed');
    }

    public function test_get_limits_refreshes_when_the_cache_is_stale(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->ageTheLimitCache(86401);

        $gateway->get_payflex_limits();
        $gateway->get_payflex_limits();

        $this->assertCount(1, $this->configurationCalls(), 'A stale cache is refreshed once, then cached again');
    }

    public function test_get_limits_refreshes_when_nothing_has_ever_been_stored(): void
    {
        $gateway = $this->gateway();
        PF_State::stub_json(200, ['minimumAmount' => 50.0, 'maximumAmount' => 20000.0], '/configuration');

        $gateway->get_payflex_limits();

        $this->assertCount(1, $this->configurationCalls());
    }

    /**
     * Every attempt is stamped, not just a 200, so an endpoint that is down is
     * not re-hit on every cart and checkout load.
     */
    public function test_a_failed_refresh_is_not_retried_on_the_next_call(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->ageTheLimitCache(86401);
        PF_State::$http_standing = [];
        PF_State::stub_json(500, [], '/configuration');

        $gateway->get_payflex_limits();
        $gateway->get_payflex_limits();
        $gateway->get_payflex_limits();

        $this->assertCount(1, $this->configurationCalls(), 'A failing endpoint must not be hammered');
    }

    /**
     * A failure must not be recorded as a successful refresh. Stamping the full
     * interval on one leaves a fresh install — which has no limits stored at all
     * — reporting every cart as inside them until the next day.
     */
    public function test_a_failed_refresh_does_not_count_as_a_successful_one(): void
    {
        $gateway = $this->gateway();
        PF_State::$http_standing = [];
        PF_State::stub_json(500, [], '/configuration');

        $gateway->update_payment_limits();

        $settings = get_option('woocommerce_payflex_settings', []);

        $this->assertArrayNotHasKey('payflex_limit_last_updated', $settings);
        $this->assertArrayHasKey('payflex_limit_last_attempt', $settings, 'The attempt still has to back off');
    }

    /**
     * With no limits ever stored, nothing is enforced until a refresh succeeds,
     * so the backoff after a failure has to be short rather than a full day.
     */
    public function test_a_failed_refresh_is_retried_once_the_backoff_expires(): void
    {
        $gateway = $this->gateway();
        PF_State::$http_standing = [];
        PF_State::stub_json(500, [], '/configuration');

        $gateway->get_payflex_limits();
        $this->ageTheRetryBackoff(WC_Gateway_PartPay::LIMIT_RETRY_INTERVAL + 1);
        $gateway->get_payflex_limits();

        $this->assertCount(2, $this->configurationCalls());
        $this->assertLessThan(
            WC_Gateway_PartPay::LIMIT_REFRESH_INTERVAL,
            WC_Gateway_PartPay::LIMIT_RETRY_INTERVAL,
            'A failure must back off for less than a success does'
        );
    }

    /**
     * Without credentials there is nothing to ask, but the attempt still has to
     * be recorded or every get_payflex_limits() call re-enters the refresh.
     */
    public function test_a_refresh_without_credentials_still_backs_off(): void
    {
        $gateway = $this->gateway(['client_id' => '', 'client_secret' => '']);

        $this->assertFalse($gateway->update_payment_limits());

        $settings = get_option('woocommerce_payflex_settings', []);

        $this->assertArrayHasKey('payflex_limit_last_attempt', $settings);
    }

    /** Pushes the stored retry backoff $seconds into the past. */
    private function ageTheRetryBackoff(int $seconds): void
    {
        $settings = get_option('woocommerce_payflex_settings', []);
        $settings['payflex_limit_last_attempt'] = time() - $seconds;
        update_option('woocommerce_payflex_settings', $settings);
    }

    public function test_a_failed_refresh_leaves_the_cached_limits_readable(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->ageTheLimitCache(86401);
        PF_State::$http_standing = [];
        PF_State::stub_json(500, [], '/configuration');

        $this->assertSame([
            'minimum'         => 50.0,
            'maximum'         => 20000.0,
            'refunds_enabled' => true,
        ], $gateway->get_payflex_limits());
    }

    /** Pushes the stored refresh timestamp $seconds into the past. */
    private function ageTheLimitCache(int $seconds): void
    {
        $settings = get_option('woocommerce_payflex_settings', []);
        $settings['payflex_limit_last_updated'] = time() - $seconds;
        update_option('woocommerce_payflex_settings', $settings);
    }

    private function configurationCalls(): array
    {
        return array_filter(
            PF_State::requested_urls(),
            fn($url) => str_contains($url, '/configuration')
        );
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

    /**
     * Widget only mode never offers the gateway, so the limits lookup - and the
     * /configuration call behind it - must not run on cart and checkout loads.
     */
    public function test_widget_only_mode_skips_the_limits_check_entirely(): void
    {
        $gateway = $this->gateway(['widget_only_mode' => 'yes']);
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 49.99;

        $before = count(PF_State::requested_urls());

        $this->assertArrayHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
        $this->assertCount($before, PF_State::requested_urls(), 'No API call should be made in widget only mode');
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
