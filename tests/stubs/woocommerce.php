<?php
/**
 * Minimal WooCommerce stubs: the gateway base class, orders, products, cart
 * and the handful of wc_* functions the Payflex plugin calls.
 *
 * WC_Order is a thin facade over PF_Order_Data held in PF_State::$orders, so
 * that `new WC_Order( $id )` and `wc_get_order( $id )` share the same state —
 * the plugin mixes both freely.
 */

/* -------------------------------------------------------------------------
 * Orders
 * ---------------------------------------------------------------------- */

/** Backing store for a single fake order. */
class PF_Order_Data
{
    public string $id = '0';
    public string $status = 'pending';
    public string $order_key = 'wc_order_testkey';
    public string $order_number = '';
    public float $total = 500.00;
    public float $total_tax = 0.0;
    public float $shipping_total = 0.0;
    public string $transaction_id = '';
    public string $payment_method = 'payflex';
    public array $meta = [];
    /** @var list<array{content:string,customer:bool}> */
    public array $notes = [];
    /** @var list<WC_Order_Item> */
    public array $items = [];
    /** @var list<object> */
    public array $refunds = [];
    public int $date_created = 0;
    public bool $payment_completed = false;
    public array $billing = [
        'phone'      => '0821234567',
        'first_name' => 'Test',
        'last_name'  => 'Customer',
        'email'      => 'test@example.test',
        'address_1'  => '1 Test Street',
        'address_2'  => 'Unit 2',
        'city'       => 'Cape Town',
        'postcode'   => '8001',
    ];
    public array $shipping = [
        'address_1' => '1 Test Street',
        'address_2' => 'Unit 2',
        'city'      => 'Cape Town',
        'postcode'  => '8001',
    ];
}

class WC_Order
{
    protected PF_Order_Data $data;

    public function __construct($order_id = 0)
    {
        $order_id = (string) $order_id;

        if (!isset(PF_State::$orders[$order_id])) {
            $data = new PF_Order_Data();
            $data->id = $order_id;
            $data->date_created = time();
            PF_State::$orders[$order_id] = $data;
        }

        $this->data = PF_State::$orders[$order_id];
    }

    /** Direct access to the backing store, for test arrangement/assertions. */
    public function pf_data(): PF_Order_Data
    {
        return $this->data;
    }

    public function get_id()             { return $this->data->id; }
    public function get_order_key()      { return $this->data->order_key; }
    public function get_order_number()   { return $this->data->order_number !== '' ? $this->data->order_number : $this->data->id; }
    public function get_total()          { return $this->data->total; }
    public function get_total_tax()      { return $this->data->total_tax; }
    public function get_shipping_total() { return $this->data->shipping_total; }
    public function get_transaction_id() { return $this->data->transaction_id; }
    public function get_payment_method() { return $this->data->payment_method; }
    public function get_status()         { return $this->data->status; }
    public function get_items($types = 'line_item') { return $this->data->items; }
    public function get_refunds()        { return $this->data->refunds; }
    public function get_date_created()   { return $this->data->date_created; }

    public function get_billing_phone()      { return $this->data->billing['phone']; }
    public function get_billing_first_name() { return $this->data->billing['first_name']; }
    public function get_billing_last_name()  { return $this->data->billing['last_name']; }
    public function get_billing_email()      { return $this->data->billing['email']; }
    public function get_billing_address_1()  { return $this->data->billing['address_1']; }
    public function get_billing_address_2()  { return $this->data->billing['address_2']; }
    public function get_billing_city()       { return $this->data->billing['city']; }
    public function get_billing_postcode()   { return $this->data->billing['postcode']; }

    public function get_shipping_address_1() { return $this->data->shipping['address_1']; }
    public function get_shipping_address_2() { return $this->data->shipping['address_2']; }
    public function get_shipping_city()      { return $this->data->shipping['city']; }
    public function get_shipping_postcode()  { return $this->data->shipping['postcode']; }

    public function has_status($status)
    {
        return is_array($status)
            ? in_array($this->data->status, $status, true)
            : $this->data->status === $status;
    }

    public function update_status($status, $note = '', $manual = false)
    {
        // Core accepts both 'wc-cancelled' and 'cancelled'; strip the prefix
        // only, not the individual characters.
        $this->data->status = str_starts_with((string) $status, 'wc-')
            ? substr((string) $status, 3)
            : (string) $status;

        return true;
    }

