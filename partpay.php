<?php
/*
 * Plugin Name: Payflex Payment Gateway
 * Description: Payflex payment gateway plugin for WooCommerce. Supports pay now as well as buy now pay later.
 * Version: 2.6.9
 * Author: Payflex
 * Author URI: https://payflex.co.za/
 * WC requires at least: 6.0
 * WC tested up to: 9.9.4
*/


/**
 * Check if WooCommerce is activated
 */

if (!function_exists('is_plugin_active') || !function_exists('is_plugin_active_for_network'))
{
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$woocommerce_active = is_plugin_active('woocommerce/woocommerce.php') || is_plugin_active_for_network('woocommerce/woocommerce.php');
if (!$woocommerce_active) return;

# We need to set a global variable for the product page widget, otherwise it won't work in some themes
global $payflex_product_page_widget_displayed;
$payflex_product_page_widget_displayed = false;

/**
 * Gets a Payflex option from the database, if none is provided, it returns all options.
 */
function get_payflex_option($option = FALSE)
{
    $payflex_settings = get_option('woocommerce_payflex_settings', array());
    
    if(!isset($payflex_settings) || !is_array($payflex_settings))
    {
        $payflex_settings = [];
    }

    if ($option)
    {
        if (isset($payflex_settings[$option]))
        {
            return $payflex_settings[$option];
        }
        return false;
    }

    return $payflex_settings;
}

/**
 * Add settings link on plugin page
 */
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), function ( $actions )
{
    return array_merge( $actions, [
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payflex' ) . '">Settings</a>',
    ]);
} );



function payflex_plugin_basename()
{
    return plugin_basename(__FILE__);
}

function woocommerce_add_payflex_gateway($methods)
{
    $methods[] = 'WC_Gateway_PartPay';
    return $methods;
}

add_action('plugins_loaded', function(){
    // Base plugin url
    define('PAYFLEX_PLUGIN_URL', plugin_dir_url(__FILE__));
    // Base plugin directory
    define('PAYFLEX_PLUGIN_DIR', plugin_dir_path(__FILE__));

    require_once( plugin_basename( 'includes/class-wc-gateway-payflex.php' ) );

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_payflex_gateway');
}, 0);


/**
 * Check for the CANCELLED payment status
 * We have to do this before the gateway initialises because WC clears the cart before initialising the gateway
 *
 * @since 1.0.0
 */

add_action('template_redirect', function()
{
    // Check if the payment was cancelled
    if (isset($_GET['status']) && $_GET['status'] == "cancelled" && isset($_GET['key']) && isset($_GET['token']))
    {

        if(isset($gateway) && $gateway instanceof WC_Gateway_PartPay)
        {
            $gateway = WC_Gateway_PartPay::instance();
        }
        else
        {
            $gateway = new WC_Gateway_PartPay();
        }
        
        $key = sanitize_text_field($_GET['key']);
        $order_id = wc_get_order_id_by_order_key($key);

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        if ($order)
        {

            $partpay_order_id = $order->get_meta('_partpay_order_id');

            # Get the order id from the post meta if it's not found in the order meta, this is for legacy orders
            if(!$partpay_order_id)
                $partpay_order_id = get_post_meta($order_id, '_partpay_order_id', true);

            $obj = new WC_Gateway_PartPay();
            $ordUrl = $obj->getOrderUrl();
            $response = wp_remote_get($ordUrl . '/' . $partpay_order_id, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $obj->get_payflex_authorization_code() ,
                )
            ));
            $body = json_decode(wp_remote_retrieve_body($response));
            if ($body->orderStatus != "Approved" OR $gateway->get_payflex_workflow_status($order_id) != 'abandoned')
            {
                $gateway->log('Order ' . $order_id . ' payment cancelled by the customer while on the Payflex checkout pages.');
                $order->add_order_note(__('Payment cancelled by the customer while on the Payflex checkout page.', 'woo_payflex'));

                $gateway->set_payflex_workflow_status($order_id, 'abandoned');

                if (method_exists($order, "get_cancel_order_url_raw"))
                {
                    wp_redirect($order->get_cancel_order_url_raw());
                }
                else
                {
                    wp_redirect($order->get_cancel_order_url());
                }
                exit;
            }
            $redirect = $order->get_checkout_payment_url(true);

            return array(
                'result' => 'success',
                'redirect' => $redirect
            );
        }
    }
});


