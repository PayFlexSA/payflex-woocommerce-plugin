<?php
/**
 * Minimal WordPress function/class stubs.
 *
 * Only the surface the Payflex plugin actually touches is implemented, and
 * behaviour deliberately mirrors WordPress where the plugin depends on it
 * (e.g. transient expiry, wp_remote_* response shape, is_wp_error).
 *
 * All mutable state lives in PF_State so tests can arrange and assert on it.
 */

/* -------------------------------------------------------------------------
 * Errors and exceptions
 * ---------------------------------------------------------------------- */

class WP_Error
{
    protected array $errors = [];
    public array $error_data = [];

    public function __construct($code = '', $message = '', $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code()
    {
        $codes = array_keys($this->errors);
        return $codes ? $codes[0] : '';
    }

    public function get_error_message($code = '')
    {
        $code = $code ?: $this->get_error_code();
        return isset($this->errors[$code][0]) ? $this->errors[$code][0] : '';
    }

    public function get_error_messages($code = '')
    {
        return $code ? ($this->errors[$code] ?? []) : array_merge(...array_values($this->errors ?: [[]]));
    }
}

/**
 * Thrown by the wp_redirect() stub in place of the exit that follows it in
 * production, so redirect-and-exit code paths remain testable.
 */
class PF_RedirectException extends RuntimeException
{
    public function __construct(public string $url)
    {
        parent::__construct('Redirect to ' . $url);
    }
}

/* -------------------------------------------------------------------------
 * Hooks
 * ---------------------------------------------------------------------- */

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    PF_State::$hooks[$hook][$priority][] = $callback;
    return true;
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    return add_action($hook, $callback, $priority, $accepted_args);
}

function remove_action($hook, $callback, $priority = 10)
{
    if (!isset(PF_State::$hooks[$hook][$priority])) return false;
    PF_State::$hooks[$hook][$priority] = array_values(array_filter(
        PF_State::$hooks[$hook][$priority],
        fn($cb) => $cb !== $callback
    ));
    return true;
}

function has_action($hook, $callback = false)
{
    if (!isset(PF_State::$hooks[$hook])) return false;
    if ($callback === false) return true;

    foreach (PF_State::$hooks[$hook] as $priority => $callbacks) {
        foreach ($callbacks as $cb) {
            if ($cb === $callback) return $priority;
        }
    }
    return false;
}

function has_filter($hook, $callback = false)
{
    return has_action($hook, $callback);
}

function do_action($hook, ...$args)
{
    if (!isset(PF_State::$hooks[$hook])) return;
    $by_priority = PF_State::$hooks[$hook];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func_array($callback, $args);
        }
    }
}

function apply_filters($hook, $value, ...$args)
{
    if (!isset(PF_State::$hooks[$hook])) return $value;
    $by_priority = PF_State::$hooks[$hook];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = call_user_func_array($callback, array_merge([$value], $args));
        }
    }
    return $value;
}

function add_shortcode($tag, $callback)
{
    PF_State::$hooks['shortcode_' . $tag][10][] = $callback;
}

function register_activation_hook($file, $callback)
{
    PF_State::$hooks['activate_' . basename(dirname($file)) . '/' . basename($file)][10][] = $callback;
}

function register_deactivation_hook($file, $callback)
{
    PF_State::$hooks['deactivate_' . basename(dirname($file)) . '/' . basename($file)][10][] = $callback;
}

/* -------------------------------------------------------------------------
 * Options and transients
 * ---------------------------------------------------------------------- */

function get_option($option, $default = false)
{
    return array_key_exists($option, PF_State::$options) ? PF_State::$options[$option] : $default;
}

function update_option($option, $value, $autoload = null)
{
    PF_State::$options[$option] = $value;
    return true;
}

function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (array_key_exists($option, PF_State::$options)) return false;
    PF_State::$options[$option] = $value;
    return true;
}

function delete_option($option)
{
    unset(PF_State::$options[$option]);
    return true;
}

function get_transient($transient)
{
    if (!isset(PF_State::$transients[$transient])) return false;

    $entry = PF_State::$transients[$transient];

    // A non-zero expiry in the past means the transient is gone, as in core.
    if ($entry['expires'] !== 0 && $entry['expires'] <= time()) {
        unset(PF_State::$transients[$transient]);
        return false;
    }

    return $entry['value'];
}