    public function get_meta($key, $single = true, $context = 'view')
    {
        return $this->data->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value, $meta_id = 0)
    {
        $this->data->meta[$key] = $value;
    }

    public function delete_meta_data($key)
    {
        unset($this->data->meta[$key]);
    }

    public function save()
    {
        return $this->data->id;
    }

    /**
     * Mirrors core: private notes ($is_customer_note = 0) are not returned by
     * get_customer_order_notes().
     */
    public function add_order_note($note, $is_customer_note = 0, $added_by_user = false)
    {
        $this->data->notes[] = ['content' => $note, 'customer' => (bool) $is_customer_note];
        return count($this->data->notes);
    }

    public function get_customer_order_notes()
    {
        $notes = [];
        foreach ($this->data->notes as $note) {
            if (!$note['customer']) continue;
            $notes[] = (object) ['comment_content' => $note['content']];
        }
        return $notes;
    }

    public function payment_complete($transaction_id = '')
    {
        $this->data->transaction_id    = (string) $transaction_id;
        $this->data->status            = 'processing';
        $this->data->payment_completed = true;
        return true;
    }

    public function get_checkout_payment_url($on_checkout = false)
    {
        return 'https://example.test/checkout/order-pay/' . $this->data->id . '/?key=' . $this->data->order_key;
    }

    /**
     * Core builds this from the checkout page permalink, which comes from
     * home_url() - so it inherits a relative home_url() the same way.
     */
    public function get_checkout_order_received_url()
    {
        return home_url('checkout/order-received/' . $this->data->id . '/?key=' . $this->data->order_key);
    }

    public function get_cancel_order_url($redirect = '')
    {
        return 'https://example.test/cart/?cancel_order=true&order_id=' . $this->data->id;
    }

    public function get_cancel_order_url_raw($redirect = '')
    {
        return 'https://example.test/cart/?cancel_order=true&order_id=' . $this->data->id . '&raw=1';
    }
}

/** Order line item: the plugin uses both method and array access. */
class WC_Order_Item implements ArrayAccess
{
    public function __construct(
        public string $name = 'Test Product',
        public int $quantity = 1,
        public float $line_subtotal = 100.00,
        public int $product_id = 101,
        public int $variation_id = 0
    ) {}

    public function get_quantity()     { return $this->quantity; }
    public function get_product_id()   { return $this->product_id; }
    public function get_variation_id() { return $this->variation_id; }
    public function get_name()         { return $this->name; }

    public function offsetExists(mixed $offset): bool { return property_exists($this, (string) $offset); }
    public function offsetGet(mixed $offset): mixed   { return $this->{$offset} ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->{$offset} = $value; }
    public function offsetUnset(mixed $offset): void  { }
}

class WC_Product
{
    public function __construct(
        public int $id = 101,
        public string $sku = 'SKU-101',
        public float $price = 100.00,
        public string $type = 'simple'
    ) {}

    public function get_id()    { return $this->id; }
    public function get_sku()   { return $this->sku; }
    public function get_price() { return $this->price; }
    public function get_type()  { return $this->type; }
    public function get_regular_price() { return $this->price; }
}

/* -------------------------------------------------------------------------
 * Cart / WC() container
 * ---------------------------------------------------------------------- */

class PF_Cart
{
    public float $total;

    public function __construct(float $total)
    {
        $this->total = $total;
    }

    public function get_total($context = 'view')
    {
        return $context === 'edit' ? $this->total : wc_price($this->total);
    }
}

class PF_WooCommerce
{
    public string $version;

    public function __construct()
    {
        $this->version = PF_State::$wc_version;
    }

    public function __get($name)
    {
        if ($name === 'cart') {
            return PF_State::$cart_total === null ? null : new PF_Cart(PF_State::$cart_total);
        }
        return null;
    }
}

function WC()
{
    $wc = $GLOBALS['woocommerce'] ?? null;
    if (!$wc instanceof PF_WooCommerce) {
        $wc = $GLOBALS['woocommerce'] = new PF_WooCommerce();
    }
    $wc->version = PF_State::$wc_version;
    return $wc;
}

/* -------------------------------------------------------------------------
 * Logging
 * ---------------------------------------------------------------------- */

