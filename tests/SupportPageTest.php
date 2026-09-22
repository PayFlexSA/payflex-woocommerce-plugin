<?php

/**
 * The Payflex Support admin page is the first thing support staff look at, so
 * it must render without fatals and report the environment accurately.
 */
final class SupportPageTest extends PF_TestCase
{
    private function render(): string
    {
        ob_start();
        WC_Gateway_PartPay::payflex_support_page();
        return ob_get_clean();
    }

    public function test_it_is_registered_under_woocommerce_settings_for_admins_only(): void
    {
        WC_Gateway_PartPay::register_support_page();

        $this->assertCount(1, PF_State::$submenu_pages);
        $page = PF_State::$submenu_pages[0];

        $this->assertSame('wc-settings', $page['parent']);
        $this->assertSame('payflex-support', $page['slug']);
        $this->assertSame('manage_options', $page['capability'], 'The support page must require admin capability');
    }

    public function test_it_renders_the_environment_summary(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0);

        $output = $this->render();

        $this->assertStringContainsString('Payflex Support', $output);
        $this->assertStringContainsString('v' . PF_PluginMeta::headerVersion(), $output);
        $this->assertStringContainsString('PHP v' . PHP_VERSION, $output);
        $this->assertStringContainsString('WordPress v' . PF_State::$wp_version, $output);
        $this->assertStringContainsString('WooCommerce v' . PF_State::$wc_version, $output);
    }

    public function test_it_flags_widget_only_mode_so_api_errors_are_not_misread(): void
    {
        $this->gateway(['widget_only_mode' => 'yes']);

        $this->assertStringContainsString('Widget Only Mode:', $this->render());
    }

    public function test_it_does_not_mention_widget_only_mode_when_it_is_off(): void
    {
        $this->gateway();

        $this->assertStringNotContainsString('Widget Only Mode:', $this->render());
    }

    public function test_it_reports_the_configured_limits_and_refund_availability(): void
    {
        $this->gateway();
        $this->withLimits(99.0, 15000.0, true);

        $output = $this->render();

        $this->assertStringContainsString('Min: R99.00', $output);
        $this->assertStringContainsString('Max: R15,000.00', $output);
        $this->assertStringContainsString('(Refunds Enabled)', $output);
    }

    public function test_it_flags_when_refunds_are_disabled_for_the_merchant(): void
    {
        $this->gateway();
        $this->withLimits(50.0, 20000.0, false);

        $this->assertStringContainsString('(Refunds Disabled)', $this->render());
    }

    public function test_it_reports_missing_limits_rather_than_rendering_blanks(): void
    {
        $this->gateway(['client_id' => '', 'client_secret' => '']);

        $this->assertStringContainsString('Limits not available', $this->render());
    }

    public function test_it_reports_a_successful_api_connection(): void
    {
        $this->gateway();
        $this->withLimits();

        $output = $this->render();

        $this->assertStringContainsString('Payflex Authentication', $output);
        $this->assertStringContainsString('Successful', $output);
    }

    public function test_it_reports_an_authentication_failure(): void
    {
        // No cached token, so the page has to authenticate and fails.
        $this->gateway(['client_id' => '', 'client_secret' => ''], false);

        $this->assertStringContainsString('Authentication Error', $this->render());
    }

    public function test_it_warns_when_woocommerce_logging_is_disabled(): void
    {
        $this->gateway();
        $this->withLimits();
        \Automattic\WooCommerce\Internal\Admin\Logging\Settings::$logging_enabled = false;

        $output = $this->render();

        $this->assertStringContainsString('Woocommerce logging disabled', $output);
        $this->assertStringContainsString('Payflex will not save logs', $output);
    }

    public function test_it_confirms_when_woocommerce_logging_is_enabled(): void
    {
        $this->gateway();
        $this->withLimits();

        $this->assertStringContainsString('Woocommerce logging enabled', $this->render());
    }

    public function test_it_reports_the_scheduled_order_queues(): void
    {
        $this->gateway();
        $this->withLimits();

        $this->order([
            'id'           => '7001',
            'date_created' => time() - 300,
            'meta'         => [
                '_payflex_order_id'        => 'PF-7001',
                '_payflex_workflow_status' => 'initiated',
            ],
        ]);
        $this->order([
            'id'           => '7002',
            'date_created' => time() - 2700,
            'meta'         => [
                '_payflex_order_id'        => 'PF-7002',
                '_payflex_workflow_status' => 'initiated',
            ],
        ]);

        $output = $this->render();

        $this->assertStringContainsString('1 Waiting order.', $output);
        $this->assertStringContainsString('1 Order currently in queue.', $output);
    }

    public function test_it_pluralises_the_queue_counts(): void
    {
        $this->gateway();
        $this->withLimits();

        $output = $this->render();

        $this->assertStringContainsString('0 Waiting orders.', $output);
        $this->assertStringContainsString('0 Orders currently in queue.', $output);
    }

    public function test_it_confirms_when_all_settings_are_saved(): void
    {
        $gateway = $this->gateway();

        $settings = [];
        foreach ($gateway->form_fields() as $key => $field) {
            $settings[$key] = $field['default'] ?? '';
        }
        $settings['payflex_limit_amount_minimum'] = 50;
        $settings['payflex_limit_amount_maximum'] = 20000;
        update_option('woocommerce_payflex_settings', $settings);
        PF_State::stub_json(200, ['minimumAmount' => 50, 'maximumAmount' => 20000], '/configuration');

        $this->assertStringContainsString('All settings appear to be saved correctly', $this->render());
    }

    public function test_it_lists_settings_that_were_never_saved(): void
    {
        $this->gateway();
        $this->withLimits();

        $settings = payflex_get_option();
        unset($settings['client_secret']);
        update_option('woocommerce_payflex_settings', $settings);

        $output = $this->render();

        $this->assertStringContainsString('Missing or incorrectly saved settings', $output);
        $this->assertStringContainsString('client_secret', $output);
    }

    /**
     * Section markers and the widget preview are never saved, so reporting them
     * would tell every merchant their settings are broken.
     */
    public function test_it_does_not_report_rows_that_hold_no_value(): void
    {
        $this->gateway();
        $this->withLimits();

        $output = $this->render();

        $this->assertStringContainsString('All settings appear to be saved correctly', $output);
        $this->assertStringNotContainsString('section_general_start', $output);
    }

    /* --------------------------------------------------------------------- */

    public function test_the_force_check_button_runs_the_cron_sweep(): void
    {
        $this->gateway();
        $this->withLimits();
        $order = $this->order([
            'id'           => '7003',
            'date_created' => time() - 120,
            'meta'         => [
                '_payflex_order_id'        => 'PF-7003',
                '_payflex_order_token'     => 'tok',
                '_payflex_workflow_status' => 'initiated',
            ],
        ]);

        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');
        PF_State::$user_can = true;
        $_GET = [
            'force_cron'               => 'Force Check',
            'payflex_force_cron_nonce' => wp_create_nonce('payflex_force_cron'),
        ];

        $output = $this->render();

        $this->assertStringContainsString('Checked Orders!', $output);
        $this->assertTrue($order->pf_data()->payment_completed);
    }

    /**
     * The sweep calls the Payflex API and can complete orders, so a link an
     * admin is tricked into loading must not be able to trigger it.
     */
    public function test_the_force_check_sweep_is_ignored_without_a_valid_nonce(): void
    {
        $this->gateway();
        $this->withLimits();
        $order = $this->order([
            'id'           => '7004',
            'date_created' => time() - 120,
            'meta'         => [
                '_payflex_order_id'        => 'PF-7004',
                '_payflex_order_token'     => 'tok',
                '_payflex_workflow_status' => 'initiated',
            ],
        ]);

        PF_State::stub_json(200, ['orderStatus' => 'Approved'], '/order/');
        PF_State::$user_can = true;
        $_GET = ['force_cron' => 'Force Check'];

        $output = $this->render();

        $this->assertStringNotContainsString('Checked Orders!', $output);
        $this->assertFalse($order->pf_data()->payment_completed);
    }

    public function test_the_order_lookup_renders_the_remote_order_details(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['payflex_order_id' => 'PF-LOOKUP-1'];
        PF_State::stub_json(200, [
            'orderStatus'       => 'Approved',
            'orderId'           => 'PF-LOOKUP-1',
            'merchantReference' => 'INV-42',
            'amount'            => 750.50,
            'createdDateTime'   => '2026-01-15T10:30:00Z',
            'consumer'          => [
                'givenNames' => 'Thandi',
                'surname'    => 'Mokoena',
                'email'      => 'thandi@example.test',
            ],
        ], '/order/PF-LOOKUP-1');

        $output = $this->render();

        $this->assertStringContainsString('Order Details', $output);
        $this->assertStringContainsString('Thandi', $output);
        $this->assertStringContainsString('Mokoena', $output);
        $this->assertStringContainsString('INV-42', $output);
        $this->assertStringContainsString('750.5', $output);
        $this->assertStringContainsString('2026-01-15 10:30:00', $output);
        $this->assertStringContainsString('mailto:thandi@example.test', $output);
    }

    public function test_the_order_lookup_reports_an_unknown_id(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['payflex_order_id' => 'PF-NOPE'];
        PF_State::stub_json(404, ['message' => 'Not found'], '/order/PF-NOPE');

        $this->assertStringContainsString('Order ID not found', $this->render());
    }

    /* --------------------------------------------------------------------- */

    /**
     * payflex_order_id is reflected straight back into the lookup field's
     * value attribute, so a quote in the query string must not be able to
     * close that attribute and add its own. sanitize_text_field() alone does
     * not do this: it strips tags but leaves quotes intact.
     */
    public function test_a_quote_in_the_looked_up_order_id_cannot_break_out_of_the_value_attribute(): void
    {
        $this->gateway();
        $this->withLimits();

        $payload = '" autofocus onfocus=alert(document.domain) x="';

        // WordPress slashes request data before a page callback ever sees it.
        $_GET = ['payflex_order_id' => addslashes($payload)];
        PF_State::stub_json(404, ['message' => 'Not found'], '/order/' . $payload);

        $output = $this->render();

        // The payload text itself is fine to display -- what matters is that
        // it stays inside the value attribute instead of becoming markup.
        $this->assertStringContainsString(
            'value="&quot; autofocus onfocus=alert(document.domain) x=&quot;"',
            $output,
            'The reflected order ID must come back with its quotes encoded'
        );

        $this->assertMatchesRegularExpression(
            '#<input type="text" id="payflex_order_id" name="payflex_order_id" value="[^"]*" style="width:300px;" placeholder="Order ID">#',
            $output,
            'The lookup field must not gain any attribute from the query string'
        );
    }

    /**
     * A failed lookup still renders the form, so escaping cannot rely on the
     * remote call succeeding.
     */
    public function test_an_ordinary_order_id_is_reflected_unchanged(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['payflex_order_id' => 'PF-NOPE'];
        PF_State::stub_json(404, ['message' => 'Not found'], '/order/PF-NOPE');

        $this->assertStringContainsString('value="PF-NOPE"', $this->render());
    }

    /**
     * Order details come from the Payflex API rather than the request, but a
     * hostile or compromised response must not reach the page as markup.
     */
    public function test_the_order_details_from_the_api_are_escaped(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['payflex_order_id' => 'PF-XSS'];
        PF_State::stub_json(200, [
            'orderStatus'       => '<script>alert(1)</script>',
            'orderId'           => 'PF-XSS',
            'merchantReference' => '<img src=x onerror=alert(2)>',
            'amount'            => 750.50,
            'createdDateTime'   => '2026-01-15T10:30:00Z',
            'consumer'          => [
                'givenNames' => '<svg onload=alert(3)>',
                'surname'    => 'Mokoena',
                'email'      => '"onmouseover="alert(4)',
            ],
        ], '/order/PF-XSS');

        $output = $this->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        $this->assertStringNotContainsString('<img src=x', $output);
        $this->assertStringNotContainsString('<svg onload', $output);
        $this->assertStringNotContainsString('"onmouseover="', $output);

        // The values must still be readable by support staff, just inert.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
        $this->assertStringContainsString('Mokoena', $output);
    }

    /**
     * redirect_url is echoed into an href and a hidden input, so an off-site
     * value must be rejected rather than turned into an open redirect.
     */
    public function test_an_off_site_redirect_url_is_rejected(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['redirect_url' => 'https://evil.test/steal'];

        $output = $this->render();

        $this->assertStringNotContainsString('evil.test', $output);
        $this->assertStringNotContainsString('Back to Previous Page', $output);
    }

    public function test_a_same_host_redirect_url_is_accepted(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['redirect_url' => urlencode('https://example.test/wp-admin/admin.php?page=wc-orders')];

        $output = $this->render();

        $this->assertStringContainsString('Back to Previous Page', $output);
        $this->assertStringContainsString('page=wc-orders', $output);
    }

    public function test_a_malformed_redirect_url_is_rejected(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['redirect_url' => 'not-a-url-at-all'];

        $this->assertStringNotContainsString('Back to Previous Page', $this->render());
    }

    public function test_a_protocol_relative_redirect_url_is_rejected(): void
    {
        $this->gateway();
        $this->withLimits();

        $_GET = ['redirect_url' => urlencode('//evil.test/steal')];

        $output = $this->render();

        $this->assertStringNotContainsString('evil.test', $output);
    }

    public function test_the_page_always_links_back_to_the_payflex_settings(): void
    {
        $this->gateway();
        $this->withLimits();

        $this->assertStringContainsString(
            'page=wc-settings&tab=checkout&section=payflex',
            $this->render()
        );
    }
}
