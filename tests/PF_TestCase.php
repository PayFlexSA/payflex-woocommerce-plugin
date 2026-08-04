<?php

use PHPUnit\Framework\TestCase;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Internal\Admin\Logging\Settings as WC_Logging_Settings;

/**
 * Base class: resets all stub state and the gateway's static/singleton state
 * between tests, and provides arrangement helpers.
 */
abstract class PF_TestCase extends TestCase
{
    /** A complete, valid settings array — the "happy path" configuration. */
    public const VALID_SETTINGS = [
        'enabled'                 => 'yes',
        'title'                   => 'Payflex',
        'testmode'                => 'production',
        'client_id'               => 'test-client-id',
        'client_secret'           => 'test-client-secret',
        'widget_style'            => 'purple',
        'widget_theme'            => '',
        'pay_type'                => '4',
        'enable_product_widget'   => 'yes',
        'enable_checkout_widget'  => 'yes',
        'admin_only_enabled'      => 'no',
        'payflex_debug'           => 'no',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        PF_State::reset();

        // Clear the gateway singleton and static logger between tests.
        $reflection = new ReflectionClass(WC_Gateway_PartPay::class);
        $instance = $reflection->getProperty('_instance');
        $instance->setValue(null, null);

        WC_Gateway_PartPay::$log         = false;
        WC_Gateway_PartPay::$log_enabled = true;

        FeaturesUtil::$declarations           = [];
        WC_Logging_Settings::$logging_enabled = true;
    }

    protected function tearDown(): void
    {
        PF_State::reset();
        parent::tearDown();
    }

    /**
     * Write the gateway settings option.
     *
     * @param array $overrides Merged over VALID_SETTINGS.
     * @param bool  $from_scratch When true, $overrides is used verbatim.
     */
    protected function set_settings(array $overrides = [], bool $from_scratch = false): array
    {
        $settings = $from_scratch ? $overrides : array_merge(self::VALID_SETTINGS, $overrides);
        update_option('woocommerce_payflex_settings', $settings);
        return $settings;
    }

    /**
     * Build a gateway instance with settings applied and a cached access token
     * already in place, so construction makes no HTTP calls.
     *
     * Pass $with_cached_token = false when the test is about authentication.
     */
    protected function gateway(array $overrides = [], bool $with_cached_token = true): WC_Gateway_PartPay
    {
        $this->set_settings($overrides);

        if ($with_cached_token) {
            set_transient('payflex_access_token', 'cached-access-token', 3600);
            set_transient('payflex_access_token_date', time(), 3600);
        }

        return new WC_Gateway_PartPay();
    }

    /**
     * Configure Payflex limits: writes them to the settings option and installs
     * a standing /configuration response so the repeated refresh that
     * get_payflex_limits() performs returns the same values.
     */
    protected function withLimits(float $min = 50.0, float $max = 20000.0, bool $refunds = true): void
    {
        $settings = get_option('woocommerce_payflex_settings', []);
        $settings['payflex_limit_amount_minimum']  = $min;
        $settings['payflex_limit_amount_maximum']  = $max;
        $settings['payflex_limit_refunds_enabled'] = $refunds;
        $settings['payflex_limit_last_updated']    = time();
        update_option('woocommerce_payflex_settings', $settings);

        PF_State::stub_json(200, [
            'minimumAmount'     => $min,
            'maximumAmount'     => $max,
            'enabledForRefunds' => $refunds,
        ], '/configuration');
    }

    /**
     * Create a fake order with one line item.
     */
    protected function order(array $props = []): WC_Order
    {
        $defaults = [
            'id'           => '1001',
            'total'        => 500.00,
            'status'       => 'pending',
            'date_created' => time(),
            'items'        => [new WC_Order_Item('Test Product', 1, 500.00, 101, 0)],
        ];

        return PF_State::make_order(array_merge($defaults, $props));
    }

    /**
     * Run a callable that is expected to end in wp_redirect()+exit and return
     * the redirect target.
     */
    protected function captureRedirect(callable $callback): string
    {
        try {
            $callback();
        } catch (PF_RedirectException $e) {
            return $e->url;
        }

        $this->fail('Expected a redirect, but none happened.');
    }

    /**
     * Decode the JSON body of the nth recorded HTTP request (0-indexed).
     */
    protected function requestBody(int $index = 0): array
    {
        $this->assertArrayHasKey($index, PF_State::$http_log, 'No HTTP request recorded at index ' . $index);
        $body = PF_State::$http_log[$index]['args']['body'] ?? '';
        return json_decode(is_string($body) ? $body : json_encode($body), true) ?? [];
    }

    /**
     * Assert that at least one logged message contains $needle.
     */
    protected function assertLogged(string $needle): void
    {
        $this->assertStringContainsString($needle, PF_State::log_text());
    }

    /**
     * Assert that a wc_add_notice() call of the given type contains $needle.
     */
    protected function assertNotice(string $needle, string $type = 'error'): void
    {
        $messages = [];
        foreach (PF_State::$notices as $notice) {
            if ($notice['type'] === $type) $messages[] = $notice['message'];
        }

        $this->assertStringContainsString($needle, implode("\n", $messages));
    }
}