class WC_Logger
{
    public function log($level, $message, $context = [])
    {
        PF_State::$logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function add($handle, $message)
    {
        $this->log('info', $message);
    }

    public function __call($name, $arguments)
    {
        $this->log($name, $arguments[0] ?? '');
    }
}

/* -------------------------------------------------------------------------
 * Settings API / gateway base class
 * ---------------------------------------------------------------------- */

abstract class WC_Settings_API
{
    public $plugin_id = 'woocommerce_';
    public $id = '';
    public $settings = [];
    public $form_fields = [];
    public $errors = [];
    public $data = [];

    public function get_option_key()
    {
        return $this->plugin_id . $this->id . '_settings';
    }

    public function get_form_fields()
    {
        return apply_filters('woocommerce_settings_api_form_fields_' . $this->id, $this->form_fields);
    }

    /**
     * Mirrors core: saved options win; defaults are only applied when no option
     * row exists at all.
     */
    public function init_settings()
    {
        $this->settings = get_option($this->get_option_key(), null);

        if (!$this->settings || !is_array($this->settings)) {
            $this->settings = [];
            foreach ($this->get_form_fields() as $key => $field) {
                $this->settings[$key] = $field['default'] ?? '';
            }
        }
    }

    public function get_option($key, $empty_value = null)
    {
        if (!isset($this->settings[$key])) {
            $this->settings[$key] = $this->form_fields[$key]['default'] ?? '';
        }

        if (!is_null($empty_value) && '' === $this->settings[$key]) {
            return $empty_value;
        }

        return $this->settings[$key];
    }

    public function get_field_key($key)
    {
        return $this->plugin_id . $this->id . '_' . $key;
    }

    public function get_post_data()
    {
        return $_POST;
    }

    public function get_field_value($key, $field, $post_data = [])
    {
        $field_key = $this->get_field_key($key);
        $post_data = $post_data ?: $this->get_post_data();
        $value     = $post_data[$field_key] ?? null;
        $type      = $field['type'] ?? 'text';

        if ('checkbox' === $type) {
            return isset($post_data[$field_key]) ? 'yes' : 'no';
        }

        $validator = 'validate_' . $type . '_field';
        if (is_callable([$this, $validator])) {
            return $this->{$validator}($key, $value);
        }

        return $this->validate_text_field($key, $value);
    }

    public function process_admin_options()
    {
        $this->init_settings();

        $post_data = $this->get_post_data();

        foreach ($this->get_form_fields() as $key => $field) {
            $type = $field['type'] ?? 'text';
            if (in_array($type, ['title', 'section_start', 'section_end', 'widget_preview'], true)) {
                continue;
            }
            $this->settings[$key] = $this->get_field_value($key, $field, $post_data);
        }

        return update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings));
    }

    public function validate_text_field($key, $value)
    {
        return is_null($value) ? '' : wp_kses_post(trim(wp_unslash((string) $value)));
    }

    public function validate_password_field($key, $value)
    {
        return is_null($value) ? '' : trim(wp_unslash((string) $value));
    }

    public function validate_select_field($key, $value)
    {
        return is_null($value) ? '' : wp_unslash((string) $value);
    }

    public function validate_checkbox_field($key, $value)
    {
        return !is_null($value) ? 'yes' : 'no';
    }

    public function validate_textarea_field($key, $value)
    {
        return is_null($value) ? '' : trim(wp_unslash((string) $value));
    }

    /**
     * Renders each field via generate_{type}_html() when the gateway defines
     * one, so custom field renderers are exercised.
     */
    public function generate_settings_html($form_fields = [], $echo = true)
    {
        if (empty($form_fields)) {
            $form_fields = $this->get_form_fields();
        }

        $html = '';
        foreach ($form_fields as $key => $field) {
            $type   = $field['type'] ?? 'text';
            $method = 'generate_' . $type . '_html';

            if (is_callable([$this, $method])) {
                $html .= $this->{$method}($key, $field);
            } else {
                $html .= '<tr><th>' . esc_html($field['title'] ?? $key) . '</th><td>'
                    . '<input type="' . esc_attr($type) . '" name="' . esc_attr($this->get_field_key($key)) . '" '
                    . 'id="' . esc_attr($this->get_field_key($key)) . '" value="' . esc_attr($this->get_option($key)) . '" /></td></tr>';
            }
        }

        if ($echo) {
            echo $html;
            return '';
        }

        return $html;
    }
}

