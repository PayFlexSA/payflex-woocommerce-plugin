<?php

/**
 * Payflex_Eligibility decides which products, carts and orders may be paid for
 * with Payflex. It backs the gateway filter, the product widget parameter, the
 * Store API cart payload and the classic notices, so a wrong answer here either
 * hides a working payment method or offers one that cannot complete.
 */
final class EligibilityTest extends PF_TestCase
{
    /** Hook registries to restore, since PF_State::reset() deliberately keeps them. */
    private array $hookSnapshots = [];

    protected function tearDown(): void
    {
        foreach ($this->hookSnapshots as $hook => $snapshot) {
            PF_State::$hooks[$hook] = $snapshot;
        }

        $this->hookSnapshots = [];

        parent::tearDown();
    }

    /** Adds a filter for the duration of one test only. */
    private function withFilter(string $hook, callable $callback): void
    {
        if (!array_key_exists($hook, $this->hookSnapshots)) {
            $this->hookSnapshots[$hook] = PF_State::$hooks[$hook] ?? [];
        }

        add_filter($hook, $callback);
    }

    /* --------------------------------------------------------------------- */
    /* Product rules                                                          */
    /* --------------------------------------------------------------------- */

    public static function subscriptionTypes(): array
    {
        return [
            'simple subscription'   => ['subscription'],
            'subscription variation'=> ['subscription_variation'],
            'variable subscription' => ['variable-subscription'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('subscriptionTypes')]
    public function test_subscription_products_cannot_use_payflex(string $type): void
    {
        $this->set_settings();

        $product = new WC_Product(101, 'SKU-101', 500.00, $type);

        $this->assertFalse(Payflex_Eligibility::is_product_eligible($product));
        $this->assertSame(
            Payflex_Eligibility::REASON_PRODUCT_TYPE,
            Payflex_Eligibility::product_reason($product)
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('subscriptionTypes')]
    public function test_turning_the_subscription_rule_off_makes_them_eligible(string $type): void
    {
        $this->set_settings(['exclude_subscriptions' => 'no']);

        $this->assertTrue(
            Payflex_Eligibility::is_product_eligible(new WC_Product(101, 'SKU-101', 500.00, $type))
        );
    }

    public function test_a_simple_product_is_eligible(): void
    {
        $this->set_settings();

        $this->assertTrue(Payflex_Eligibility::is_product_eligible(new WC_Product()));
    }

    public function test_anything_that_is_not_a_product_has_no_reason(): void
    {
        $this->set_settings();

        $this->assertNull(Payflex_Eligibility::product_reason(null));
        $this->assertNull(Payflex_Eligibility::product_reason('not a product'));
    }

    public function test_a_merchant_excluded_product_cannot_use_payflex(): void
    {
        $this->set_settings();

        $product = $this->product(101, ['meta' => [Payflex_Eligibility::PRODUCT_META => 'yes']]);

        $this->assertSame(
            Payflex_Eligibility::REASON_PRODUCT_OPTOUT,
            Payflex_Eligibility::product_reason($product)
        );
    }

    public function test_a_variation_inherits_the_parent_exclusion(): void
    {
        $this->set_settings();

        $this->product(200, ['meta' => [Payflex_Eligibility::PRODUCT_META => 'yes']]);
        $variation = $this->product(201, ['parent_id' => 200, 'type' => 'variation']);

        $this->assertSame(
            Payflex_Eligibility::REASON_PRODUCT_OPTOUT,
            Payflex_Eligibility::product_reason($variation)
        );
    }

    public function test_a_variation_of_an_included_parent_is_eligible(): void
    {
        $this->set_settings();

        $this->product(200);
        $variation = $this->product(201, ['parent_id' => 200, 'type' => 'variation']);

        $this->assertTrue(Payflex_Eligibility::is_product_eligible($variation));
    }

    public function test_turning_per_product_exclusions_off_ignores_the_meta(): void
    {
        $this->set_settings(['enable_product_exclusions' => 'no']);

        $product = $this->product(101, ['meta' => [Payflex_Eligibility::PRODUCT_META => 'yes']]);

        $this->assertTrue(Payflex_Eligibility::is_product_eligible($product));
    }

    public function test_a_product_in_an_excluded_category_cannot_use_payflex(): void
    {
        $this->set_settings(['excluded_product_cats' => ['7']]);

        $product = $this->product(101, ['terms' => [7, 9]]);

        $this->assertSame(
            Payflex_Eligibility::REASON_CATEGORY,
            Payflex_Eligibility::product_reason($product)
        );
    }

    public function test_a_product_outside_the_excluded_categories_is_eligible(): void
    {
        $this->set_settings(['excluded_product_cats' => ['7']]);

        $this->assertTrue(Payflex_Eligibility::is_product_eligible($this->product(101, ['terms' => [9]])));
    }

    public function test_no_excluded_categories_blocks_nothing(): void
    {
        $this->set_settings();

        $this->assertTrue(Payflex_Eligibility::is_product_eligible($this->product(101, ['terms' => [7]])));
    }

    public function test_a_variation_is_categorised_by_its_parent(): void
    {
        $this->set_settings(['excluded_product_cats' => ['7']]);

        $this->product(200, ['terms' => [7]]);
        $variation = $this->product(201, ['parent_id' => 200, 'type' => 'variation']);

        $this->assertFalse(Payflex_Eligibility::is_product_eligible($variation));
    }

    /**
     * The settings badge counts a parent category exclusion as covering every
     * product filed beneath it, because its tax_query leaves include_children at
     * the WP_Tax_Query default. The runtime check has to agree, or the badge
     * reports an exclusion that never actually takes effect.
     */
    public function test_a_product_in_a_child_of_an_excluded_category_is_excluded(): void
    {
        $this->set_settings(['excluded_product_cats' => ['7']]);

        PF_State::$term_ancestors['9|product_cat'] = [7];

        $product = $this->product(101, ['terms' => [9]]);

        $this->assertFalse(Payflex_Eligibility::is_product_eligible($product));
        $this->assertSame(Payflex_Eligibility::REASON_CATEGORY, Payflex_Eligibility::product_reason($product));
    }

    public function test_a_product_under_an_unrelated_parent_is_left_alone(): void
    {
        $this->set_settings(['excluded_product_cats' => ['7']]);

        PF_State::$term_ancestors['9|product_cat'] = [8];

        $this->assertTrue(Payflex_Eligibility::is_product_eligible($this->product(101, ['terms' => [9]])));
    }

    public function test_the_ineligible_types_filter_is_applied(): void
    {
        $this->set_settings();

        $this->withFilter('payflex_ineligible_product_types', fn($types) => array_merge($types, ['booking']));

        $this->assertFalse(
            Payflex_Eligibility::is_product_eligible(new WC_Product(101, 'SKU-101', 500.00, 'booking'))
        );
    }

    public function test_the_product_reason_filter_is_applied(): void
    {
        $this->set_settings();

        $this->withFilter('payflex_product_reason', fn($reason, $product) => 'custom');

        $this->assertSame('custom', Payflex_Eligibility::product_reason(new WC_Product()));
    }

    /**
     * The product widget renders on every product page, so a limits lookup here
     * would put a synchronous outbound request on the catalogue.
     */
    public function test_product_checks_never_reach_the_limits_endpoint(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->ageTheLimitCache(86401);
        $before = count(PF_State::requested_urls());

        Payflex_Eligibility::is_product_eligible($this->product(101));

        $this->assertCount($before, PF_State::requested_urls());
    }

    /** Pushes the stored refresh timestamp $seconds into the past. */
    private function ageTheLimitCache(int $seconds): void
    {
        $settings = get_option('woocommerce_payflex_settings', []);
        $settings['payflex_limit_last_updated'] = time() - $seconds;
        update_option('woocommerce_payflex_settings', $settings);
    }

    /* --------------------------------------------------------------------- */
    /* Cart rules                                                             */
    /* --------------------------------------------------------------------- */

    public function test_an_all_eligible_cart_keeps_payflex(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [$this->product(101)]);

        $this->assertArrayHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
    }

    public function test_an_ineligible_line_removes_payflex_and_leaves_other_gateways_alone(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [new WC_Product(101, 'SKU-101', 750.00, 'subscription')]);

        $gateways = $gateway->check_cart_within_limits($this->gateways());

        $this->assertArrayNotHasKey('payflex', $gateways);
        $this->assertArrayHasKey('cod', $gateways, 'Other gateways must be left alone');
    }

    public function test_widget_only_mode_never_removes_payflex(): void
    {
        $gateway = $this->gateway(['widget_only_mode' => 'yes']);
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [new WC_Product(101, 'SKU-101', 750.00, 'subscription')]);

        $this->assertArrayHasKey('payflex', $gateway->check_cart_within_limits($this->gateways()));
    }