function set_transient($transient, $value, $expiration = 0)
{
    $expiration = (int) $expiration;

    // Core stores 0 as "never expires"; a negative value expires immediately.
    $expires = $expiration === 0 ? 0 : time() + $expiration;

    PF_State::$transients[$transient] = ['value' => $value, 'expires' => $expires];
    return true;
}

function delete_transient($transient)
{
    $existed = isset(PF_State::$transients[$transient]);
    unset(PF_State::$transients[$transient]);
    return $existed;
}

/* -------------------------------------------------------------------------
 * Post meta (legacy order storage)
 * ---------------------------------------------------------------------- */

function get_post_meta($post_id, $key = '', $single = false)
{
    $values = PF_State::$post_meta[(string) $post_id][$key] ?? [];

    if ($single) {
        return $values ? $values[0] : '';
    }

    return $values;
}

function update_post_meta($post_id, $key, $value, $prev = '')
{
    PF_State::$post_meta[(string) $post_id][$key] = [$value];
    return true;
}

function add_post_meta($post_id, $key, $value, $unique = false)
{
    PF_State::$post_meta[(string) $post_id][$key][] = $value;
    return true;
}

function delete_post_meta($post_id, $key, $value = '')
{
    unset(PF_State::$post_meta[(string) $post_id][$key]);
    return true;
}

function get_comments($args = [])
{
    return [];
}

/* -------------------------------------------------------------------------
 * Plugin / environment helpers
 * ---------------------------------------------------------------------- */

function is_plugin_active($plugin)
{
    return in_array($plugin, PF_State::$active_plugins, true);
}

function is_plugin_active_for_network($plugin)
{
    return false;
}

function get_plugins()
{
    $plugins = [];
    foreach (PF_State::$active_plugins as $plugin) {
        $plugins[$plugin] = ['Name' => $plugin, 'Version' => '1.0.0'];
    }
    // A couple of inactive plugins so counts differ from active_plugins.
    $plugins['akismet/akismet.php']   = ['Name' => 'Akismet', 'Version' => '5.0'];
    $plugins['hello.php']             = ['Name' => 'Hello Dolly', 'Version' => '1.7'];
    return $plugins;
}

function plugin_basename($file)
{
    $file = str_replace('\\', '/', $file);

    // The plugin's real WP_PLUGIN_DIR entry is always "payflex-payment-gateway",
    // regardless of what the checkout directory on disk happens to be named
    // (e.g. the git repo folder in CI). Rewrite the known plugin root rather
    // than guessing from a literal "/plugins/" segment in the path.
    if (defined('PAYFLEX_PLUGIN_ROOT')) {
        $root = str_replace('\\', '/', PAYFLEX_PLUGIN_ROOT);
        if (strpos($file, $root) === 0) {
            return 'payflex-payment-gateway' . substr($file, strlen($root));
        }
    }

    $marker = '/plugins/';
    $pos = strpos($file, $marker);
    if ($pos !== false) {
        return substr($file, $pos + strlen($marker));
    }
    return ltrim($file, '/');
}

function plugin_dir_path($file)
{
    return rtrim(dirname($file), '/') . '/';
}

function plugin_dir_url($file)
{
    return 'https://example.test/wp-content/plugins/' . dirname(plugin_basename($file)) . '/';
}

function plugins_url($path = '', $plugin = '')
{
    return 'https://example.test/wp-content/plugins/' . dirname(plugin_basename($plugin)) . '/' . ltrim($path, '/');
}

function admin_url($path = '')
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function home_url($path = '')
{
    return PF_State::$home_url . ltrim($path, '/');
}

function site_url($path = '')
{
    return PF_State::$site_url . ltrim($path, '/');
}

function is_ssl()
{
    return PF_State::$is_ssl;
}

function get_bloginfo($show = '')
{
    return $show === 'version' ? PF_State::$wp_version : 'Test Store';
}

function is_admin()
{
    return PF_State::$is_admin;
}

function current_user_can($capability)
{
    return PF_State::$user_can;
}

function wp_get_update_data()
{
    return ['counts' => ['plugins' => 0, 'themes' => 0, 'wordpress' => 0, 'translations' => 0, 'total' => 0]];
}

function human_time_diff($from, $to = 0)
{
    $diff = abs(($to ?: time()) - $from);
    return $diff < 60 ? $diff . ' seconds' : round($diff / 60) . ' mins';
}

function add_submenu_page($parent, $page_title, $menu_title, $capability, $slug, $callback = '', $position = null)
{
    PF_State::$submenu_pages[] = compact('parent', 'page_title', 'menu_title', 'capability', 'slug', 'callback');
    return $slug;
}

