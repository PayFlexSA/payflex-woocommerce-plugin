<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

/**
 * Registration wiring: hooks, the gateway registration filter, WooCommerce
 * feature compatibility flags and the block-checkout integration.
 *
 * A missing hook here does not throw — the feature simply never appears — so
 * each one is asserted explicitly.
 */
final class HooksTest extends PF_TestCase
{
    public function test_the_gateway_is_added_to_the_woocommerce_payment_gateways_list(): void
    {
        $this->assertNotFalse(has_filter('woocommerce_payment_gateways', 'payflex_add_gateway'));
        $this->assertContains('WC_Gateway_PartPay', payflex_add_gateway([]));
    }

    public function test_registering_the_gateway_preserves_the_existing_gateways(): void
    {
        $this->assertSame(
            ['WC_Gateway_COD', 'WC_Gateway_PartPay'],
            payflex_add_gateway(['WC_Gateway_COD'])
        );
    }

    public function test_the_plugin_basename_helper_points_at_the_main_file(): void
    {
        $this->assertSame('payflex-payment-gateway/partpay.php', payflex_plugin_basename());
    }

    public function test_a_settings_link_is_added_to_the_plugins_screen(): void
    {
        $actions = apply_filters('plugin_action_links_payflex-payment-gateway/partpay.php', ['deactivate' => 'x']);

        $this->assertContains('deactivate', array_keys($actions));
        $this->assertStringContainsString(
            'page=wc-settings&tab=checkout&section=payflex',
            implode('', $actions)
        );
    }

    public function test_the_plugin_constants_point_at_the_plugin_directory(): void
    {
        $this->assertTrue(defined('PAYFLEX_PLUGIN_URL'));
        $this->assertTrue(defined('PAYFLEX_PLUGIN_DIR'));
        $this->assertSame(PAYFLEX_PLUGIN_ROOT . '/', PAYFLEX_PLUGIN_DIR);
        $this->assertStringEndsWith('/payflex-payment-gateway/', PAYFLEX_PLUGIN_URL);
    }

    /* --------------------------------------------------------------------- */

    /**
     * WordPress renames a plugin's folder to resolve a name collision on
     * upload (observed in production as "woocommerce_"). That used to disable
     * the whole plugin silently: is_plugin_active() no longer matched the
     * hardcoded 'woocommerce/woocommerce.php' path, so partpay.php returned
     * before registering the gateway, with no error anywhere and the plugin
     * still showing as "Active" on the Plugins screen.
     */
    public function test_woocommerce_is_detected_at_its_standard_path(): void
    {
        PF_State::$active_plugins = ['woocommerce/woocommerce.php'];

        $this->assertTrue(payflex_is_woocommerce_active());
    }

    public function test_woocommerce_is_still_detected_when_its_folder_has_been_renamed(): void
    {
        PF_State::$active_plugins = ['woocommerce_/woocommerce.php'];

        $this->assertTrue(payflex_is_woocommerce_active());
    }

    public function test_woocommerce_is_not_detected_when_it_is_not_installed(): void
    {
        PF_State::$active_plugins = ['some-other-plugin/some-other-plugin.php'];

        $this->assertFalse(payflex_is_woocommerce_active());
    }

    /* --------------------------------------------------------------------- */

    /**
     * Without these declarations WooCommerce shows an incompatibility warning
     * and can refuse to enable HPOS or block checkout.
     */
    public function test_compatibility_is_declared_for_hpos_and_block_checkout(): void
    {
        do_action('before_woocommerce_init');

        $this->assertTrue(FeaturesUtil::pf_declared('custom_order_tables'), 'HPOS compatibility not declared');
        $this->assertTrue(FeaturesUtil::pf_declared('cart_checkout_blocks'), 'Block checkout compatibility not declared');
    }

    public function test_compatibility_declarations_reference_the_main_plugin_file(): void
    {
        do_action('before_woocommerce_init');

        foreach (FeaturesUtil::$declarations as $declaration) {
            $this->assertSame(
                PAYFLEX_PLUGIN_ROOT . '/partpay.php',
                $declaration['file'],
                'Compatibility must be declared against the main plugin file'
            );
        }
    }