/**
 * Custom function to declare compatibility with cart_checkout_blocks feature 
*/
function declare_cart_checkout_blocks_compatibility() {
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');


/**
 * Add block based checkout support assets/payflex-block-checkout.js
 */

// add_action('enqueue_block_assets', function(){
//     wp_enqueue_script('payflex-block-checkout', PAYFLEX_PLUGIN_URL . 'assets/payflex-block-checkout.js', array('wp-blocks', 'wp-element', 'wp-editor'), filemtime(PAYFLEX_PLUGIN_DIR . 'assets/payflex-block-checkout.js'));
// });

// Hook the custom function to the 'woocommerce_blocks_loaded' action

add_action( 'woocommerce_blocks_loaded', 'oawoo_register_order_approval_payment_method_type' );
/**
 * Custom function to register a payment method type
 */
function oawoo_register_order_approval_payment_method_type() {

    // Check if the required class exists
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }
    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . 'includes/class-payflex-woocommerce-block-checkout.php';
    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            // Register an instance of WC_payflex_Blocks
            $payment_method_registry->register( new WC_Payflex_Blocks );
        }
    );
}

/**
 * Call the cron task related methods in the gateway
 *
 * @since 1.0.0
 *
 */

add_action('payflex_do_cron_jobs', function (){

    # Make sure we're not running the cron job on the checkout page
    if(is_checkout()) return;

    $gateway = WC_Gateway_Partpay::instance();

    $gateway->check_pending_abandoned_orders();
    $gateway->update_payment_limits();
});

add_action('init', function ()
{
    # If cron "partpay_do_cron_jobs" still exists, delete it
    if(wp_next_scheduled('partpay_do_cron_jobs'))
        wp_clear_scheduled_hook('partpay_do_cron_jobs');

    # Check if the cron job is already scheduled
    if (wp_next_scheduled('payflex_do_cron_jobs')) return;

    # Make sure plugin is active
    if (!is_plugin_active('payflex-payment-gateway/partpay.php')) return;

    # Schedule the cron job
    wp_schedule_event(time() , 'twominutes', 'payflex_do_cron_jobs');
});

/* WP-Cron activation and schedule setup */

/**
 * Schedule Payflex WP-Cron job
 *
 * @since 1.0.0
 *
 */
function payflex_create_wpcronjob()
{
    $timestamp = wp_next_scheduled('payflex_do_cron_jobs');
    if ($timestamp == false)
    {
        wp_schedule_event(time() , 'twominutes', 'payflex_do_cron_jobs');
    }
}

register_activation_hook(__FILE__, 'payflex_create_wpcronjob');


/**
 * Delete PartPay WP-Cron job
 *
 * @since 1.0.0
 *
 */
function payflex_delete_wpcronjob()
{
    wp_clear_scheduled_hook('payflex_do_cron_jobs');
}

register_deactivation_hook(__FILE__, 'payflex_delete_wpcronjob');



/**
 * Add a new WP-Cron job scheduling interval of every 2 minutes
 *
 * @param  array $schedules
 * @return array Array of schedules with 2 minutes added
 * @since 1.0.0
 *
 */

add_filter('cron_schedules', function ($schedules)
{
    $schedules['twominutes'] = array(
        'interval' => 120, // seconds
        'display'  => __('Every 2 minutes', 'woo_payflex')
    );
    return $schedules;
});



// FUNCTION - Frontend show on single product page
function widget_content()
{

    if(payflex_product_widget_enabled() == false) return;

    global $payflex_product_page_widget_displayed;
    $payflex_product_page_widget_displayed = true;

    echo woo_payflex_frontend_widget();

}
global $wp_version;
if($wp_version >= 6.3){
    add_action('woocommerce_before_add_to_cart_form', 'widget_content', 0);
}else{
    add_action('woocommerce_single_product_summary', 'widget_content', 12);
}


function widget_shortcode_content()
{
    return woo_payflex_frontend_widget();
}

