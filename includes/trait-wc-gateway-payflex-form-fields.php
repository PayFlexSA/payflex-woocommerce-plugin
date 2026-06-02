<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings form fields and supporting admin scripts for the Payflex gateway.
 *
 * Extracted from WC_Gateway_PartPay to keep the main class focused on
 * payment logic. Requires $this->environments (populated by init_environment_config)
 * and the get_payflex_option() helper.
 */
trait WC_Gateway_Payflex_Form_Fields
{
    /**
     * Build and return the WooCommerce settings form field definitions.
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
        $pf_merch_ref_example = 'https://widgets.payflex.co.za/<span class="pf-merch-value">'.$pf_merch_value.'</span>/2.0.3/payflex-widget.min.js?type=calculator';

        $pf_merch_ref_example = 'https://widgets.payflex.co.za/<span class="pf-merch-value">'.get_payflex_option('merchant_widget_reference').'</span>/2.0.3/payflex-widget.min.js?type=calculator';

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
     * Checks if the form fields match saved options; returns any fields missing from saved options.
     */
    public function form_field_check()
    {
        $saved_options_full = get_payflex_option();
        $saved_options      = array_keys($saved_options_full);

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
     * Initialise Gateway Settings Form Fields.
     *
     * @since 1.0.0
     */
    public function init_form_fields()
    {
        $this->form_fields();

        add_action('admin_footer', array(
            $this,
            'add_script_to_settings_page'
        ));
    }

    /**
     * Output inline JS and CSS needed on the gateway settings page.
     */
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
                var widget_preview_url = 'https://widgets.payflex.co.za/your-merchant-name/2.0.3/payflex-widget.js?type=calculator&amount=1000&logo_type=' + widget_style + '&theme=' + widget_theme + '&pay_type=' + pay_type;
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
                var widget_preview_url = 'https://widgets.payflex.co.za/your-merchant-name/2.0.3/payflex-widget.js?type=calculator&amount=1000&logo_type=' + widget_style + '&theme=' + widget_theme + '&pay_type=' + pay_type;
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
}