    /* --------------------------------------------------------------------- */

    public function test_the_block_checkout_payment_method_is_registered(): void
    {
        $this->set_settings();

        do_action('woocommerce_blocks_loaded');

        $registry = new PaymentMethodRegistry();
        do_action('woocommerce_blocks_payment_method_type_registration', $registry);

        $this->assertCount(1, $registry->registered);
        $this->assertInstanceOf(WC_Payflex_Blocks::class, $registry->registered[0]);
    }

    public function test_the_block_payment_method_is_named_payflex(): void
    {
        $blocks = new WC_Payflex_Blocks();
        $blocks->initialize();

        $this->assertSame('payflex', $blocks->get_name(), 'Must match the gateway id for block checkout to bind');
    }

    public function test_the_block_payment_method_follows_the_enabled_setting(): void
    {
        $this->set_settings(['enabled' => 'yes']);
        $enabled = new WC_Payflex_Blocks();
        $enabled->initialize();
        $this->assertTrue($enabled->is_active());

        $this->set_settings(['enabled' => 'no']);
        $disabled = new WC_Payflex_Blocks();
        $disabled->initialize();
        $this->assertFalse($disabled->is_active());
    }

    public function test_the_block_payment_method_exposes_the_title_and_description(): void
    {
        $this->set_settings(['title' => 'Payflex BNPL']);
        $blocks = new WC_Payflex_Blocks();
        $blocks->initialize();

        $data = $blocks->get_payment_method_data();

        $this->assertSame('Payflex BNPL', $data['title']);
        $this->assertStringContainsString('interest-free', $data['description']);
    }

    public function test_the_block_checkout_script_is_registered(): void
    {
        $this->set_settings();
        $blocks = new WC_Payflex_Blocks();
        $blocks->initialize();

        $handles = $blocks->get_payment_method_script_handles();

        $this->assertSame(['wc-payflex-blocks-integration', 'wc-payflex-eligibility'], $handles);
        $this->assertContains('wc-payflex-blocks-integration', array_column(PF_State::$scripts, 'handle'));
        $this->assertContains('wc-payflex-eligibility', array_column(PF_State::$scripts, 'handle'));
    }

    public function test_the_block_checkout_script_file_exists(): void
    {
        $this->assertFileExists(PAYFLEX_PLUGIN_ROOT . '/assets/checkout.js');
    }

    /* --------------------------------------------------------------------- */

    public function test_the_widget_block_is_registered_when_the_gateway_is_enabled(): void
    {
        $this->set_settings(['enabled' => 'yes']);

        payflex_register_widget_block();

        $this->assertSame(['payflex/widget'], array_column(PF_State::$blocks, 'name'));
        $this->assertContains('payflex-widget-block', array_column(PF_State::$scripts, 'handle'));
    }

    public function test_the_widget_block_is_not_registered_when_the_gateway_is_disabled(): void
    {
        $this->set_settings(['enabled' => 'no']);

        payflex_register_widget_block();

        $this->assertSame([], PF_State::$blocks);
    }

    public function test_the_widget_block_declares_a_render_callback(): void
    {
        $this->set_settings(['enabled' => 'yes']);

        payflex_register_widget_block();

        $this->assertSame('payflex_render_widget_block', PF_State::$blocks[0]['args']['render_callback']);
    }

    /**
     * payflex_register_widget_block() calls filemtime() on this file, which
     * would emit a warning and register a broken asset version if it were gone.
     */
    public function test_the_widget_block_script_file_exists(): void
    {
        $this->assertFileExists(PAYFLEX_PLUGIN_ROOT . '/assets/block.js');
    }

    public function test_block_editor_variables_expose_the_plugin_url_and_widget_markup(): void
    {
        $this->set_settings();
        $GLOBALS['product'] = new WC_Product(101, 'SKU-101', 500.00);

        payflex_block_vars();

        $localised = array_values(array_filter(
            PF_State::$scripts,
            fn($script) => $script['action'] === 'localize'
        ));

        $this->assertNotEmpty($localised);
        $this->assertSame(PAYFLEX_PLUGIN_URL, $localised[0]['data']['pluginUrl']);
        $this->assertStringContainsString('payflexCalculatorWidgetContainer', $localised[0]['data']['payflex_widget']);
    }

