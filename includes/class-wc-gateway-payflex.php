<?php if ( ! defined( 'ABSPATH' ) ) exit;

use Automattic\WooCommerce\Internal\Admin\Logging\Settings as WC_Settings;

/**
 * Payflex Payment Gateway
 * 
 * Creates the Payflex Payment Gateway for WooCommerce.
 */
class WC_Gateway_PartPay extends WC_Payment_Gateway
{
    protected array  $environments = [];
    protected string $configurationUrl = '';
    protected string $orderurl = '';

    private $version = '2.6.9';

    /**
        * @var $_instance WC_Gateway_PartPay The reference to the singleton instance of this class
        */
    private static $_instance = NULL;

    /**
        * @var boolean Whether or not logging is enabled
        */
    public static $log_enabled = false;

    /**
        * @var WC_Logger Logger instance
        */
    public static $log = false;
    
    public $random_id = false;

    public $base_plugin_url = '';

    public $base_plugin_dir = '';

    /**
        * @var WC_Order Order instance
        */
    public $WC_Order = false;

    /**
        * @var WC_Order Order ID
        */
    public $WC_Order_ID = false;

    public $current_order_proccessed = false;

    public $debug_mode = false;

    public $payflex_worfklow_status = false;

    /**
        * Main WC_Gateway_PartPay Instance
        *
        * Used for WP-Cron jobs when
        *
        * @since 1.0
        * @return WC_Gateway_PartPay Main instance
        */
    public static function instance()
    {
        if (is_null(self::$_instance))
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct()
    {
        # Generate random ID for instance
        $this->random_id = uniqid();

        # Base plugin URL for assets, this is the root url to the plugin directory
        $this->base_plugin_url = (defined('PAYFLEX_PLUGIN_URL')) ? PAYFLEX_PLUGIN_URL : plugin_dir_url(__FILE__.'/../');

        # Base plugin directory for assets, this is the root directory to the plugin directory
        $this->base_plugin_dir = (defined('PAYFLEX_PLUGIN_DIR')) ? PAYFLEX_PLUGIN_DIR : plugin_dir_path(__FILE__.'/../');

        $this->id = 'payflex';
        $this->method_title = __('Payflex', 'woo_payflex');
        $this->method_description = __('Use Payflex as a credit card processor for WooCommerce.', 'woo_payflex');
        $this->icon = $this->plugin_url('Checkout.png');

        $this->supports = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_environment_config();

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Load the frontend scripts.
        $this->init_scripts_js();
        $this->init_scripts_css();
        $settings = get_payflex_option();

        $api_url = '';
        $this->configurationUrl = '';
        if (false !== $settings)
        {
            // Check debug mode
            if(isset($this->settings['payflex_debug'])) // Debug mode is fairly new, so we need to make sure the value is set
                $this->debug_mode = $this->settings['payflex_debug'] == 'yes' ? true : false;

            $api_url = '';
            if(isset($this->environments[get_payflex_option('testmode')]))
            {
                $api_url = $this->environments[get_payflex_option('testmode')]['api_url'];
            }
            $this->orderurl = $api_url . '/order';
            $this->configurationUrl = $api_url . '/configuration';
        }
        else
        {
            $api_url = '';
        }
        // Define user set variables
        $this->title = '';
        if (isset($this->settings['title']))
        {
            $this->title = $this->settings['title'];
        }
        $this->description = __('Pay for your order in either 4 interest-free payments over 6 weeks OR 3 interest-free payments over 3 paydays.', 'woo_payflex');

        self::$log_enabled = true;

        // Hooks
        add_action('woocommerce_receipt_' . $this->id, array(
            $this,
            'receipt_page'
        ));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
            $this,
            'process_admin_options'
        ));
        