add_shortcode('payflex_widget', 'widget_shortcode_content');


function woo_payflex_frontend_widget($amount = false)
{
    global $product, $payflex_product_page_widget_displayed;

    if(!$product) return;

    $payflex_settings = get_payflex_option();

    if ($product->get_type() === 'subscription') return;

    if(!$amount){

        $amount = wc_get_price_including_tax($product);
    }
    $amount_string = '&amount='.$amount;

    # Defaults
    $all_options        = '';
    $merchant_reference = false;
    $theme_div          = '';
    $theme              = '';
    $widget_style_div   = '';
    $widget_style       = '';
    $pay_type_div       = '';
    $pay_type           = '';

    if(isset($payflex_settings['widget_style']) AND $payflex_settings['widget_style'])
    {
        $payflex_settings['widget_style'] = sanitize_text_field($payflex_settings['widget_style']);

        $widget_style_div = 'data-widget-style="'.$payflex_settings['widget_style'].'" ';
        $widget_style     = '&logo_type='.$payflex_settings['widget_style'];
    }

    if(isset($payflex_settings['widget_theme']) AND $payflex_settings['widget_theme'])
    {
        $payflex_settings['widget_theme'] = sanitize_text_field($payflex_settings['widget_theme']);

        $theme_div = 'data-theme="'.$payflex_settings['widget_theme'].'" ';
        $theme     = '&theme='.$payflex_settings['widget_theme'];
    }


    if(isset($payflex_settings['pay_type']) AND $payflex_settings['pay_type'])
    {
        $payflex_settings['pay_type'] = sanitize_text_field($payflex_settings['pay_type']);

        $pay_type_div = 'data-pay_type="'.$payflex_settings['pay_type'].'" ';
        $pay_type     = '&pay_type='.$payflex_settings['pay_type'];
    }

    if(isset($payflex_settings['merchant_widget_reference']) AND $payflex_settings['merchant_widget_reference'])
        # Make sure the merchant reference is set and is url freindly
        $merchant_reference = preg_replace('/[^a-zA-Z0-9_]/', '', $payflex_settings['merchant_widget_reference']);

    $all_options = $amount_string.$widget_style.$theme.$pay_type;
    $all_div_options = $widget_style_div.$theme_div.$pay_type_div;

    $payflex_product_page_widget_displayed = true;

    if($merchant_reference){
        return '<div class="payflexCalculatorWidgetContainer" '.$all_div_options.'><script async src="https://widgets.payflex.co.za/'.$merchant_reference.'/payflex-widget-2.0.1.js?type=calculator'.$all_options.'" type="application/javascript"></script></div>';
    }
    return '<div class="payflexCalculatorWidgetContainer" '.$all_div_options.'><script async src="https://widgets.payflex.co.za/payflex-widget-2.0.1.js?type=calculator'.$all_options.'" type="application/javascript"></script></div>';
}

// Register support page. This needs to be outside the class otherwise it won't be called soon enough
add_action('admin_menu', ['WC_Gateway_PartPay', 'register_support_page']);


// Payflex JS payflexBlockVars
function payflex_block_vars() {
    $payflex_block_vars = [
        'pluginUrl' => PAYFLEX_PLUGIN_URL,
        'payflex_widget' => woo_payflex_frontend_widget(),
    ];
    wp_localize_script('payflex-widget-block', 'payflexBlockVars', $payflex_block_vars);
}
add_action('enqueue_block_editor_assets', 'payflex_block_vars');



// Register the block
function register_payflex_widget_block() {
    if(payflex_enabled() == false) return;

    wp_register_script(
        'payflex-widget-block',
        plugins_url('assets/block.js', __FILE__),
        array('wp-blocks', 'wp-element', 'wp-editor'),
        filemtime(plugin_dir_path(__FILE__) . 'assets/block.js')
    );

    register_block_type('payflex/widget', array(
        'editor_script' => 'payflex-widget-block',
        'render_callback' => 'render_payflex_widget_block',
    ));
}
add_action('init', 'register_payflex_widget_block');