abstract class WC_Payment_Gateway extends WC_Settings_API
{
    public $title = '';
    public $description = '';
    public $chosen = false;
    public $has_fields = false;
    public $method_title = '';
    public $method_description = '';
    public $icon = '';
    public $supports = ['products'];
    public $enabled = 'yes';
    public $max_amount = 0;
    public $order_button_text = '';
    public $view_transaction_url = '';
    public $new_method_label = '';
    public $pay_button_id = '';
    public $tokens = [];

    public function is_available()
    {
        return 'yes' === $this->enabled;
    }

    public function supports($feature)
    {
        return in_array($feature, $this->supports, true);
    }

    public function get_return_url($order = null)
    {
        return $order
            ? $order->get_checkout_order_received_url()
            : 'https://example.test/checkout/order-received/?key=none';
    }

    public function get_title()       { return apply_filters('woocommerce_gateway_title', $this->title, $this->id); }
    public function get_description() { return $this->description; }
    public function get_icon()        { return $this->icon; }

    public function process_payment($order_id)
    {
        return ['result' => 'success'];
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        return false;
    }

    public function payment_fields() {}
    public function admin_options() {}
    public function validate_fields() { return true; }
    public function add_payment_method() {}
}

/* -------------------------------------------------------------------------
 * wc_* functions
 * ---------------------------------------------------------------------- */

function wc_get_order($order_id = 0)
{
    if (!$order_id) return false;
    if (!isset(PF_State::$orders[(string) $order_id])) return false;
    return new WC_Order($order_id);
}

function wc_get_order_id_by_order_key($order_key)
{
    foreach (PF_State::$orders as $id => $data) {
        if ($data->order_key === $order_key) return $id;
    }
    return 0;
}

/**
 * Supports the subset of wc_get_orders() query args the plugin uses:
 * status, payment_method, date_created ("from...to") and a single meta_query.
 */
function wc_get_orders($args = [])
{
    $results = [];

    foreach (PF_State::$orders as $id => $data) {
        if (isset($args['status'])) {
            $statuses = (array) $args['status'];
            if (!in_array($data->status, $statuses, true)) continue;
        }

        if (isset($args['payment_method']) && $data->payment_method !== $args['payment_method']) {
            continue;
        }

        if (isset($args['date_created']) && str_contains((string) $args['date_created'], '...')) {
            [$from, $to] = explode('...', (string) $args['date_created'], 2);
            if ($data->date_created < (int) $from || $data->date_created > (int) $to) continue;
        }

        if (isset($args['meta_query'])) {
            $matched = true;
            foreach ($args['meta_query'] as $clause) {
                if (!is_array($clause) || !isset($clause['key'])) continue;
                $actual  = $data->meta[$clause['key']] ?? null;
                $compare = $clause['compare'] ?? '=';
                if ($compare === '=' && $actual !== ($clause['value'] ?? null)) {
                    $matched = false;
                    break;
                }
            }
            if (!$matched) continue;
        }

        $results[] = new WC_Order($id);
    }

    return $results;
}

function wc_get_product($product_id = 0)
{
    return new WC_Product((int) $product_id, 'SKU-' . $product_id);
}

function wc_get_price_including_tax($product, $args = [])
{
    return $product instanceof WC_Product ? $product->get_price() : 0.0;
}

function wc_price($price, $args = [])
{
    return 'R' . number_format((float) $price, 2, '.', ',');
}

function wc_add_notice($message, $notice_type = 'success', $data = [])
{
    PF_State::$notices[] = ['message' => $message, 'type' => $notice_type];
}

function wc_get_notices($type = '')
{
    return PF_State::$notices;
}

function wc_empty_cart()
{
    PF_State::$cart_emptied = true;
    PF_State::$cart_total   = null;
}

function is_checkout()
{
    return PF_State::$is_checkout;
}

function is_cart()
{
    return false;
}

/** Minimal WC_Order_Refund: only get_refund_amount() is used by the plugin. */
class WC_Order_Refund
{
    public function __construct(private float $amount = 0.0) {}

    public function get_refund_amount() { return $this->amount; }
    public function get_amount()        { return $this->amount; }
}