function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Scripts, styles, blocks
 * ---------------------------------------------------------------------- */

function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = [])
{
    PF_State::$scripts[] = ['handle' => $handle, 'src' => $src, 'action' => 'enqueue'];
    return true;
}

function wp_register_script($handle, $src = '', $deps = [], $ver = false, $args = [])
{
    PF_State::$scripts[] = ['handle' => $handle, 'src' => $src, 'action' => 'register'];
    return true;
}

function wp_localize_script($handle, $object_name, $l10n)
{
    PF_State::$scripts[] = ['handle' => $handle, 'src' => $object_name, 'action' => 'localize', 'data' => $l10n];
    return true;
}

function register_block_type($name, $args = [])
{
    PF_State::$blocks[] = ['name' => $name, 'args' => $args];
    return true;
}

/* -------------------------------------------------------------------------
 * Cron
 * ---------------------------------------------------------------------- */

function wp_next_scheduled($hook, $args = [])
{
    return PF_State::$scheduled[$hook] ?? false;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = [])
{
    PF_State::$schedule_calls[] = ['hook' => $hook, 'timestamp' => $timestamp, 'recurrence' => $recurrence];
    PF_State::$scheduled[$hook] = $timestamp;
    return true;
}

function wp_clear_scheduled_hook($hook, $args = [])
{
    PF_State::$cleared_hooks[] = $hook;
    unset(PF_State::$scheduled[$hook]);
    return 1;
}

/* -------------------------------------------------------------------------
 * HTTP
 * ---------------------------------------------------------------------- */

function wp_remote_request($url, $args = [])
{
    $method = strtoupper($args['method'] ?? 'GET');
    PF_State::$http_log[] = ['method' => $method, 'url' => $url, 'args' => $args];
    return PF_State::next_response($url);
}

function wp_remote_get($url, $args = [])
{
    $args['method'] = 'GET';
    return wp_remote_request($url, $args);
}

function wp_remote_post($url, $args = [])
{
    $args['method'] = $args['method'] ?? 'POST';
    return wp_remote_request($url, $args);
}

function wp_remote_retrieve_body($response)
{
    if (is_wp_error($response) || !is_array($response)) return '';
    return $response['body'] ?? '';
}

function wp_remote_retrieve_response_code($response)
{
    if (is_wp_error($response) || !is_array($response)) return '';
    return $response['response']['code'] ?? '';
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

/* -------------------------------------------------------------------------
 * Redirects and output
 * ---------------------------------------------------------------------- */

function wp_redirect($location, $status = 302, $x_redirect_by = 'WordPress')
{
    PF_State::$redirects[] = $location;
    throw new PF_RedirectException($location);
}

function wp_safe_redirect($location, $status = 302)
{
    return wp_redirect($location, $status);
}

/* -------------------------------------------------------------------------
 * Escaping, sanitising, i18n
 * ---------------------------------------------------------------------- */

function __($text, $domain = 'default')            { return $text; }
function _e($text, $domain = 'default')            { echo $text; }
function esc_html__($text, $domain = 'default')    { return esc_html($text); }
function esc_attr__($text, $domain = 'default')    { return esc_attr($text); }
function esc_html_e($text, $domain = 'default')    { echo esc_html($text); }
function esc_attr_e($text, $domain = 'default')    { echo esc_attr($text); }
function _n($single, $plural, $number, $domain = 'default') { return $number === 1 ? $single : $plural; }

function esc_html($text)  { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text)  { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url)    { return filter_var((string) $url, FILTER_SANITIZE_URL) ?: ''; }
function esc_js($text)    { return addslashes((string) $text); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }

function sanitize_text_field($str)
{
    $str = (string) $str;
    $str = strip_tags($str);
    $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
    return trim($str);
}

function sanitize_url($url, $protocols = null)
{
    return esc_url($url);
}

function sanitize_html_class($class, $fallback = '')
{
    $sanitised = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $class);
    return $sanitised === '' ? $fallback : $sanitised;
}

function wp_strip_all_tags($string, $remove_breaks = false)
{
    $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string);
    return trim(strip_tags($string));
}

function wp_kses_post($data)
{
    return (string) $data;
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function wp_parse_args($args, $defaults = [])
{
    return array_merge($defaults, (array) $args);
}

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}

function absint($value)
{
    return abs((int) $value);
}
