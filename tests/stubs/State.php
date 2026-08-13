<?php
/**
 * Shared, resettable state for the WordPress/WooCommerce stubs.
 *
 * Everything the stubbed WP functions read or write lives here so that a test
 * can arrange state, run plugin code, and then assert on what was recorded.
 */
final class PF_State
{
    /** @var array<string,mixed> get_option()/update_option() store */
    public static array $options = [];

    /** @var array<string,array{value:mixed,expires:int}> transient store */
    public static array $transients = [];

    /** @var array<string,array<string,array>> post_id => meta_key => list of values */
    public static array $post_meta = [];

    /** @var array<string,PF_Order_Data> order id => order data */
    public static array $orders = [];

    /** @var array<string,array<callable>> "$hook|$priority" registry */
    public static array $hooks = [];

    /** @var list<array{match:?string,response:mixed}> queued HTTP responses, consumed in order */
    public static array $http_queue = [];

    /** @var list<array{match:?string,response:mixed}> standing responses, never consumed */
    public static array $http_standing = [];

    /** @var list<array{method:string,url:string,args:array}> every HTTP call made */
    public static array $http_log = [];

    /** @var list<array{level:string,message:string}> WC_Logger output */
    public static array $logs = [];

    /** @var list<array{message:string,type:string}> wc_add_notice() calls */
    public static array $notices = [];

    /** @var list<string> wp_redirect() targets */
    public static array $redirects = [];

    /** @var list<array{handle:string,src:string}> registered/enqueued scripts */
    public static array $scripts = [];

    /** @var array<string,int|false> wp_next_scheduled() results, keyed by hook */
    public static array $scheduled = [];

    /** @var list<array{hook:string,timestamp:int}> wp_schedule_event() calls */
    public static array $schedule_calls = [];

    /** @var list<string> wp_clear_scheduled_hook() calls */
    public static array $cleared_hooks = [];

    /** @var list<array> add_submenu_page() calls */
    public static array $submenu_pages = [];

    /** @var list<array{name:string,args:array}> register_block_type() calls */
    public static array $blocks = [];

    /** True when the current user should pass current_user_can(). */
    public static bool $user_can = false;

    public static bool $is_admin = false;

    public static bool $is_checkout = false;

    /**
     * Base returned by home_url()/site_url(), with a trailing slash. Set to a
     * relative value such as '/' to model a site where WP_HOME or a plugin has
     * made home_url() root-relative.
     */
    public static string $home_url = 'https://example.test/';

    public static string $site_url = 'https://example.test/';

    public static bool $is_ssl = true;

    public static string $wp_version = '6.8.3';

    public static string $wc_version = '9.9.4';

    /** Plugins reported as active by is_plugin_active(). */
    public static array $active_plugins = [
        'woocommerce/woocommerce.php',
        'payflex-payment-gateway/partpay.php',
    ];

    /** Fake cart total, or null for "no cart". */
    public static ?float $cart_total = null;

    /** Set to true once wc_empty_cart() has been called. */
    public static bool $cart_emptied = false;

    /**
     * Timestamp captured at reset(). The plugin calls the native time(), which
     * cannot be stubbed, so tests compare against this instead of a fixed value.
     */
    public static int $now = 0;