    public function test_only_the_offending_lines_are_reported(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [
            $this->product(101, ['name' => 'Good Widget']),
            new WC_Product(102, 'SKU-102', 250.00, 'subscription', 'Monthly Box'),
        ]);

        $result = Payflex_Eligibility::evaluate_cart();

        $this->assertFalse($result['eligible']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Monthly Box', $result['items'][0]['name']);
        $this->assertSame(102, $result['items'][0]['product_id']);
    }

    public function test_reasons_are_deduplicated(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [
            new WC_Product(101, 'SKU-101', 250.00, 'subscription', 'Box A'),
            new WC_Product(102, 'SKU-102', 250.00, 'subscription', 'Box B'),
        ]);

        $result = Payflex_Eligibility::evaluate_cart();

        $this->assertSame([Payflex_Eligibility::REASON_PRODUCT_TYPE], $result['reasons']);
        $this->assertCount(2, $result['items']);
    }

    public function test_a_cart_below_the_minimum_is_ineligible(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(49.99, [$this->product(101)]);

        $result = Payflex_Eligibility::evaluate_cart();

        $this->assertSame([Payflex_Eligibility::REASON_BELOW_MIN], $result['reasons']);
    }

    public function test_a_cart_above_the_maximum_is_ineligible(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(20000.01, [$this->product(101)]);

        $result = Payflex_Eligibility::evaluate_cart();

        $this->assertSame([Payflex_Eligibility::REASON_ABOVE_MAX], $result['reasons']);
    }

    public function test_both_limit_boundaries_are_inclusive(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);

        $this->withCart(50.0, [$this->product(101)]);
        $this->assertTrue(Payflex_Eligibility::evaluate_cart()['eligible']);

        $this->withCart(20000.0, [$this->product(101)]);
        $this->assertTrue(Payflex_Eligibility::evaluate_cart()['eligible']);
    }

    public function test_no_cart_is_treated_as_a_zero_total(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = null;

        $this->assertSame(
            [Payflex_Eligibility::REASON_BELOW_MIN],
            Payflex_Eligibility::evaluate_cart()['reasons']
        );
    }

    public function test_unknown_limits_leave_the_cart_eligible(): void
    {
        $this->gateway(['client_id' => '', 'client_secret' => '']);
        $this->withCart(750.00, [$this->product(101)]);

        $this->assertTrue(Payflex_Eligibility::evaluate_cart()['eligible']);
    }

    public function test_the_result_filter_can_force_an_answer(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [$this->product(101)]);

        $this->withFilter('payflex_eligibility_result', function ($result) {
            $result['eligible'] = false;
            return $result;
        });

        $this->assertFalse(Payflex_Eligibility::evaluate_cart()['eligible']);
    }

    /* --------------------------------------------------------------------- */
    /* Messages                                                               */
    /* --------------------------------------------------------------------- */

    public function test_an_eligible_cart_has_no_message(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [$this->product(101)]);

        $this->assertSame('', Payflex_Eligibility::evaluate_cart()['message']);
    }

    public function test_the_message_names_the_offending_item(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [new WC_Product(101, 'SKU-101', 750.00, 'subscription', 'Monthly Box')]);

        $this->assertStringContainsString('Monthly Box', Payflex_Eligibility::evaluate_cart()['message']);
    }

    public function test_the_message_names_every_offending_item(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [
            new WC_Product(101, 'SKU-101', 250.00, 'subscription', 'Box A'),
            new WC_Product(102, 'SKU-102', 250.00, 'subscription', 'Box B'),
        ]);

        $message = Payflex_Eligibility::evaluate_cart()['message'];

        $this->assertStringContainsString('Box A', $message);
        $this->assertStringContainsString('Box B', $message);
    }

    public function test_the_below_minimum_message_quotes_the_minimum(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(10.00, [$this->product(101)]);

        $message = Payflex_Eligibility::evaluate_cart()['message'];

        $this->assertStringContainsString('R50.00', $message);
        $this->assertStringContainsString('from', $message);
    }

    public function test_the_above_maximum_message_quotes_the_maximum(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(30000.00, [$this->product(101)]);

        $message = Payflex_Eligibility::evaluate_cart()['message'];

        $this->assertStringContainsString('R20,000.00', $message);
        $this->assertStringContainsString('up to', $message);
    }

    /**
     * The classic notice runs the message through esc_html() and the Store API
     * ships it as a plain string, so any markup wc_price() adds would be shown
     * to the shopper verbatim.
     */
    public function test_limit_messages_carry_no_markup(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(10.00, [$this->product(101)]);

        $message = Payflex_Eligibility::evaluate_cart()['message'];

        $this->assertStringNotContainsString('<', $message);
        $this->assertStringNotContainsString('&', $message);
    }

    /**
     * A filter can produce a reason with no offending line and no limit breach.
     * Without a fallback the message would quote a limit that was never set.
     */
    public function test_an_unrecognised_reason_falls_back_to_a_generic_message(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [$this->product(101)]);

        $this->withFilter('payflex_product_reason', fn($reason) => 'something_else');

        $result = Payflex_Eligibility::evaluate_cart();

        $this->assertFalse($result['eligible']);
        $this->assertStringNotContainsString('R0.00', $result['message']);
        $this->assertNotSame('', $result['message']);
    }

    /* --------------------------------------------------------------------- */
    /* Orders                                                                 */
    /* --------------------------------------------------------------------- */

    public function test_an_order_of_eligible_products_is_eligible(): void
    {
        $this->gateway();
        $this->product(101);

        $this->assertTrue(Payflex_Eligibility::evaluate_order($this->order())['eligible']);
    }

    public function test_an_order_containing_a_subscription_is_ineligible(): void
    {
        $this->gateway();
        $this->product(101, ['type' => 'subscription', 'name' => 'Monthly Box']);

        $result = Payflex_Eligibility::evaluate_order($this->order());

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('Monthly Box', $result['message']);
    }

    public function test_an_order_without_an_order_object_is_eligible(): void
    {
        $this->gateway();

        $this->assertTrue(Payflex_Eligibility::evaluate_order(null)['eligible']);
    }

    /**
     * WooCommerce re-runs the gateway filter on submission, so the cart total is
     * already measured against the limits. Re-measuring the order would also put
     * a possibly cold /configuration fetch on the payment path and would break
     * an order-pay retry after the merchant changes their limits.
     */
    public function test_order_eligibility_ignores_the_amount_limits(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->product(101);
        $before = count(PF_State::requested_urls());

        $this->assertTrue(Payflex_Eligibility::evaluate_order($this->order(['total' => 10.00]))['eligible']);
        $this->assertCount($before, PF_State::requested_urls(), 'No limits lookup belongs on the payment path');
    }

    public function test_an_empty_cart_does_not_make_an_order_ineligible(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = null;
        $this->product(101);

        $this->assertTrue(Payflex_Eligibility::evaluate_order($this->order())['eligible']);
    }

    /* --------------------------------------------------------------------- */
    /* Enforcement                                                            */
    /* --------------------------------------------------------------------- */

    public function test_process_payment_refuses_an_ineligible_order_without_calling_payflex(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->product(101, ['type' => 'subscription', 'name' => 'Monthly Box']);
        $this->order();
        $before = count(PF_State::requested_urls());

        $result = $gateway->process_payment('1001');

        $this->assertSame('failure', $result['result']);
        $this->assertStringContainsString('Monthly Box', $result['message']);
        $this->assertCount($before, PF_State::requested_urls(), 'No payment should be created');
        $this->assertNotice('Monthly Box');
    }

    public function test_process_payment_logs_why_it_refused(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->product(101, ['type' => 'subscription']);
        $this->order();

        $gateway->process_payment('1001');

        $this->assertLogged(Payflex_Eligibility::REASON_PRODUCT_TYPE);
    }

    public function test_the_classic_notice_explains_an_ineligible_cart(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [new WC_Product(101, 'SKU-101', 750.00, 'subscription', 'Monthly Box')]);

        $gateway->render_eligibility_notice();

        $this->assertCount(1, PF_State::$printed_notices);
        $this->assertStringContainsString('Monthly Box', PF_State::$printed_notices[0]['message']);
        $this->assertSame('notice', PF_State::$printed_notices[0]['type']);
    }

    public function test_the_classic_notice_stays_quiet_for_an_eligible_cart(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [$this->product(101)]);

        $gateway->render_eligibility_notice();

        $this->assertSame([], PF_State::$printed_notices);
    }

    public function test_the_classic_notice_stays_quiet_when_payflex_is_off(): void
    {
        $gateway = $this->gateway(['enabled' => 'no']);
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [new WC_Product(101, 'SKU-101', 750.00, 'subscription')]);

        $gateway->render_eligibility_notice();

        $this->assertSame([], PF_State::$printed_notices);
    }

    /* --------------------------------------------------------------------- */
    /* Store API payload                                                      */
    /* --------------------------------------------------------------------- */

    public function test_the_cart_payload_reports_an_eligible_cart(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [$this->product(101)]);

        $this->assertSame(['eligible' => true, 'message' => ''], payflex_cart_eligibility_data());
    }

    public function test_the_cart_payload_reports_an_ineligible_cart(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [new WC_Product(101, 'SKU-101', 750.00, 'subscription', 'Monthly Box')]);

        $payload = payflex_cart_eligibility_data();

        $this->assertFalse($payload['eligible']);
        $this->assertStringContainsString('Monthly Box', $payload['message']);
    }

    public function test_the_cart_payload_schema_describes_both_keys(): void
    {
        $schema = payflex_cart_eligibility_schema();

        $this->assertSame('boolean', $schema['eligible']['type']);
        $this->assertSame('string', $schema['message']['type']);
        $this->assertTrue($schema['eligible']['readonly']);
        $this->assertTrue($schema['message']['readonly']);
    }

    /* --------------------------------------------------------------------- */
    /* Defaults                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Both exclusion rules ship switched off, and there is no migration, so a
     * store that has never opened the settings screen has neither option
     * written. An unset option has to read as off, or the update would start
     * hiding Payflex on carts nobody asked it to.
     */
    public function test_settings_that_predate_the_feature_exclude_nothing(): void
    {
        $settings = self::VALID_SETTINGS;
        unset($settings['exclude_subscriptions'], $settings['enable_product_exclusions'], $settings['excluded_product_cats']);
        $this->set_settings($settings, true);

        $subscription = new WC_Product(101, 'SKU-101', 500.00, 'subscription');
        $opted_out    = $this->product(102, ['meta' => [Payflex_Eligibility::PRODUCT_META => 'yes']]);

        $this->assertTrue(Payflex_Eligibility::is_product_eligible($subscription));
        $this->assertTrue(Payflex_Eligibility::is_product_eligible($opted_out));
        $this->assertFalse(Payflex_Admin_Products::enabled());
    }

    public function test_the_exclusion_settings_ship_switched_off(): void
    {
        $fields = $this->gateway()->form_fields();

        $this->assertSame('no', $fields['exclude_subscriptions']['default']);
        $this->assertSame('no', $fields['enable_product_exclusions']['default']);
        $this->assertSame([], $fields['excluded_product_cats']['default']);
    }

    /**
     * A subscription is only blocked once the merchant asks for it.
     */
    public function test_subscriptions_are_allowed_until_the_rule_is_switched_on(): void
    {
        $this->set_settings(['exclude_subscriptions' => 'no']);

        $subscription = new WC_Product(101, 'SKU-101', 500.00, 'subscription');

        $this->assertTrue(Payflex_Eligibility::is_product_eligible($subscription));

        $this->set_settings(['exclude_subscriptions' => 'yes']);

        $this->assertFalse(Payflex_Eligibility::is_product_eligible($subscription));
    }

    /**
     * check_cart_within_limits() runs on woocommerce_available_payment_gateways.
     * Resolving the singleton from in there constructs a second gateway, whose
     * constructor registers this same callback on the hook currently being
     * iterated, so the evaluation runs all over again.
     */
    public function test_the_gateway_filter_does_not_construct_a_second_gateway(): void
    {
        $gateway = $this->gateway();
        $this->withLimits(50.0, 20000.0);
        $this->withCart(750.00, [$this->product(101)]);

        $gateway->check_cart_within_limits($this->gateways());

        $instance = (new ReflectionClass(WC_Gateway_PartPay::class))->getProperty('_instance');

        $this->assertNull(
            $instance->getValue(),
            'The filter must measure with the gateway it is already running on'
        );
    }

    /* --------------------------------------------------------------------- */

    private function gateways(): array
    {
        return ['payflex' => 'Payflex', 'cod' => 'Cash on delivery'];
    }

    /**
     * Register a product wc_get_product() will hand back, with optional
     * exclusion meta, a parent, and product_cat term ids.
     */
    private function product(int $id, array $props = []): WC_Product
    {
        $product = new WC_Product(
            $id,
            'SKU-' . $id,
            $props['price'] ?? 100.00,
            $props['type'] ?? 'simple',
            $props['name'] ?? 'Product ' . $id,
            $props['parent_id'] ?? 0,
            $props['meta'] ?? []
        );

        PF_State::$products[$id] = $product;

        if (isset($props['terms'])) {
            PF_State::$product_terms[$id . '|product_cat'] = $props['terms'];
        }

        return $product;
    }

    /** @param list<WC_Product> $products */
    private function withCart(float $total, array $products): void
    {
        PF_State::$cart_total = $total;
        PF_State::$cart_items = [];

        foreach ($products as $index => $product) {
            PF_State::$cart_items['item-' . $index] = ['data' => $product, 'quantity' => 1];
        }
    }
}