    /* --------------------------------------------------------------------- */

    public function test_the_product_page_widget_hook_matches_the_wordpress_version(): void
    {
        // WordPress 6.3+ uses woocommerce_before_add_to_cart_form; the plugin
        // decides this once at load time against the running WP version.
        $this->assertNotFalse(
            has_action('woocommerce_before_add_to_cart_form', 'payflex_widget_content'),
            'Expected the 6.3+ hook for the WordPress version under test'
        );
    }

    public function test_the_shortcode_is_registered(): void
    {
        $this->assertNotFalse(has_action('shortcode_payflex_widget', 'payflex_widget_shortcode_content'));
    }

    public function test_the_support_page_is_registered_on_the_admin_menu(): void
    {
        $this->assertNotFalse(
            has_action('admin_menu', ['WC_Gateway_PartPay', 'register_support_page'])
        );
    }

    public function test_the_gateway_registers_its_woocommerce_hooks(): void
    {
        $gateway = $this->gateway();

        $this->assertNotFalse(has_action('woocommerce_receipt_payflex', [$gateway, 'receipt_page']));
        $this->assertNotFalse(has_action('woocommerce_api_wc_gateway_partpay', [$gateway, 'payment_callback']));
        $this->assertNotFalse(has_filter('woocommerce_available_payment_gateways', [$gateway, 'check_cart_within_limits']));
        $this->assertNotFalse(has_action('woocommerce_update_options_payment_gateways_payflex', [$gateway, 'process_admin_options']));
        $this->assertNotFalse(has_action('woocommerce_update_options_payment_gateways_payflex', [$gateway, 'on_save_settings']));
    }

    /**
     * Both classic surfaces need the notice: the cart renders one, the checkout
     * renders the other above the payment methods.
     */
    public function test_the_eligibility_notice_hooks_are_registered(): void
    {
        $callback = ['WC_Gateway_PartPay', 'render_eligibility_notice'];

        $this->assertNotFalse(has_action('woocommerce_before_cart', $callback));
        $this->assertNotFalse(has_action('woocommerce_review_order_before_payment', $callback));
    }

    /**
     * The gateway is constructed more than once per request — WC_Payment_Gateways,
     * the blocks integration and the singleton each build their own. An instance
     * callback would be stored once per instance and print the notice again for
     * each, so the registration lives in the bootstrap instead.
     */
    public function test_the_eligibility_notice_is_registered_once_however_many_gateways_exist(): void
    {
        $this->gateway();
        $this->gateway();
        WC_Gateway_PartPay::instance();

        foreach (['woocommerce_before_cart', 'woocommerce_review_order_before_payment'] as $hook) {
            $this->assertCount(
                1,
                $this->eligibility_callbacks($hook),
                $hook . ' must hold exactly one eligibility notice callback'
            );
        }
    }