    /**
     * Reset everything to a clean slate. Called from PF_TestCase::setUp().
     *
     * Note: $hooks is deliberately NOT cleared — hooks are registered when the
     * plugin files are loaded once at bootstrap and cannot be re-registered.
     */
    public static function reset(): void
    {
        // 'active_plugins' always exists in a real install and the plugin
        // count()s it unguarded, so it must never be missing here either.
        self::$options        = ['active_plugins' => [
            'woocommerce/woocommerce.php',
            'payflex-payment-gateway/partpay.php',
        ]];
        self::$transients     = [];
        self::$post_meta      = [];
        self::$orders         = [];
        self::$http_queue     = [];
        self::$http_standing  = [];
        self::$http_log       = [];
        self::$logs           = [];
        self::$notices        = [];
        self::$redirects      = [];
        self::$scripts        = [];
        self::$scheduled      = [];
        self::$schedule_calls = [];
        self::$cleared_hooks  = [];
        self::$submenu_pages  = [];
        self::$blocks         = [];
        self::$user_can       = false;
        self::$is_admin       = false;
        self::$is_checkout    = false;
        self::$home_url       = 'https://example.test/';
        self::$site_url       = 'https://example.test/';
        self::$is_ssl         = true;
        self::$wp_version     = '6.8.3';
        self::$wc_version     = '9.9.4';
        self::$cart_total     = null;
        self::$cart_emptied   = false;
        self::$now            = time();
        self::$active_plugins = [
            'woocommerce/woocommerce.php',
            'payflex-payment-gateway/partpay.php',
        ];

        $GLOBALS['wp_version']  = self::$wp_version;
        $GLOBALS['product']     = null;
        $GLOBALS['woocommerce'] = new PF_WooCommerce();
        $GLOBALS['payflex_product_page_widget_displayed'] = false;

        $_GET  = [];
        $_POST = [];
        $_COOKIE = [];
        // phpunit.xml seeds this; restore it so a test that overrides the host
        // does not leak into the next one.
        $_SERVER['HTTP_HOST'] = 'example.test';
    }

    /**
     * Queue an HTTP response. Responses are consumed in order; if $match is
     * given the entry is only used for URLs containing that substring.
     *
     * @param mixed   $response WP HTTP response array or WP_Error
     * @param ?string $match    URL substring this entry applies to
     */
    public static function queue_response($response, ?string $match = null): void
    {
        self::$http_queue[] = ['match' => $match, 'response' => $response];
    }

    /**
     * Queue a JSON response with the given status code and decoded body.
     */
    public static function queue_json(int $code, $body, ?string $match = null): void
    {
        self::queue_response([
            'response' => ['code' => $code, 'message' => 'OK'],
            'body'     => is_string($body) ? $body : json_encode($body),
            'headers'  => [],
            'cookies'  => [],
        ], $match);
    }

    /**
     * Register a standing response: matched after the queue is exhausted and
     * never consumed. Use for endpoints the plugin polls repeatedly.
     */
    public static function stub_json(int $code, $body, ?string $match = null): void
    {
        self::$http_standing[] = [
            'match'    => $match,
            'response' => [
                'response' => ['code' => $code, 'message' => 'OK'],
                'body'     => is_string($body) ? $body : json_encode($body),
                'headers'  => [],
                'cookies'  => [],
            ],
        ];
    }

    /**
     * Queue a valid auth-token response, as the Payflex auth endpoint returns it.
     */
    public static function queue_auth_token(string $token = 'test-access-token', int $expires_in = 3600): void
    {
        self::queue_json(200, ['access_token' => $token, 'expires_in' => $expires_in], '/auth/merchant');
    }

    /**
     * Resolve the response for a request: the first matching queued entry
     * (which is then consumed), else the first matching standing entry, else a
     * WP_Error so an unstubbed call is obvious rather than silently empty.
     */
    public static function next_response(string $url)
    {
        foreach (self::$http_queue as $i => $entry) {
            if ($entry['match'] === null || str_contains($url, $entry['match'])) {
                unset(self::$http_queue[$i]);
                self::$http_queue = array_values(self::$http_queue);
                return $entry['response'];
            }
        }

        foreach (self::$http_standing as $entry) {
            if ($entry['match'] === null || str_contains($url, $entry['match'])) {
                return $entry['response'];
            }
        }

        return new WP_Error('pf_no_stubbed_response', 'No stubbed HTTP response for ' . $url);
    }

    /** URLs of every request made, in order. */
    public static function requested_urls(): array
    {
        return array_column(self::$http_log, 'url');
    }

    /**
     * Register a fake order and return it.
     */
    public static function make_order(array $props = []): WC_Order
    {
        $id = (string) ($props['id'] ?? (count(self::$orders) + 1));

        $data = new PF_Order_Data();
        $data->id = $id;

        foreach ($props as $key => $value) {
            if ($key === 'id') continue;
            if ($key === 'meta') {
                $data->meta = $value;
                continue;
            }
            $data->$key = $value;
        }

        self::$orders[$id] = $data;

        return new WC_Order($id);
    }

    /**
     * All logged messages joined, for convenient assertions.
     */
    public static function log_text(): string
    {
        return implode("\n", array_column(self::$logs, 'message'));
    }
}