// Render the block
function render_payflex_widget_block($attributes) {
    ob_start();
    // If were in the page builder, just show an image, if were rendering the block on the front end, show the widget
    if (is_admin()) {
        echo '<img src="' . plugins_url('assets/widget-icon.png', __FILE__) . '" alt="Payflex Widget" />';
    } else {
        echo woo_payflex_frontend_widget();
    }
    return ob_get_clean();
}

function payflex_enabled()
{
    // Check if gateway is enabled
    if(get_payflex_option('enabled') !== 'yes') return false;

    // Check admin only mode
    if(payflex_admin_only_enabled())
    {
        if(!current_user_can('manage_options')) return false;
    }

    return true;
}


/**
 * Check if admin only mode is enabled
 */
function payflex_admin_only_enabled()
{
    if(get_payflex_option('admin_only_enabled') === 'yes') return true;

    return false;
}

function payflex_product_widget_enabled()
{
    if(payflex_enabled() == false) return false;

    if(get_payflex_option('enable_product_widget') === 'yes') return true;

    return false;
}

function payflex_checkout_widget_enabled()
{

    if(payflex_enabled() == false) return false;
    // Check if cart total is within payment limits
    if (WC()->cart) {
        $cart_total = WC()->cart->get_total('edit');
        $gateway = WC_Gateway_PartPay::instance();
        $min_amount = $gateway->get_payflex_limits('amount_minimum');
        $max_amount = $gateway->get_payflex_limits('amount_maximum');
        
        if ($cart_total < $min_amount || $cart_total > $max_amount) {
            return false;
        }
    }

    if(get_payflex_option('enable_checkout_widget') === 'yes') return true;

    return false;
}

function payflex_environment()
{
    $payflex_settings = get_payflex_option();

    if(isset($payflex_settings['testmode']))
    {
        if(strtolower($payflex_settings['testmode']) == 'production')
            return 'production';

        if(strtolower($payflex_settings['testmode']) == 'develop')
            return 'develop';
    }

    return 'unknown';
}   

// Variation price update
add_action( 'woocommerce_after_single_product', 'payflex_update_price_on_variation' );
function payflex_update_price_on_variation() {
    global $product , $payflex_product_page_widget_displayed;
    
    # return if widget is disabled
    if(payflex_product_widget_enabled() == false AND !$payflex_product_page_widget_displayed) return;

    $payflex = WC_Gateway_PartPay::instance();

    $debug_mode = $payflex->get_debug_mode();

    if(!$product) return;

        ?>
        <script>
            <?php
            // Pass debug mode to js
            if($debug_mode){
                echo 'var debug_mode = true;';
            }else{
                echo 'var debug_mode = false;';
            }
            ?>
            // Function to get the price
            function getWooCommercePrice() {
                // woocommerce-Price-amount amount
                var price = jQuery('.woocommerce-Price-amount.amount').first().text();
                price = price.replace(/[^0-9]/g, '');
                price = price.slice(0, -2) + '.' + price.slice(-2);
                return price;
            }

            // Listen for changes in the price (useful for variable products)
            jQuery(document).on('found_variation', function(event, variation) {
                // Make sure the display price has two decimal places
                var price = variation.display_price;
                price = price.toFixed(2);

                var pay_type = jQuery('.payflexCalculatorWidgetContainer').data('pay_type');

                PayflexWidget.update(price, pay_type);

                if(debug_mode) console.log('Payflex Debug: Widget price updated on variation change: ' + price);
            });

            // Listen for changes in the price (useful for simple products)
            jQuery(document).on('updated_wc_div', function() { // Can be trigged with jQuery( document.body ).trigger( 'updated_wc_div' );
                var price = getWooCommercePrice();
                var pay_type = jQuery('.payflexCalculatorWidgetContainer').data('pay_type');

                PayflexWidget.update(price, pay_type);

                if(debug_mode) console.log('Payflex Debug: Widget price updated on price change: ' + price);
            });

        </script>
        <?php

}

/**
 * All functions related to Woocommerce orders are handled through Woocommerce hooks, meaning we support Woocommerces HPOS and Custom Order Tables.
 * We just need to declare compatibility with the custom order tables feature.
 */
add_action('before_woocommerce_init', function(){
    if ( !class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) return;

    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
});