        // Update function on save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
            $this,
            'on_save_settings'
        ));

        //add_filter( 'woocommerce_thankyou_order_id',array($this,'payment_callback'));
        add_action('woocommerce_order_status_refunded', array(
            $this,
            'create_refund'
        ));

        # Log that the class was instantiated and by what hook
        // $this->log('Payflex Class instantiated by ' . current_filter());

        // Don't enable PartPay if the amount limits are not met
        add_filter('woocommerce_available_payment_gateways', array(
            $this,
            'check_cart_within_limits'
        ) , 99, 1);

        add_action('woocommerce_settings_start', array(
            $this,
            'update_payment_limits'
        ));

        // Payment listener/API hook
        add_action('woocommerce_api_wc_gateway_partpay', array(
            $this,
            'payment_callback'
        ));

    }

    public function is_available()
    {
        return payflex_enabled();
    }

    public function getOrderUrl(){
        return $this->orderurl;
    }

    /**
     * Get the plugin root URL
     *
     * @param  string $path  The file location and name relative to the plugin root
     * @return string
     */
    public function plugin_url($path = '')
    {
        return $this->base_plugin_url . ltrim($path, '/');
    }
    
    /**
     * Get the plugin root directory
     *
     * @param  string $path The file location and name relative to the plugin root
     * @return string
     */
    public function plugin_dir($path = '')
    {
        return $this->base_plugin_dir . ltrim($path, '/');
    }

    /**
     * Get debug mode
     */
    public function get_debug_mode()
    {
        return $this->debug_mode;
    }

    /**
     * Gets a list of form fields for the payment gateway.
     */
    public function form_fields()
    {
        $payflex_api_accessable   = ($this->get_payflex_authorization_code() !== false);

        $pf_connection_status     = ($payflex_api_accessable) ? 'Successfully connected' : 'Connection failed, please check your credentials';

        $pf_connection_status_class = ($payflex_api_accessable) ? 'payflex_debug_success' : 'payflex_debug_error';
        
        $env_values = array();
        foreach ($this->environments as $key => $item)
        {
            $env_values[$key] = $item["name"];
        }

        $widget_types = [
            'purple' => 'Purple',
            'navy'   => 'Navy',
        ];

        $widget_themes =[ 
            ''     => 'Default',
            'dark' => 'Dark',
        ];
        $pay_type = [
            '4' => 'Pay in 4',
            '3' => 'Pay in 3'
        ];

        $pf_merch_value       = 'your-merchant-name';
        $pf_merch_ref_example = 'https://widgets.payflex.co.za/<span class="pf-merch-value">'.$pf_merch_value.'</span>/payflex-widget-2.0.1.js?type=calculator';

        $pf_merch_ref_example = 'https://widgets.payflex.co.za/<span class="pf-merch-value">'.get_payflex_option('merchant_widget_reference').'</span>/payflex-widget-2.0.1.js?type=calculator';

        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'woo_payflex'),
                'type'    => 'checkbox',
                'label'   => __('Enable Payflex', 'woo_payflex'),
                'default' => 'yes'
            ],
            'title' => [
                'title'       => __('Title', 'woo_payflex'),
                'type'        => 'text',
                'description' => __('This controls the payment method title which the user sees during checkout.', 'woo_payflex'),
                'default'     => __('Payflex', 'woo_payflex')
            ],
            'testmode' => [
                'title'       => __('Environment', 'woo_payflex'),
                'type'        => 'select',
                'options'     => $env_values,
                'description' => __('Select which environment to use, Sandbox or Production.', 'woo_payflex'),
            ],
            'client_id' => [
                'title'       => __('Client ID', 'woo_payflex'),
                'type'        => 'text',
                'description' => __('Payflex Client ID credential <br/><span class="pfConnectionStatus '.$pf_connection_status_class.'">'.$pf_connection_status.'</span>', 'woo_payflex'),
                'default'     => __('', 'woo_payflex')
            ],
            'client_secret' => [
                'title'       => __('Client Secret', 'woo_payflex'),
                'type'        => 'text',
                'description' => __('Payflex Client Secret credential', 'woo_payflex'),
                'default'     => __('', 'woo_payflex')
            ],
            'widget_style' => [
                'title'       => __('Widget Style', 'woo_payflex') ,
                'type'        => 'select',
                'options'     => $widget_types,
                'description' => __('Select the widget style to use on the product page.', 'woo_payflex') ,
                'default'     => 'purple'
            ],
            'widget_theme' => [
                'title'       => __('Widget Theme', 'woo_payflex') ,
                'type'        => 'select',
                'options'     => $widget_themes,
                'description' => __('Select the widget theme', 'woo_payflex') ,
                'default'     => ''
            ],
            'pay_type' => [
                'title'       => __('Pay Months', 'woo_payflex') ,
                'type'        => 'select',
                'options'     => $pay_type,
                'description' => __('Select the number of months to pay.<br/><br/>Preview: <br/><span class="pfwidgetpreview"></span>', 'woo_payflex') ,
                'default'     => '4'
            ],
            'enable_product_widget' => [
                'title'   => __('Product Page Widget', 'woo_payflex'),
                'type'    => 'checkbox',
                'label'   => __('Enable Product Page Widget', 'woo_payflex'),
                'default' => 'yes',

            ],
            'enable_checkout_widget' => [
                'title'   => __('Checkout Page Widget', 'woo_payflex'),
                'type'    => 'checkbox',
                'label'   => __('Enable Checkout Page Widget', 'woo_payflex'),
                'default' => 'yes'
            ],
            'merchant_widget_reference' => [
                'title'       => __('Widget Reference', 'woo_payflex'),
                'type'        => 'text',
                'label'       => __('Widget Reference', 'woo_payflex'),
                'default'     => __('', 'woo_payflex'),
                'description' => __('This is an optional reference that will be used to identify the widget on Payflex. <br/>Example: <span class="pf_merchant_ref_example">'.$pf_merch_ref_example.'</span><br/><br/>Info: <a href="https://widgets.payflex.co.za/index-2.html" target="_blank">https://widgets.payflex.co.za/index-2.html</a>', 'woo_payflex')
            ],
            'admin_only_enabled' => [
                'title'       => __('Admin Only Mode', 'woo_payflex'),
                'type'        => 'checkbox',
                'label'       => __('Enable Admin Only Mode', 'woo_payflex'),
                'default'     => 'no',
                'description' => __('Only enable Payflex when the user is logged into the Wordpress Backend.<br/>"Enable Payflex" will need to be selected as well.', 'woo_payflex')
            ],
            // Debug Mode
            'payflex_debug' => [
                'title'       => __('Debug Output', 'woo_payflex'),
                'type'        => 'checkbox',
                'label'       => __('Enable Debug Output', 'woo_payflex'),
                'default'     => 'no',
                'description' => __('Enable debug messages. Note this is not intended to be enabled day to day and should only be enabled during testing', 'woo_payflex')
            ],

        ];

        return $this->form_fields;
    }

    /**
     * Checks if the form fields match saved options, if not, return which fields are invalid
     */
    public function form_field_check()
    {
        // Get the saved options
        $saved_options_full = get_payflex_option();
        $saved_options      = array_keys($saved_options_full);

        // Get form fields
        $form_fields_full = $this->form_fields();
        $saved_fields     = array_keys($form_fields_full);
        
        $missing_fields = [];

        foreach ($saved_fields as $value)
        {
            if (!in_array($value, $saved_options))
            {
                $missing_fields[] = $value;
            }
        }

        return $missing_fields;
    }

    /**
        * Initialise Gateway Settings Form Fields
        *
        * @since 1.0.0
        */
    public function init_form_fields()
    {
        // Load the form fields.
        $this->form_fields();

        // Add script to settings page
        add_action('admin_footer', array(
            $this,
            'add_script_to_settings_page'
        ));
    } // End init_form_fields()
    
    public function add_script_to_settings_page()
    {
        ?>
        <script>
            // Make sure the pf_merchant_ref_example value that's used is url safe
            jQuery(document).on('keyup', '#woocommerce_payflex_merchant_widget_reference', function(){
                var pfinputmerchref = jQuery(this).val();

                // Can have any value that's safe in a url, including hyphens and underscores. If a space is used, replace it with a hyphen
                pfinputmerchref = pfinputmerchref.replace(/ /g, '-');
                
                // Make sure there's only ever one hyphen in a row
                pfinputmerchref = pfinputmerchref.replace(/-+/g, '-');

                // Remove special characters and anything else that's not a letter, number, hyphen or underscore

                pfinputmerchref = pfinputmerchref.replace(/[^a-zA-Z0-9-_]/g, '');

                jQuery(this).val(pfinputmerchref);

                var pfmerchstringvalue = jQuery(this).val();

                if(pfmerchstringvalue == ''){
                    pfmerchstringvalue = 'your-merchant-name';
                }

                jQuery('.pf-merch-value').text(pfmerchstringvalue);
                
            });

            jQuery(document).ready(function($){
                $('.pf_merchant_ref_example').css('color', '#0073aa');
            });

            // Dynamicaly load the widget into pfwidgetpreview
            jQuery(document).on('change', '#woocommerce_payflex_widget_style , #woocommerce_payflex_widget_theme, #woocommerce_payflex_pay_type', function(){
                var widget_style       = jQuery('#woocommerce_payflex_widget_style').val();
                var widget_theme       = jQuery('#woocommerce_payflex_widget_theme').val();
                var pay_type           = jQuery('#woocommerce_payflex_pay_type').val();
                var widget_preview     = jQuery('.pfwidgetpreview');
                if(widget_theme == ''){
                    widget_preview.removeClass('dark');
                }else{
                    widget_preview.addClass('dark');
                }
                var widget_preview_url = 'https://widgets.payflex.co.za/your-merchant-name/payflex-widget-2.0.1.js?type=calculator&amount=1000&logo_type=' + widget_style + '&theme=' + widget_theme + '&pay_type=' + pay_type;
                widget_preview.html('<script src="' + widget_preview_url + '"><\/script>');
            });

            // Load widget on page load
            jQuery(document).ready(function(){
                var widget_style       = jQuery('#woocommerce_payflex_widget_style').val();
                var widget_theme       = jQuery('#woocommerce_payflex_widget_theme').val();
                var pay_type           = jQuery('#woocommerce_payflex_pay_type').val();
                var widget_preview     = jQuery('.pfwidgetpreview');
                if(widget_theme == ''){
                    widget_preview.removeClass('dark');
                }else{
                    widget_preview.addClass('dark');
                }
                var widget_preview_url = 'https://widgets.payflex.co.za/your-merchant-name/payflex-widget-2.0.1.js?type=calculator&amount=1000&logo_type=' + widget_style + '&theme=' + widget_theme + '&pay_type=' + pay_type;
                widget_preview.html('<script src="' + widget_preview_url + '"><\/script>');
            });

            // pfConnectionStatus, when Client ID or secret is entered, update the text to tell you to save settings
            jQuery(document).on('keyup', '#woocommerce_payflex_client_id, #woocommerce_payflex_client_secret', function(){
                jQuery('.pfConnectionStatus').text('Save settings to attempt authentication');
                jQuery('.pfConnectionStatus').removeClass('payflex_debug_success');
                jQuery('.pfConnectionStatus').removeClass('payflex_debug_error');
            });
        </script>

        <style>
            .pf_merchant_ref_example{
                font-size: 12px;
                background-color: #fff;
                padding: 2px;
                border-radius: 4px;
            }

            /* Make the table look nice */
            .payflex-support-settings-table {
                width: 100%;
                border-collapse: collapse;
            }
            .payflex-support-settings-table th {
                padding: 10px;
                text-align: left;
                border-bottom: 1px solid #e5e5e5;
                
            }
            .payflex-support-settings-table td {
                padding: 10px;
                border-bottom: 1px solid #e5e5e5;
            }
            .payflex-support-settings-table tr:last-child td {
                border-bottom: none;
            }
            .payflex-support-settings-table tr:last-child th {
                border-bottom: none;
            }
            .pfwidgetpreview{
                max-width: 800px;
                display: block;
                /* resizable */
                resize: horizontal;
                overflow: auto;
                border: 1px solid #e5e5e5;
            }
            .pfwidgetpreview.dark{
                background-color: #333;
                color: #fff;
            }

            .pfConnectionStatus{
                font-size: 12px;
                color: #0073aa;
            }

            .payflex_debug_success{
                color: #46b450;
            }
            .payflex_debug_error{
                color: #ff0000;
            }

        </style>
        <?php
    }

    /**
        * Init JS Scripts Options
        *
        * @since 1.2.1
        */
    public function init_scripts_js()
    {
        //use WP native jQuery
        wp_enqueue_script("jquery");

    }

    /**
     * Get Woocommerce logging status
     */
    public function get_wc_logging_status()
    {
        $wc_settings = new WC_Settings();
        $is_logging_enabled = $wc_settings->logging_is_enabled();

        return $is_logging_enabled;
    }

    /**
        * Init Scripts Options
        *
        * @since 1.2.1
        */
    public function init_scripts_css()
    {
    }

    /**
        * Init Environment Options
        *
        * @since 1.2.3
        */
    public function init_environment_config()
    {
        if (empty($this->environments))
        {
            //config separated for ease of editing
            require (__DIR__.'/../config/config.php');
            $this->environments = $environments;
        }
    }

    /**
        * Admin Panel Options
        *
        * @since 1.0.0
        */
    public function admin_options()
    {
    ?>
        <h3><?php esc_html_e('Payflex Gateway', 'woo_payflex'); ?></h3>

        <table class="form-table payflex-support-settings-table">
            <?php
        // Generate the HTML For the settings form.
        $this->generate_settings_html();


    ?>
        </table><!--/.form-table-->

        <?php

            $this->admin_info_block();
    } // End admin_options()
    
    /**
     * Process admin options and sanitize credentials
     *
     * @since 2.6.8
     */
    public function process_admin_options()
    {
        // Get the posted data
        $post_data = $this->get_post_data();

        // Trim whitespace from client_id and client_secret before processing
        if (isset($post_data['woocommerce_payflex_client_id']))
        {
            $post_data['woocommerce_payflex_client_id'] = trim($post_data['woocommerce_payflex_client_id']);
            $_POST['woocommerce_payflex_client_id']     = $post_data['woocommerce_payflex_client_id'];
        }
        
        if (isset($post_data['woocommerce_payflex_client_secret']))
        {
            $post_data['woocommerce_payflex_client_secret'] = trim($post_data['woocommerce_payflex_client_secret']);
            $_POST['woocommerce_payflex_client_secret']     = $post_data['woocommerce_payflex_client_secret'];
        }

        // Call the parent method to handle the rest of the processing
        return parent::process_admin_options();
    }
    
    public function on_save_settings()
    {
        # Reset the access token
        $this->get_payflex_authorization_code(true);

        # Get environment mode
        $env = $this->settings['testmode'];

        # Save timestamp of when the settings were last saved
        update_option('payflex_settings_last_saved', time());

        # Log authentication state
        if($this->get_payflex_authorization_code())
        {
            $this->log('Settings Updated: Environment ('.$env.') Authentication state: Success!');
        }
        else
        {
            $this->log('Settings Updated: Environment ('.$env.') Authentication state: Failed', 'error');
        }
        # Update limits
        // $this->update_payment_limits();
    }

    public function admin_info_block()
    {
    ?>
        <p><a href="<?php echo admin_url('admin.php?page=payflex-support'); ?>"><?php esc_html_e('Support Information', 'woo_payflex'); ?></a></p>
    <?php
    }
    
    /**
     * Display payment options on the checkout page
     *
     * @since 1.0.0
     */
    public function payment_fields()
    {
        global $woocommerce;

        if(!payflex_checkout_widget_enabled()) return;

        echo '<style>.elementor{max-width:100% !important}';
        echo 'html {
                -webkit-font-smoothing: antialiased!important;
                -moz-osx-font-smoothing: grayscale!important;
                -ms-font-smoothing: antialiased!important;
            }
            .md-stepper-horizontal {
                display:table;
                width:100%;
                margin:0 auto;
                background-color:transparent;
            }
            .md-stepper-horizontal .md-step {
                display:table-cell;
                position:relative;
                padding:0;
            }
            .md-stepper-horizontal .md-step .md-step-circle {
                width:30px;
                height:30px;
                margin:0 auto;
                border-radius: 50%;
                text-align: center;
                line-height:30px;
                font-size: 16px;
                font-weight: 600;
                color:#FFFFFF;
            }
            .md-stepper-horizontal .md-step .md-step-title {
                margin-top:16px;
                font-size:16px;
                font-weight:600;
            }
            .md-stepper-horizontal .md-step .md-step-title,
            .md-stepper-horizontal .md-step .md-step-optional {
                text-align: center;
                color : #002751;
            }
            .md-stepper-horizontal .md-step .md-step-optional {
                font-size:12px;
            }
            .payflex_description{
                font-size:16px;text-align:center;
                margin-top:17px;
            }
            .fontcolor{
                color : #002751;
            }
            </style>';
        $ordertotal = $woocommerce
            ->cart->total;
        $installment = round(($ordertotal / 4) , 2);
        echo '<div class="fontcolor" style="font-size:16px;text-align:center">Four interest-free payments totalling R' . $ordertotal . '</div>';
        echo '<div class="md-stepper-horizontal orange">
                <div class="md-step active">
                <div class="md-step-title">R' . $installment . '</div>
                <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-01.png') . '"></span></div>
                <div class="md-step-optional">1st instalment</div>
                </div>
                <div class="md-step active">
                <div class="md-step-title">R' . $installment . '</div>
                <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-02.png') . '"></span></div>
                <div class="md-step-optional">2 weeks later</div>
                </div>
                <div class="md-step active">
                <div class="md-step-title">R' . $installment . '</div>
                <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-03.png') . '"></span></div>
                <div class="md-step-optional">4 weeks later</div>
                </div>
                <div class="md-step active">
                <div class="md-step-title">R' . $installment . '</div>
                <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-04.png') . '"></span></div>
                <div class="md-step-optional">6 weeks later</div>
                </div>
            </div>
        <div class="payflex_description fontcolor">You will be redirected to Payflex when you click on place order.</div>
        ';

    }

    /**
     * Register Payflex support page
     *
     * @return void
     */
    static function register_support_page() {
        add_submenu_page(
            'wc-settings',
            'Payflex Support',
            'Payflex Support',
            'manage_options',
            'payflex-support',
            'WC_Gateway_PartPay::payflex_support_page'
        );
    }


    /**
     * Simple support details and tools.
     *
     * @return void
     */
    static function payflex_support_page() {
        $WC                       = WC_Gateway_PartPay::instance();
        $check_php_version        = version_compare(PHP_VERSION, '8.1', '>=');
        $payflex_api_accessable   = ($WC->get_payflex_authorization_code() !== false);
        $payflex_order            = FALSE;
        $redirect_url             = FALSE;

        if(isset($_GET['redirect_url']))
        {
            $redirect_url = $returned_token = sanitize_url(urldecode($_GET['redirect_url']));

            // Validate redirect
            if(!filter_var($redirect_url, FILTER_VALIDATE_URL) OR parse_url($redirect_url, PHP_URL_HOST) !== $_SERVER['HTTP_HOST'])
            {
                $redirect_url = FALSE;
            }
            
        }
        
        // Get order details
        $payflex_order_id = FALSE;

        if(isset($_GET['payflex_order_id']) && !empty($_GET['payflex_order_id']))
        {
            $payflex_order_id = sanitize_text_field($_GET['payflex_order_id']);
            $payflex_order = $WC->payflex_remote_get_order($payflex_order_id);
        }

        // Force cron check
        $running_cron = FALSE;
        if(isset($_GET['force_cron']) && $_GET['force_cron'] == 'Force Check')
        {
            $running_cron = TRUE;
            $WC->check_pending_abandoned_orders(true);
        }

        // Update check
        $update_data      = wp_get_update_data();
        $outdated_plugins = $update_data['counts']['plugins'];

        // Plugin Count
        $plugin_count = count(get_option('active_plugins'));

        // API Token
        $api_token_date = '';

        if($WC->get_access_token_date())
        {
            $formatted_api_date = human_time_diff($WC->get_access_token_date(), time()) . ' ago';
            $api_token_date = '(Updated '.$formatted_api_date.')';
        }

        // Cron checks
        $cron_orders = $WC->get_pending_abandoned_orders();

        // Check if WC has logging enabled using the WC_Admin_Settings
        $is_logging_enabled = $WC->get_wc_logging_status();

        $missing_field_check = $WC->form_field_check()
        ?>
        <div class="wrap">
            <h1>Payflex Support</h1>
            <span>
            <?php if($redirect_url): ?>
                <a href="<?=$redirect_url?>">Back to Previous Page</a> | 
            <?php endif; ?>
            <a href="<?=admin_url('admin.php?page=wc-settings&tab=checkout&section=payflex')?>">Payflex Settings</a>
            </span>
            <div class="debug_table_wrapper">
            
                <table class="debug_table">
                    <tr>
                        <td colspan="2">
                            <h3>Support Information</h3>
                        </td>
                    </tr>
                    <tr>
                        <td>Payflex Plugin Version: </td>
                        <td>
                            <span class="">v<?=$WC->version?></span>
                        </td>
                    </tr>
                    <tr>
                        <td>PHP Version: </td>
                        <td>
                            <?php if($check_php_version): ?>
                                <span class="payflex_debug_success">PHP v<?=PHP_VERSION?></span>
                            <?php else:?>
                                <span class="payflex_debug_error">PHP v<?=PHP_VERSION?> Isn't officially supported</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>WordPress Version: </td>
                        <td>
                            <?php if(version_compare(get_bloginfo('version'), '6.5', '>=')): ?>
                                <span class="payflex_debug_success">WordPress v<?=get_bloginfo('version')?></span>
                            <?php else:?>
                                <span class="payflex_debug_error">WordPress v<?=get_bloginfo('version')?> Isn't officially supported</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>WooCommerce Version: </td>
                        <td>
                            <?php if(version_compare(WC()->version, '9.6', '>=')): ?>
                                <span class="payflex_debug_success">WooCommerce v<?=WC()->version?></span>
                            <?php elseif(version_compare(WC()->version, '9.0', '>')): ?>
                                <span class="payflex_debug_warning">WooCommerce v<?=WC()->version?> Isn't officially supported, but isn't too old and should work with possible minor bugs, please consider updating Woocommerce</span>
                            <?php else:?>
                                <span class="payflex_debug_error">WooCommerce v<?=WC()->version?> is seriously outdated, please update Woocommerce as soon as possible</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Payflex Limits:</td>
                        <td>
                            <?php 
                            $limits = $WC->get_payflex_limits();
                            if($limits['minimum'] !== false && $limits['maximum'] !== false): 
                            ?>
                                <span class="payflex_debug_success">
                                    Min: R<?=number_format($limits['minimum'], 2)?> | Max: R<?=number_format($limits['maximum'], 2)?>
                                </span>
                                <?php if($limits['refunds_enabled']): ?>
                                    <span class="payflex_debug_success">(Refunds Enabled)</span>
                                <?php else: ?>
                                    <span class="payflex_debug_warning">(Refunds Disabled)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="payflex_debug_error">Limits not available</span>
                            <?php endif; ?>
                            <div class="payflex_info_text">
                                These are the Payflex limits set for your account <br>
                                Payflex will not be available as a payment option if the cart total is outside of these limits.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>Plugins:</td>
                        <td>
                            <span class="payflex_debug_success"><?=$plugin_count?> Active</span>
                            <?php if($outdated_plugins): ?>
                                <span class="payflex_debug_warning">(<?=$outdated_plugins?> Outdated)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Payflex Authentication:</td>
                        <td>
                            <?php if($payflex_api_accessable): ?>
                                <span class="payflex_debug_success">Successful <?=$api_token_date?></span>
                            <?php else:?>
                                <span class="payflex_debug_error">Authentication Error</span>
                            <?php endif; ?>

                            <div class="payflex_info_text">
                                When saving settings, we will attempt to authenticate with Payflex and cache the access token. <br>
                                The token is used for all API requests and will automatically refresh when it expires.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>Scheduled orders (CRON):</td>
                        <td>
                            <div class="payflex_two_column">
                                <div>
                                    <?=count($cron_orders['new'])?> Waiting order<?=(count($cron_orders['new']) !== 1 ? 's' : '')?>.<br/>
                                    <?=count($cron_orders['scheduled'])?> Order<?=(count($cron_orders['scheduled']) !== 1 ? 's' : '')?> currently in queue.
                                </div>
                                <div>
                                    <form method="get" action="<?=admin_url('admin.php');?>">
                                        <input type="hidden" name="redirect_url" value="<?=$redirect_url?>">
                                        <input type="hidden" name="page" value="payflex-support">
                                        <input type="submit" name="force_cron" value="Force Check">
                                    </form>
                                    <div>
                                        <?php if($running_cron): ?>
                                            <span class="payflex_debug_success cron_check_message">Checked Orders!</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="payflex_info_text">
                                Any orders abandoned during the checkout process are added to a schedule. They always wait at least 30 minutes before running. <br>
                                After 30 minutes, they will be added to a schedule that checks every 2 minutes. After 2 hours, we stop checking.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>Payflex Options</td>
                        <td>
                            <?php if(count($missing_field_check) === 0): ?>
                                <span class="payflex_debug_success">All settings appear to be saved correctly</span>
                            <?php else: ?>
                                <span class="payflex_debug_error">Missing or incorrectly saved settings:</span>
                                <ul>
                                    <?php foreach($missing_field_check as $field): ?>
                                        <li><?php echo esc_html($field); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <span class="payflex_debug_error">
                                    Settings need to be saved. It's recommended to re-save your settings if you are having issues:</br>
                                    1. Initial settings have not been saved</br> 
                                    2. Fields were updated in a recent version</br>
                                    3. Settings were lost or corrupted</br>
                                    Visit the <a href="<?=admin_url('admin.php?page=wc-settings&tab=checkout&section=payflex')?>">Payflex settings</a> page to verify your configuration.</br>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Woocommerce Log Files</td>
                        <td>
                            <?php if($is_logging_enabled): ?>
                                <span class="payflex_debug_success">Woocommerce logging enabled: </span><a href="<?=admin_url('admin.php?page=wc-status&tab=logs')?>">View Logs</a>
                            <?php else:?>
                                <span class="payflex_debug_error">Woocommerce logging disabled. Payflex will not save logs. You can enable it here:</span> <a href="<?=admin_url('admin.php?page=wc-status&tab=logs&view=settings')?>">Woocommerce Log settings</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="no-border">
                        <td>Lookup Order ID</td>
                        <td>
                            <form method="get" action="<?=admin_url('admin.php');?>">
                                <input type="hidden" name="page" value="payflex-support">
                                <?php if($redirect_url): ?>
                                    <input type="hidden" name="redirect_url" value="<?=$redirect_url?>">
                                <?php endif; ?>
                                <input type="text" id="payflex_order_id" name="payflex_order_id" value="<?=$payflex_order_id?>" style="width:300px;" placeholder="Order ID">
                                <input type="submit" value="Lookup">
                            </form>
                            <?php if($payflex_order_id AND !$payflex_order): ?>
                                <span class="payflex_debug_error">Order ID not found</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php if($payflex_order): ?>
                <div class="debug_table_wrapper">
                    <table class="debug_table">
                        <tr>
                            <td colspan="2">
                                <h3>Order Details</h3>
                            </td>
                        </tr>
                        <tr>
                            <td>Order Consumer</td>
                            <td><?=$payflex_order->consumer->givenNames?> <?=$payflex_order->consumer->surname?></td>
                        </tr>
                        <tr>
                            <?php
                                $class = 'payflex_debug_unknown';
                                if($payflex_order->orderStatus == 'Declined' OR $payflex_order->orderStatus == 'Abandoned') $class = 'payflex_debug_error';
                                if($payflex_order->orderStatus == 'Approved') $class = 'payflex_debug_success';
                            ?>
                            <td>Order Status</td>
                            <td>
                                <span class="<?=$class?>"><?=$payflex_order->orderStatus?></span>
                            </td>
                        </tr>
                        <tr>
                            <td>Order ID</td>
                            <td>
                                <?=$payflex_order->orderId?>
                            </td>
                        </tr>
                        <tr>
                            <td>Merchant Reference</td>
                            <td>
                                <?=$payflex_order->merchantReference?>
                            </td>
                        </tr>
                        <tr>
                            <td>Order Amount</td>
                            <td><?=$payflex_order->amount?></td>
                        </tr>
                        <tr>
                            <td>Order Date</td>
                            <?php $date = new DateTime($payflex_order->createdDateTime); ?>
                            <td> <?=$date->format('Y-m-d H:i:s')?></td>
                        </tr>
                        <tr class="no-border">
                            <td>Order Email</td>
                            <td><a href="mailto:<?=$payflex_order->consumer->email?>"><?=$payflex_order->consumer->email?></a></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

                
            <script>
                // Remove force_cron from URL, keeping all other parameters on page load
                window.onload = function(){
                    var url = new URL(window.location.href);
                    url.searchParams.delete('force_cron');
                    window.history.replaceState({}, document.title, url);

                    // Remove cron_check_message after 3 seconds
                    setTimeout(function(){
                        var elements = document.getElementsByClassName('cron_check_message');
                        for (var i = 0; i < elements.length; i++) {
                            elements[i].style.display = 'none';
                        }
                    }, 3000);
                }

            </script>
            <style>
            .debug_table_wrapper {
                margin-top: 20px;
                padding: 20px;
                background-color: #f9f9f9;
                border: 1px solid #e5e5e5;
                max-width: 1000px;
            }

            .debug_table {
                width: 100%;
            }

            .debug_table td {
                padding: 5px;
                border-bottom: 1px solid #e5e5e5;
            }
            .no-border td {
                border-bottom: none;
            }

            .debug_table td:first-child {
                font-weight: bold;
                vertical-align: top;
            }

            .debug_table td:first-child {
                font-weight: bold;
            }

            .payflex_debug_success {
                color: #2ecc71;
            }

            .payflex_debug_warning {
                color: #f1c40f;
            }

            .payflex_debug_error {
                color: #e74c3c;
            }

            .payflex_two_column{
                display: flex;
                justify-content: flex-start;
                gap: 40px;
            }
            .payflex_info_text{
                font-size: 12px;
                color: #777;
                padding-left: 10px;
            }
        </style>
        </div>
        <?php
    }


    /**
     * Request an order token from Partpay
     *
     * @return  string or boolean false if no token generated
     * @since 1.0.0
     */
    public function get_payflex_authorization_code($reset_token = false)
    {
        if($reset_token)
        {
            delete_transient('payflex_access_token');
            delete_transient('payflex_access_token_date');
        }

        $access_token      = get_transient('payflex_access_token');
        $access_token_date = get_transient('payflex_access_token_date');

        // $this->log('Access token from cache is ' . $access_token);
        // $this->log('Refreshed Payflex Access Token');

        if (false !== $access_token && !empty($access_token))
        {
            // $this->log('Returning cached access token ' . $access_token);
            return $access_token;
        }

        if (false === $this->apiKeysAvailable())
        {
            if($this->get_debug_mode())
                $this->log('No api keys available');
            return false;
        }
        
        // Debug mode check
        if($this->get_debug_mode())
        {
            $this->log('Getting new access token');
        }

        $AuthURL = $this->environments[$this->settings['testmode']]['auth_url'];
        $AuthBody = ['client_id' => $this->settings['client_id'], 'client_secret' => $this->settings['client_secret'], 'audience' => $this->environments[$this->settings['testmode']]['auth_audience'], 'grant_type' => 'client_credentials'];
        $AuthBody = wp_json_encode($AuthBody);
        $headers = array(
            'Content-Type' => 'application/json'
        );
        $AuthBody = json_decode(json_encode($AuthBody) , true);

        $response = wp_remote_post($AuthURL, array(
            'body' => $AuthBody,
            'headers' => $headers
        ));
        $body = json_decode(wp_remote_retrieve_body($response) , true);
        if (!is_wp_error($response) && isset($response['response']['code']) && $response['response']['code'] != '401')
        {
            //store token in cache
            $accessToken = isset($body['access_token']) ? $body['access_token'] : '';
            $expireTime = isset($body['expires_in']) ? $body['expires_in'] : '';
            $accessToken_short = substr($accessToken, 0, 10).'...'.substr($accessToken, -10);
            $this->log('Storing new token in cache ' . $accessToken_short . ' which is valid for ' . $expireTime . ' seconds');
            set_transient('payflex_access_token', $accessToken, ((int)$expireTime - 120));
            set_transient('payflex_access_token_date', time(), ((int)$expireTime - 120));
            return $accessToken;
        }
        else
        {
            return false;
        }
    }

    public function get_access_token_date()
    {
        return get_transient('payflex_access_token_date');
    }

    private function apiKeysAvailable()
    {

        if (empty($this->settings['client_id']) || empty($this->settings['client_secret']))
        {
            if($this->get_debug_mode())
                $this->log('API keys not available.');
            return false;
        }

        return true;
    }

    public function update_payment_limits()
    {
        // Get existing limits
        $settings = get_payflex_option();

        if (false === $this->apiKeysAvailable())
        {
            return false;
        }

        // $this->log('Updating payment limits requested');
        if (!empty($this->configurationUrl))
        {
            $response = wp_remote_get($this->configurationUrl, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->get_payflex_authorization_code()
                )
            ));
            $body = json_decode(wp_remote_retrieve_body($response) , true);


            // Remove old limits
            if(isset($settings['payflex-amount-maximum'])) unset($settings['payflex-amount-maximum']);
            if(isset($settings['payflex-amount-minimum'])) unset($settings['payflex-amount-minimum']);

            if (!is_wp_error($response) && isset($response['response']['code']) && $response['response']['code'] == 200)
            {
                if($this->get_debug_mode()) $this->log('Updating payment limits');
                
                $settings['payflex_limit_amount_minimum']  = isset($body['minimumAmount']) ? $body['minimumAmount'] : 0;
                $settings['payflex_limit_amount_maximum']  = isset($body['maximumAmount']) ? $body['maximumAmount'] : 0;
                $settings['payflex_limit_refunds_enabled'] = isset($body['enabledForRefunds']) ? $body['enabledForRefunds'] : false;

                $settings['payflex_limit_last_updated'] = time();
            }

            update_option('woocommerce_payflex_settings', $settings);
        }
        $this->init_settings();

    }


    /**
     * Get Payflex payment limits
     * @param bool $field Specific field to return (amount_minimum, amount_maximum, refunds_enabled)
     * @return array|bool
     */
    public function get_payflex_limits($field = false)
    {
        if (!isset($settings['payflex_limit_last_updated']) || (time() - $settings['payflex_limit_last_updated']) > 86400) {
            $this->update_payment_limits();
        }

        $settings = get_payflex_option();

        if($field)
        {
            return isset($settings['payflex_limit_'.$field]) ? $settings['payflex_limit_'.$field] : false;
        }
        else
        {
            return [
                'minimum'         => isset($settings['payflex_limit_amount_minimum']) ? $settings['payflex_limit_amount_minimum'] : false,
                'maximum'         => isset($settings['payflex_limit_amount_maximum']) ? $settings['payflex_limit_amount_maximum'] : false,
                'refunds_enabled' => isset($settings['payflex_limit_refunds_enabled']) ? $settings['payflex_limit_refunds_enabled'] : false,
            ];
        }
    }

    /**
     * Process the payment and return the result
     * - redirects the customer to the pay page
     *
     * @param int $order_id
     *
     * @since 1.0.0
     * @return array
     */
    public function process_payment($order_id)
    {

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        // Check if there's a custom order number
        $merchantRefe = $order_id;



        // Get the authorization token
        $access_token = $this->get_payflex_authorization_code();

        //Process here
        $orderitems = $order->get_items();
        $items = array();
        $i = 0;
        if (count($orderitems))
        {
            foreach ($orderitems as $item)
            {

                $i++;
                // get SKU
                if ($item['variation_id'])
                {

                    if (function_exists("wc_get_product"))
                    {
                        $product = wc_get_product($item['variation_id']);
                    }
                    else
                    {
                        $product = new WC_Product($item['variation_id']);
                    }
                }
                else
                {

                    if (function_exists("wc_get_product"))
                    {
                        $product = wc_get_product($item['product_id']);
                    }
                    else
                    {
                        $product = new WC_Product($item['product_id']);
                    }
                }

                if ($i == count($orderitems))
                {
                    $product = $items[] = array(

                        '{
                            "name":"' . esc_html($item['name']) . $i . '",
                            "sku":"' . $product->get_sku() . '",
                            "quantity":"' . $item['qty'] . '",
                            "price":"' . number_format(($item['line_subtotal'] / $item['qty']) , 2, '.', '') . '"
                        }'

                    );
                }
                else
                {
                    $product = $items[] = array(

                        '{
                            "name":"' . esc_html($item['name']) . $i . '",
                            "sku":"' . $product->get_sku() . '",
                            "quantity":"' . $item['qty'] . '",
                            "price":"' . number_format(($item['line_subtotal'] / $item['qty']) , 2, '.', '') . '"
                        }'

                    );
                }
            }
        }

        //calculate total shipping amount
        if (method_exists($order, 'get_shipping_total'))
        {
            //WC 3.0
            $shipping_total = $order->get_shipping_total();
        }
        else
        {
            //WC 2.6.x
            $shipping_total = $order->get_total_shipping();
        }
        $merchantRefe = $order->get_order_number();
        
        $OrderBodyObj = new stdClass;
        $OrderBodyObj->amount                 = number_format($order->get_total() , 2, '.', '');
        $OrderBodyObj->consumer               = new stdClass;
        $OrderBodyObj->consumer->phoneNumber  = (string)$order->get_billing_phone();
        $OrderBodyObj->consumer->givenNames   = (string)$order->get_billing_first_name();
        $OrderBodyObj->consumer->surname      = (string)$order->get_billing_last_name();
        $OrderBodyObj->consumer->email        = (string)$order->get_billing_email();
        $OrderBodyObj->billing                = new stdClass;
        $OrderBodyObj->billing->addressLine1  = (string)$order->get_billing_address_1();
        $OrderBodyObj->billing->addressLine2  = (string)$order->get_billing_address_2();
        $OrderBodyObj->billing->suburb        = (string)$order->get_billing_city();
        $OrderBodyObj->billing->postcode      = (string)$order->get_billing_postcode();
        $OrderBodyObj->shipping               = new stdClass;
        $OrderBodyObj->shipping->addressLine1 = (string)$order->get_shipping_address_1();
        $OrderBodyObj->shipping->addressLine2 = (string)$order->get_shipping_address_2();
        $OrderBodyObj->shipping->suburb       = (string)$order->get_shipping_city();
        $OrderBodyObj->shipping->postcode     = (string)$order->get_shipping_postcode();
        $OrderBodyObj->description            = 'string';
        $OrderBodyObj->items                  = [];
        $objectItems                          = [];
        foreach ($items as $item)
        {
            array_push($objectItems,json_decode($item[0]));
        }

        $OrderBodyObj->items                        = $objectItems;
        $OrderBodyObj->merchant                     = new stdClass;
        $OrderBodyObj->merchant->redirectConfirmUrl = (string)$this->get_return_url($order) . '&order_id=' . $order_id . '&status=confirmed&wc-api=WC_Gateway_PartPay';
        $OrderBodyObj->merchant->redirectCancelUrl  = (string)$this->get_return_url($order) . '&status=cancelled';
        $OrderBodyObj->merchantReference            = (string)$merchantRefe;
        $OrderBodyObj->taxAmount                    = $order->get_total_tax();
        $OrderBodyObj->shippingAmount               = $shipping_total;



        $platform_info_string = 'Wordpress '. get_bloginfo('version') . ', WooCommerce ' . WC()->version;

        $all_plugins    = get_plugins();
        $active_plugins = count(get_option('active_plugins'));
        $total_plugins  = count($all_plugins);

        $OrderBodyObj->merchantSystemInformation = new stdClass;
        $OrderBodyObj->merchantSystemInformation->plugin_version        = $this->version;
        $OrderBodyObj->merchantSystemInformation->php_version           = PHP_VERSION;
        $OrderBodyObj->merchantSystemInformation->ecommerce_platform    = $platform_info_string;
        $OrderBodyObj->merchantSystemInformation->total_plugin_modules  = (string)$total_plugins;
        $OrderBodyObj->merchantSystemInformation->active_plugin_modules = (string)$active_plugins;

        $APIURL = $this->orderurl . '/productSelect';

        // Check if the current order already has a Payflex order ID
        $existing_order_id = $order->get_meta('_payflex_order_id');

        // Create an order note
        if($existing_order_id)
        {
            $current_order_url = urlencode(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));
            $admin_support_url = admin_url('admin.php?page=payflex-support&payflex_order_id=' . $existing_order_id.'&redirect_url='.$current_order_url);
            $transaction_id_html_link =  '<a href="'.$admin_support_url.'">'.$existing_order_id.'</a>';

            $order->add_order_note(__('Payflex: User checking out again using Payflex, but the order is already in progress on the gateway transaction ID: ' . $transaction_id_html_link, 'woo_payflex'));

            // Check payflex order transaction ID on Payflex
            $payflex_order = $this->payflex_remote_get_order($existing_order_id);

            if($payflex_order->orderStatus == 'Declined' OR $payflex_order->orderStatus == 'Abandoned' OR $payflex_order->orderStatus == 'Cancelled')
            {
                // If the order status is declined or abandoned, we can create a new order
                $order->add_order_note(__('Payflex:  ' .$transaction_id_html_link . ' is was '.$payflex_order->orderStatus.'. Transaction ID resetting to allow a new payment for current order.', 'woo_payflex'));

                $order->delete_meta_data('_payflex_order_id');
                $order->delete_meta_data('_payflex_order_token');
            }

            if($payflex_order->orderStatus == 'Created')
            {
                // If the order status is created, we should not create a new order
                $order->add_order_note(__('Payflex: Order is currently pending on Payflex. Until the Payflex order is declined or cancelled, the user cannot checkout again for the current order', 'woo_payflex'));
                // Show a notice to the user
                wc_add_notice(__('This order is currently awaiting approval from Payflex. Please wait for the order to be approved or cancelled before trying again.', 'woo_payflex'), 'error');

                return;
            }
            if($payflex_order->orderStatus == 'Approved')
            {
                // If the order status is approved, we should not create a new order
                $order->add_order_note(__('Payflex: Order was already approved on Payflex. Payment cannot be processed again', 'woo_payflex'));
                $this->set_payflex_workflow_status($order_id, 'approved');

                // Show a notice to the user
                wc_add_notice(__('This order has already been approved by Payflex, if your order still appears to be pending, please wait for up to 40 minutes for the order to be processed.', 'woo_payflex'), 'error');

                return;
            }

            if(!in_array($payflex_order->orderStatus, ['Declined', 'Abandoned','Cancelled','Created','Approved']))
            {
                // If the order status is not in the list of statuses that we want to check, we can create a new order
                $order->add_order_note(__('Payflex: Order status is ' . $payflex_order->orderStatus . '. This isn\'t a known status, so we will not allow the user to checkout again using Payflex for the current order', 'woo_payflex'));
                
                // Show a notice to the user
                wc_add_notice(__('This order is currently awaiting approval from Payflex. Please wait for the order to be approved or cancelled before trying again.', 'woo_payflex'), 'error');
                return;
            }
        }



        $order_args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ) ,
            'body'    => json_encode($OrderBodyObj) ,
            'timeout' => 30
        );

        $order_response = wp_remote_post($APIURL, $order_args);

        // echo json_encode($APIURL); die;

        if(is_wp_error($order_response))
        {
            if ($this->debug_mode)
            {
                wc_add_notice(__('There was an issue connecting to Payflex servers. Please try again later.', 'woo_payflex') , 'error');
            }
            else
            {
                wc_add_notice(__('Sorry, there was a problem preparing your payment. Please try again later.', 'woo_payflex') , 'error');
            }

            $this->log('User attempted Payflex payment and an error occured - ' . $order_response->get_error_message());

            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        $order_body = json_decode(wp_remote_retrieve_body($order_response));
        if(!is_object($order_body))
        {
            if ($this->debug_mode)
            {
                wc_add_notice(__('Payflex API return is not a valid object, API might be under maintenance or there was an undefined issue with the sent data', 'woo_payflex') , 'error');
            }
            else
            {
                wc_add_notice(__('Sorry, there was a problem preparing your payment.', 'woo_payflex') , 'error');
            }
            
            $this->log('User attempted Payflex payment and an error occured - Payflex API return is not a valid object, API might be under maintenance or there was an undefined issue with the sent data', 'error');

            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        if(!isset($order_body->redirectUrl) OR !isset($order_body->orderId) OR !isset($order_body->token))
        {
            if ($this->debug_mode)
            {
                if(isset($order_body->message))
                {
                    // This currently doesn't trigger correctly due to a Woocommerce bug https://github.com/woocommerce/woocommerce/issues/51272
                    wc_add_notice(__('Payflex payment error. Successfully connected to Payflex, but did not get back expected data.<br/> API Responded with: '.$order_body->message, 'woo_payflex') , 'error');

                    $this->log('User attempted Payflex payment and an error occured - Successfully connected to Payflex gateway, but did not get back expected data. API Responded with: '.$order_body->message, 'error');

                    return array(
                        'result' => 'failure',
                        'redirect' => $order->get_checkout_payment_url(true)
                    );
                }

                if(isset($order_body->response) AND isset($order_body->response->message))
                {

                    wc_add_notice(__('Payflex payment error. Successfully connected to Payflex, but did not get back expected data.<br/> API Responded with: '.$order_body->message, 'woo_payflex') , 'error');

                    $this->log('User attempted Payflex payment and an error occured - Successfully connected to Payflex gateway, but did not get back expected data. API Responded with: '.$order_body->message, 'error');

                    return array(
                        'result' => 'failure',
                        'redirect' => $order->get_checkout_payment_url(true)
                    );
                }

                wc_add_notice(__('Payflex API return response is not in expected format, Payflex is possibly under maintenance', 'woo_payflex') , 'error');
            }
            else
            {
                $message = 'Sorry, there was a problem preparing your payment.';
                if(isset($order_body->message) AND !empty($order_body->message))
                {
                    $message = $order_body->message;
                }

                wc_add_notice(__('Sorry, there was a problem processing your payment using Payflex.', 'woo_payflex') , 'error');

                $this->log('User attempted Payflex payment and an error occured - Successfully connected to Payflex gateway, but something went wrong. API Responded with: '.$message, 'error');

            }
            
            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        if ($access_token == false)
        {
            // Couldn't generate token
            if ($this->debug_mode)
            {
                wc_add_notice(__('Payflex API Token appears to be invalid', 'woo_payflex') , 'error');
            }
            else
            {
                wc_add_notice(__('Sorry, there was a problem preparing your payment.', 'woo_payflex') , 'error');
            }
            $order->add_order_note(__('Unable to generate the order token. Payment couldn\'t proceed.', 'woo_payflex'));
            
            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        $this->log('Created Payflex OrderId: ' . print_r($order_body->orderId, true));

        # Use WC_Order method to save meta
        // $order->update_meta_data('_partpay_order_token', $order_body->token);
        // $order->update_meta_data('_partpay_order_id',    $order_body->orderId);
        $order->update_meta_data('_order_redirectURL',   $order_body->redirectUrl);

        # Adding Payflex meta fields for future compatibility
        $order->update_meta_data('_payflex_order_token', $order_body->token);
        $order->update_meta_data('_payflex_order_id',    $order_body->orderId);
        $order->update_meta_data('_payflex_environment', payflex_environment());
        $order->save();

        $savedId = $order->get_meta('_payflex_order_id');

        # Order Link

        $current_order_url = urlencode(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));
        $admin_support_url = admin_url('admin.php?page=payflex-support&payflex_order_id=' . $savedId.'&redirect_url='.$current_order_url);
        $order_id_url = '<a href="'.$admin_support_url.'" >' . $savedId . '</a> ';
        
        # Add order note
        if($this->get_payflex_workflow_status($order_id) !== 'initiated')
        {
            $order_note = __('Payflex: User attempted Payflex order.<br>Transaction ID: ' . $order_id_url, 'woo_payflex');
            
            if(payflex_environment() == 'develop')    $order_note = __('Payflex: User attempted Payflex order in Sandbox Mode.<br>Transaction ID: ' . $order_id_url, 'woo_payflex');
            if(payflex_environment() == 'production') $order_note = __('Payflex: User attempted Payflex order.<br>Transaction ID: ' . $order_id_url, 'woo_payflex');
            if(payflex_environment() == 'unknown')    $order_note = __('Payflex: User attempted Payflex order.<br>Transaction ID: ' . $order_id_url, 'woo_payflex');

            $order->add_order_note($order_note);
        }

        $this->set_payflex_workflow_status($order_id, 'initiated');

        $this->log('Saved ' . $savedId . ' into post meta');

        $redirect = $order->get_checkout_payment_url(true);
        $this->log('Redirect URL ' . json_encode($redirect));
        return array(
            'result'   => 'success',
            'redirect' => $redirect
        );

    }

    /**
     * Update status after API redirect back to merchant site
     *
     * @since 1.0.0
     */
    public function receipt_page($order_id)
    {

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        $redirectURL = $order->get_meta('_order_redirectURL');

        //Update order status if it isn't already
        $is_pending = false;
        if (function_exists("has_status"))
        {
            $is_pending = $order->has_status('pending');
        }
        else
        {
            if ($order->get_status() == 'pending')
            {
                $is_pending = true;
            }
        }

        if (!$is_pending)
        {
            $order->update_status('pending');
        }

        $redirectURL_new = $redirectURL;

        if(isset($redirectURL[0]) && !empty($redirectURL[0])) $redirectURL_new = $redirectURL;

        $this->log('Payflex Checkout URL ' .$redirectURL_new);
        //Redirect to Payflex checkout
        header('Location: ' . $redirectURL_new);
        exit;
    }

    /**
     * @param $order_id
     */
    public function payment_callback($order_id)
    {
        header('Cache-Control: no-cache, no-store, must-revalidate');

        // Check for cookies in the header
        if (empty($_COOKIE)) {
            $this->log('No cookies detected, user either has cookies disabled or the browser is prefetching the page');
            // Instead of redirecting, just fail silently
            return;
        }

        // Make sure the order id is set
        if(!isset($_GET['order_id']) OR !isset($_GET['status']) OR !isset($_GET['token']))
        {
            $this->log('Invalid callback data received on payment callback');
            $this->payflex_redirect_failed();
        }

        $returned_order_id = sanitize_text_field($_GET['order_id']);
        $returned_status   = sanitize_text_field($_GET['status']);
        $returned_token    = sanitize_text_field($_GET['token']);

        if($this->current_order_proccessed === $returned_order_id)
        {
            $this->log('Order already processed, redirecting to payment page');
            $this->payflex_redirect_failed($order_id);
        }

        if(empty($returned_order_id) OR empty($returned_token))
        {
            $this->log('Invalid callback data received on payment callback');
            $this->payflex_redirect_failed($order_id);
        }

        // Get the order id, this is not to be trusted yet
        $order_id = $returned_order_id;

        // Get the order
        $order = $this->get_order($order_id);

        $remote_order_status = $this->payflex_remote_check_order_status($order_id);

        // If we don't get a remote status, the data is invalid
        if(!$remote_order_status) {
            $this->payflex_redirect_failed($order_id);
        }

        // get the payflex order id and token
        $payflex_order_token = $this->get_payflex_order_token($order_id);
        $payflex_order_id    = $this->get_payflex_order_id($order_id);

        // If we don't get a payflex order id or token, the data is invalid
        if(!$payflex_order_id OR !$payflex_order_token)
        {   
            $this->log('Invalid callback data received on payment callback');
            $this->payflex_redirect_failed($order_id);
        }

        // If we make it here, the order is both on the site and on Payflex

        $this->log(sprintf('Processing Payflex order for %s, payflex orderId: %s, token: %s', $order_id, $payflex_order_id, $payflex_order_token));
        
        $current_order_url = urlencode(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));

        // Comment order url
        $admin_support_url = admin_url('admin.php?page=payflex-support&payflex_order_id=' . $payflex_order_id.'&redirect_url='.$current_order_url);
        $order_id_url      = '<a href="'.$admin_support_url.'" >' . $payflex_order_id . '</a> ';
        
        if ($remote_order_status === 'Approved' AND !$order->has_status(['processing', 'completed']))
        {
            $order_note = __('Payflex: Payment approved.<br>Transaction ID: <a href="#" >' . $order_id_url, 'woo_payflex');

            if($this->get_payflex_workflow_status($order_id) !== 'completed' )
            {
                $order->add_order_note($order_note);
                $order->payment_complete($payflex_order_id);
            }
            else{
                $order->add_order_note(__('Payflex: Payment already completed, user returned more than once. Transaction ID: ' . $order_id_url, 'woo_payflex'));
            }

            $this->set_payflex_workflow_status($order_id, 'completed');

            # Save
            $order->save();

            $this->current_order_proccessed = $payflex_order_id;

            wc_empty_cart();

            $this->payflex_redirect_success($order_id);
            return true;
        }

        if ($remote_order_status === 'Declined' AND !$order->has_status('failed'))
        {
            $order_note = __('Payflex: Payment declined. Transaction ID: ' . $order_id_url, 'woo_payflex');

            if($this->get_payflex_workflow_status($order_id) !== 'failed')
                $order->add_order_note($order_note);
        
            $this->current_order_proccessed = $payflex_order_id;
            $order->update_status('failed');

            $this->set_payflex_workflow_status($order_id, 'failed');

            $this->payflex_redirect_failed($order_id);
        }
        
        if ($remote_order_status === 'Abandoned' AND !$order->has_status('failed'))
        {
            $order_note = __('Payflex: Payment abandoned. Transaction ID: ' . $order_id_url . ' ', 'woo_payflex');

            if($this->get_payflex_workflow_status($order_id) !== 'abandoned')
                $order->add_order_note($order_note);
            
            $this->current_order_proccessed = $payflex_order_id;
            $order->update_status('failed');

            $this->set_payflex_workflow_status($order_id, 'abandoned');

            $this->payflex_redirect_failed($order_id);
        }

        $this->payflex_redirect_unknown($order_id);
    }

    public function payflex_redirect_success($order_id = false)
    {
        $order = $this->get_order($order_id);
        $this->log('Payflex redirect success for order ' . $order_id);
        // Load success page
        wp_redirect($this->get_return_url($order)); exit;
    }

    public function payflex_redirect_unknown($order_id = false)
    {
        $order = $this->get_order($order_id);
        $this->log('Payflex redirect unknown for order ' . $order_id);
        wp_redirect($this->get_return_url($order)); exit;
    }

    public function payflex_redirect_failed($order_id = false)
    {
        $order = $this->get_order($order_id);
        $this->log('Payflex redirect failed for order ' . $order_id);
        wp_redirect($this->get_return_url($order)); exit;
    }

    /**
     * Adds a page to check the remote status of the order
     * 
     * 
     */
    public function page_check_remote_status()
    {
        if(isset($_GET['order_id']))
        {
            $order_id = sanitize_text_field($_GET['order_id']);
            $order = $this->get_order($order_id);
            $remote_order_status = $this->payflex_remote_check_order_status($order_id);
            $payflex_order_id    = $this->get_payflex_order_id($order_id);
            $payflex_order_token = $this->get_payflex_order_token($order_id);

            if(!$remote_order_status OR !$payflex_order_id OR !$payflex_order_token)
            {
                $this->log('Invalid callback data received on payment callback');
                wp_redirect($this->get_return_url($order)); exit;
            }

            $this->log('Remote status check for order ' . $order_id . ' returned ' . $remote_order_status);

            $order_note = sprintf(__('Remote status check for order ' . $order_id . ' returned ' . $remote_order_status, 'woo_payflex'));
            $order->add_order_note($order_note);
            wp_redirect($this->get_return_url($order)); exit;
        }
    }

    /**
     * Check whether the cart amount is within payment limits
     *
     * @param  array $gateways Enabled gateways
     * @return  array Enabled gateways, possibly with PartPay removed
     * @since 1.0.0
     */
    public function check_cart_within_limits($gateways)
    {

        global $woocommerce;
        $total = isset($woocommerce
            ->cart
            ->total) ? $woocommerce
            ->cart->total : 0;

        $access_token = $this->get_payflex_authorization_code();
        $config_response_transistent = get_transient('payflex_configuration_response');
        $api_url = $this->configurationUrl;
        if (false !== $config_response_transistent && !empty($config_response_transistent))
        {
            $order_response = $config_response_transistent;
            $order_body = json_decode($order_response);
        }
        else
        {

            $order_args = array(
                'method' => 'GET',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token
                ) ,
                'timeout' => 30
            );
            $order_response = wp_remote_post($api_url, $order_args);
            $order_response = wp_remote_retrieve_body($order_response);
            $order_body = json_decode($order_response);
            set_transient('payflex_configuration_response', $order_response, 86400);
        }
        if ($order_response)
        {

            $pbi = ($total >= $order_body->minimumAmount && $total <= $order_body->maximumAmount);

            if (!$pbi)
            {
                unset($gateways['partpay']);
            }

        }

        return $gateways;

    }

    /**
     * Can the order be refunded?
     *
     * @param  WC_Order $order
     * @return bool
     */
    public function can_refund_order($order)
    {
        return $order && $order->get_transaction_id();
    }

    /**
     * Process a refund if supported
     *
     * @param  WC_Order $order
     * @param  float $amount
     * @param  string $reason
     * @return  boolean True or false based on success
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if(function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        $this->log(sprintf('Attempting refund for order_id: %s', $order_id));

        $payflex_order_id = $this->get_payflex_order_id($order_id);
        
        $this->log(sprintf('Attempting refund for Payflex orderId: %s', $payflex_order_id));

        $order = new WC_Order($order_id);

        if (empty($payflex_order_id))
        {
            $order->add_order_note(sprintf(__('There was an error submitting the refund to Payflex.', 'woo_payflex')));
            return false;
        }

        $access_token = $this->get_payflex_authorization_code();
        $random_string = wp_generate_password(8, false, false);
        error_log('Payflex: orderId2' . $payflex_order_id);
        $refund_args = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ] ,
            'body' => json_encode([
                'requestId'               => 'Order #' . $order_id . '-' . $random_string,
                'amount'                  => $amount,
                'isPlugin'                => true,
                'merchantRefundReference' => 'Order #' . $order_id . '-' . $random_string
            ])
        ];
        error_log('Payflex: orderId3' . $payflex_order_id);
        $refundOrderUrl = $this->orderurl . '/' . $payflex_order_id . '/refund';

        $refund_response = wp_remote_post($refundOrderUrl, $refund_args);
        $refund_body = json_decode(wp_remote_retrieve_body($refund_response));

        $this->log('Refund body: ' . print_r($refund_body, true));
        error_log('Payflex: orderId3' . $payflex_order_id);
        $responsecode = isset($refund_response['response']['code']) ? intval($refund_response['response']['code']) : 0;

        if ($responsecode == 201 || $responsecode == 200) {
            $order->add_order_note(sprintf(__('Refund of $%s successfully sent to PayFlex.', 'woo_payflex') , $amount));
            return true;
        } else if($responsecode === 400 && $refund_body->errorCode==='MRM007') {
            $error_message = $refund_body->message;
            $order->add_order_note(sprintf(__($error_message), 'woo_payflex'));
            $error = new WP_Error( 'woocommerce_api_create_order_refund_api_failed', $error_message);    
            return $error;
        } else {
            if ($responsecode == 404) {
                $order->add_order_note(sprintf(__('Order not found on Payflex.', 'woo_payflex')));
            } else {
                $order->add_order_note(sprintf(__('There was an error submitting the refund to Payflex.', 'woo_payflex')));
            }
            return false;
        }

    }

    /**
     * Logging method
     * @param  string $message
     * @param  string $level 
     * @param  array $context
     */
    public static function log($message, $level = 'info', $context = [])
    {

        if (self::$log_enabled)
        {
            if (empty(self::$log))
            {
                self::$log = new WC_Logger();
            }
            
            self::$log->log($level, $message, $context);
        }
    }

    /**
     * @param $order_id
     */
    public function create_refund($order_id)
    {

        $order = new WC_Order($order_id);
        $order_refunds = $order->get_refunds();

        if (!empty($order_refunds))
        {
            $refund_amount = $order_refunds[0]->get_refund_amount();
            if (!empty($refund_amount))
            {
                $this->process_refund($order_id, $refund_amount, "Admin Performed Refund");
            }
        }
    }
    
    /**
     * Check the order status of all orders that didn't return to the thank you page or marked as Pending by Payflex
     *
     * @since 1.0.0
     */
    public function check_pending_abandoned_orders($skip_new_order_check = false)
    {
        // Check debug mode
        if($this->debug_mode)
            $this->log('#### Order CRON running ####');
    
        if($skip_new_order_check)
        {
            $pending_orders = $this->get_pending_abandoned_orders('all');
        }
        else
        {
            $pending_orders = $this->get_pending_abandoned_orders('scheduled');
        }

        $prefix = 'Payflex CRON: ';

        // If Debug mode is enable, we will know the cron is running, so we don't need to state it again
        if($this->debug_mode) $prefix = '';

        if(count($pending_orders) > 0 OR $this->debug_mode)
        {
            if(count($pending_orders) === 0)
            {
                $this->log($prefix.'No orders to check');
            }
            else
            {
                $this->log($prefix.'Checking ' . count($pending_orders) . " orders");
            }
        }

        foreach ($pending_orders as $pending_order)
        {
            // Use the available wc_get_order function if it exists
            if (function_exists("wc_get_order"))
                $order = wc_get_order($pending_order->get_id());

            if (!function_exists("wc_get_order"))
                $order = new WC_Order($pending_order->get_id());

            $order_id = $order->get_id();

            // Use wc meta function instead of get_post_meta
            $payflex_order_id = $this->get_payflex_order_id($order_id);
            
            // $this->log(print_r($order, true));
            
            // Check if there's a stored order token. If not, it's not an PartPay order.
            if (!$payflex_order_id)
            {
                $this->log('No Payflex OrderId for Order ' . $order_id);
                continue;
            }
            
            $this->log($prefix.'Checking abandoned order for WC Order ID ' . $order->get_id() . ', Payflex ID ' . $payflex_order_id);


            // Get the order status from Payflex
            $response = wp_remote_get($this->orderurl . '/' . $payflex_order_id, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->get_payflex_authorization_code() ,
                )
            ));
            $body = json_decode(wp_remote_retrieve_body($response));

            $response_code = wp_remote_retrieve_response_code($response);
            $settings = get_payflex_option();


            if ($response_code != 200)
            {
                // $order->add_order_note(sprintf(__('Tried to check payment status with Payflex. Unable to access API. Repsonse code is %s Payflex Order ID: %s','woo_payflex'),$response_code,$payflex_order_id));
                continue;
            }

            // Comment order url
            $current_order_url = urlencode(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));

            $admin_support_url = admin_url('admin.php?page=payflex-support&payflex_order_id=' . $payflex_order_id.'&redirect_url='.$current_order_url);
            $order_id_url = '<a href="'.$admin_support_url.'" >' . $payflex_order_id . '</a> ';

            $order_note = __('Payflex: Payment processed via CRON.<br>Transaction ID: ' . $order_id_url, 'woo_payflex');

            # Get workflow status
            $workflow_updated = FALSE;
            $workflow_status  = $this->get_payflex_workflow_status($order_id);

            if($body->orderStatus == "Initiated")
            {
                $this->log('Order is Intiated by customer but not logged in with Payflex OrderId :: '. $payflex_order_id);
                continue;
            }

            if ($body->orderStatus == "Approved")
            {
                $order_note = __('Payflex: Payment approved via CRON.<br>Transaction ID: ' . $order_id_url, 'woo_payflex');
                if($workflow_status !== 'completed') $workflow_updated = TRUE;

                if($workflow_updated)
                {
                    $order->add_order_note($order_note);
                    $order->payment_complete($order_id);
                }

                # Update meta status
                $this->set_payflex_workflow_status($order_id, 'completed');
                continue;
            }

            if ($body->orderStatus == "Created")
            {
                if($workflow_status !== $body->orderStatus.'_cron_checked') $workflow_updated = TRUE;

                $order_note = sprintf(__('Payflex: Checked payment status via CRON. Still pending approval.', 'woo_payflex') , $payflex_order_id);
                if($workflow_updated) $order->add_order_note($order_note);

                $this->set_payflex_workflow_status($order_id, $body->orderStatus.'_cron_checked');
                continue;
            }

            if ($body->orderStatus == 'Abandoned' OR $body->orderStatus == 'Declined')
            {

                if($workflow_status !== $body->orderStatus.'_cron_checked') $workflow_updated = TRUE;

                $order_note = __('Payflex: Payment checked via CRON. Order '.$body->orderStatus.'.<br>Transaction ID: ' . $order_id_url, 'woo_payflex');

                # If the workflow updated, we will update the order
                if($workflow_updated)
                {
                    $isExist = $this->checkOrderNotesExistsByOrderId($order_id, $order_note);

                    if(!$isExist){
                        $order->add_order_note($order_note);
                    }
                    $order->update_status('cancelled');
                }

                # Set order workflow status (always update after processing)
                $this->set_payflex_workflow_status($order_id, $body->orderStatus.'_cron_checked');

                continue;
            }

            if(!$this->checkOrderNotesExistsByOrderId($order_id, $order_note))
                $order->add_order_note($order_note);

            # We will continue to update the order, but not update the order notes
            $order->update_status('failed');

        }

        $this->log('++++ Order CRON Finished ++++');
    }

    /**
     * Gets a list of orders in two arrays - new orders and orders ready for processing
     *
     * @param string|bool $new_scheduled Whether to get new orders or orders ready for processing ("all", "new", "scheduled", or false/nothing for both)
     * @return array $pending_orders An array of pending orders (new_orders, orders_ready_for_processing)
     */
    public function get_pending_abandoned_orders($new_scheduled = false)
    {
        // Get all orders that are pending, including the new ones.
        if($new_scheduled == 'all')
        {
            $pending_orders = wc_get_orders([
                'status' => [
                    'pending',
                    'failed',
                    'cancelled'
                ],
                'payment_method' => 'payflex',

                // get orders from the last 2 hours
                'date_created' => (time() - 7200).'...'.time()
            ]);

            return $pending_orders;
        }

        // Get all new orders that aren't going to get processed yet
        if($new_scheduled == 'new' OR $new_scheduled == FALSE)
        {
            $pending_orders['new'] = wc_get_orders([
                'payment_method' => 'payflex',

                // get orders from the last 30 minutes
                'date_created' => (time() - 1800).'...'.time(),
                'meta_query' => [
                    [
                        'key' => '_payflex_workflow_status',
                        'value' => 'initiated',
                        'compare' => '='
                    ]
                ]
            ]);

            if($new_scheduled == 'new') return $pending_orders['new'];
        }

        // Get all orders that are scheduled for processing by the CRON
        if($new_scheduled == 'scheduled' OR $new_scheduled == FALSE)
        {
            $pending_orders['scheduled'] = wc_get_orders([
                'payment_method' => 'payflex',
                'meta_query' => [
                    [
                        'key' => '_payflex_workflow_status',
                        'value' => 'initiated',
                        'compare' => '='
                    ]
                ],

                // get orders older than 30 minutes but less than 2 hours
                'date_created' => (time() - 7200).'...'.(time() - 1800)
            ]);

            if($new_scheduled == 'scheduled') return $pending_orders['scheduled'];
        }

        return $pending_orders;
    }

    /**
     * Check if the order notes already exists
     *
     * @param int $order_id
     * @param string $content
     * @return void
     */
    public function checkOrderNotesExistsByOrderId($order_id, $content)
    {
        if (method_exists('WC_Order', 'get_customer_order_notes'))
        {
            $order       = wc_get_order($order_id);
            $order_notes = $order->get_customer_order_notes();
        }
        else
        {
            $order_notes = get_comments([
                'post_id' => $order_id,
                'author'  => 'WooCommerce',
                'type'    => 'order_note'
            ]);
        }

        foreach ($order_notes as $note)
        {
            if ($note->comment_content == $content)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks the current order token, and regenerates it if it's successful
     *
     * @param string|int   $order_id
     * @param string       $order_token
     * @return string|bool The order token or false if it fails
     */
    public function check_order_token($order_id, $order_token)
    {
        $order = wc_get_order($order_id);

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        if(!$order)
            return false;


        $payflex_token = $order->get_meta('_payflex_token');

        // Legacy checks, first check new order meta, then check old meta
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_token, '_payflex_token', true);
        
        // If it's still empty, check legacy meta
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_token, '_partpay_token');

        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_token, '_partpay_token', true);

        if(empty($payflex_token))
            return false;

    }

    /**
     * Get the Payflex order token
     *
     * @param [type] $order_id
     * @return string|bool
     */
    public function get_payflex_order_token($order_id)
    {
        $order = $this->get_order($order_id);

        $payflex_token = $order->get_meta('_payflex_order_token');

        // Legacy checks, first check new order meta, then check old meta fields
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_id, '_payflex_order_token', true);
        
        // If it's still empty, check legacy meta
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_id, '_partpay_order_token');

        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_id, '_partpay_order_token', true);

        if(empty($payflex_token))
            return false;

        return $payflex_token;
    }

    public function get_payflex_order_id($order_id)
    {
        $order = $this->get_order($order_id);

        $payflex_order_id = $order->get_meta('_payflex_order_id');

        // Legacy checks, first check new order meta, then check old meta fields
        if(empty($payflex_order_id))
            $payflex_order_id = get_post_meta($order_id, '_payflex_order_id', true);
        
        // If it's still empty, check legacy meta
        if(empty($payflex_order_id))
            $payflex_order_id = get_post_meta($order_id, '_partpay_order_id');

        if(empty($payflex_order_id))
            $payflex_order_id = get_post_meta($order_id, '_partpay_order_id', true);

        if(empty($payflex_order_id))
            return false;

        return $payflex_order_id;
    }

    /**
     * Gets the current workflow status of the order, this isn't the order status. It's how far along the
     * order is in the Payflex workflow. ['initiated', 'processing', 'approved', 'declined', 'abandoned']
     *
     * @param mixed $order_id
     * @return string|bool
     */
    public function get_payflex_workflow_status($order_id)
    {
        $order = $this->get_order($order_id);

        if($this->payflex_worfklow_status)
            return $this->payflex_worfklow_status;

        $payflex_workflow_status = $order->get_meta('_payflex_workflow_status');

        if(!$payflex_workflow_status)
            return false;

        return $payflex_workflow_status;
    }

    /**
     * Set the workflow status of the order
     *
     * @param mixed $order_id
     * @param string $status ['initiated', 'processing', 'approved', 'declined', 'abandoned']
     * @return void
     */
    public function set_payflex_workflow_status($order_id, $status)
    {
        $order = $this->get_order($order_id);
        
        $order->update_meta_data('_payflex_workflow_status', $status);
        
        $order->save();

        $this->payflex_worfklow_status = $status;

        return true;
    }

    /**
     * Get the order object
     *
     * @param  int $order_id
     * @return  WC_Order
     */
    public function get_order($order_id, $new_order = false)
    {
        if(!$order_id)
            return false;

        // If the order is already set, return it
        if($this->WC_Order && !$new_order && $order_id == $this->WC_Order_ID)
            return $this->WC_Order;
        
        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        $this->WC_Order    = $order;
        $this->WC_Order_ID = $order_id;

        return $order;
    
    }

    public function payflex_remote_check_order_status($order_id)
    {
        $order = $this->get_order($order_id);

        $payflex_token = $this->get_payflex_order_id($order_id);

        if(!$payflex_token)
            return false;

        $response = wp_remote_get($this->orderurl . '/' . $payflex_token, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->get_payflex_authorization_code() ,
            )
        ));

        $body = json_decode(wp_remote_retrieve_body($response));

        if(!$body) return false;
        if(!isset($body->token)) return false;
        if(!isset($body->amount)) return false;

        // Check against local token
        $local_token = $this->get_payflex_order_token($order_id);

        if($local_token != $body->token) return false;

        // Check amount
        if($order->get_total() != $body->amount) return false;

        if(!isset($body->orderStatus)) return false;

        return $body->orderStatus;
    }

    public function payflex_remote_get_order($payflex_order_id)
    {
        // sanitize the order id
        $payflex_order_id = sanitize_text_field($payflex_order_id);

        if(!$payflex_order_id)
            return false;

        $response = wp_remote_get($this->orderurl . '/' . $payflex_order_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->get_payflex_authorization_code() ,
            )
        ));

        $body = json_decode(wp_remote_retrieve_body($response));

        return $body;
    }

}