    /** Every registered callback on $hook that renders the eligibility notice. */
    private function eligibility_callbacks(string $hook): array
    {
        $found = [];

        foreach (PF_State::$hooks[$hook] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback) && ($callback[1] ?? '') === 'render_eligibility_notice') {
                    $found[] = $callback;
                }
            }
        }

        return $found;
    }

    public function test_the_product_exclusion_panel_is_registered(): void
    {
        $this->assertNotFalse(has_action('woocommerce_product_options_general_product_data', 'payflex_product_exclusion_field'));
        $this->assertNotFalse(has_action('woocommerce_process_product_meta', 'payflex_save_product_exclusion_field'));
    }

    public function test_the_product_exclusion_checkbox_is_rendered(): void
    {
        $this->set_settings();
        PF_State::$current_post_id = 101;

        ob_start();
        payflex_product_exclusion_field();
        ob_end_clean();

        $this->assertCount(1, PF_State::$product_fields);
        $this->assertSame(Payflex_Eligibility::PRODUCT_META, PF_State::$product_fields[0]['id']);
    }

    public function test_the_product_exclusion_checkbox_is_hidden_when_the_setting_is_off(): void
    {
        $this->set_settings(['enable_product_exclusions' => 'no']);

        ob_start();
        payflex_product_exclusion_field();
        ob_end_clean();

        $this->assertSame([], PF_State::$product_fields);
    }

    public function test_the_product_exclusion_checkbox_saves_yes_and_no(): void
    {
        $this->set_settings();

        $_POST[Payflex_Eligibility::PRODUCT_META] = 'yes';
        payflex_save_product_exclusion_field(101);
        $this->assertSame('yes', get_post_meta(101, Payflex_Eligibility::PRODUCT_META, true));

        unset($_POST[Payflex_Eligibility::PRODUCT_META]);
        payflex_save_product_exclusion_field(101);
        $this->assertSame('no', get_post_meta(101, Payflex_Eligibility::PRODUCT_META, true));
    }

    /**
     * The Cart and Checkout blocks read eligibility off the Store API cart
     * response, so the endpoint data has to be registered on woocommerce_blocks_loaded.
     */
    public function test_the_store_api_cart_eligibility_data_is_registered(): void
    {
        do_action('woocommerce_blocks_loaded');

        $payflex = array_values(array_filter(
            PF_State::$store_api_endpoints,
            fn($args) => ($args['namespace'] ?? '') === 'payflex'
        ));

        $this->assertCount(1, $payflex);
        $this->assertSame('cart', $payflex[0]['endpoint']);
        $this->assertSame('payflex_cart_eligibility_data', $payflex[0]['data_callback']);
        $this->assertSame('payflex_cart_eligibility_schema', $payflex[0]['schema_callback']);
    }

    public function test_the_block_eligibility_script_file_exists(): void
    {
        $this->assertFileExists(PAYFLEX_PLUGIN_ROOT . '/assets/payflex-eligibility.js');
    }

    /**
     * The limits filter runs at priority 99 so it sees the final gateway list
     * after other plugins have added or removed methods.
     */
    public function test_the_limits_filter_runs_late(): void
    {
        $gateway = $this->gateway();

        $this->assertSame(
            99,
            has_filter('woocommerce_available_payment_gateways', [$gateway, 'check_cart_within_limits'])
        );
    }

    /* --------------------------------------------------------------------- */

    /**
     * When a shopper backs out on the Payflex pages they return with
     * status=cancelled; the order is marked abandoned and sent to the cart.
     */
    public function test_cancelling_on_the_payflex_pages_abandons_the_order(): void
    {
        $this->gateway();
        $order = $this->order([
            'order_key' => 'wc_order_cancelkey',
            'meta'      => ['_partpay_order_id' => 'PF-CANCEL-1'],
        ]);

        $_GET = ['status' => 'cancelled', 'key' => 'wc_order_cancelkey', 'token' => 'tok'];
        PF_State::stub_json(200, ['orderStatus' => 'Created'], '/order/');

        $redirect = $this->captureRedirect(fn() => do_action('template_redirect'));

        $this->assertStringContainsString('cancel_order=true', $redirect);
        $this->assertSame('abandoned', $order->get_meta('_payflex_workflow_status'));
        $this->assertStringContainsString(
            'Payment cancelled by the customer',
            implode("\n", array_column($order->pf_data()->notes, 'content'))
        );
    }

    public function test_the_cancel_handler_ignores_requests_without_the_cancelled_status(): void
    {
        $this->gateway();
        $order = $this->order(['meta' => ['_partpay_order_id' => 'PF-CANCEL-1']]);

        $_GET = ['key' => 'wc_order_testkey', 'token' => 'tok'];

        do_action('template_redirect');

        $this->assertSame([], PF_State::$redirects);
        $this->assertSame('', $order->get_meta('_payflex_workflow_status'));
    }

    public function test_the_cancel_handler_ignores_an_unknown_order_key(): void
    {
        $this->gateway();
        $_GET = ['status' => 'cancelled', 'key' => 'wc_order_nosuchkey', 'token' => 'tok'];

        do_action('template_redirect');

        $this->assertSame([], PF_State::$redirects);
    }
}
