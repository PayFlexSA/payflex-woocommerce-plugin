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
        $payflex_api_accessable     = ($this->get_payflex_authorization_code() !== false);
        $pf_connection_status       = $payflex_api_accessable ? 'Successfully connected' : 'Connection failed, please check your credentials';
        $pf_connection_status_class = $payflex_api_accessable ? 'payflex_debug_success' : 'payflex_debug_error';

        $env_values = array();
        foreach ($this->environments as $key => $item)
        {
            $env_values[$key] = $item["name"];
        }

        $this->form_fields = [

            // General
            'section_general_start' => [
                'type'  => 'section_start',
                'title' => __('General', 'woo_payflex'),
                'icon'  => 'admin-settings',
            ],
            'enabled' => [
                'title'   => __('Enable/Disable', 'woo_payflex'),
                'type'    => 'checkbox',
                'label'   => __('Enable Payflex', 'woo_payflex'),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __('Title', 'woo_payflex'),
                'type'        => 'text',
                'description' => __('Payment method title shown to the customer during checkout.', 'woo_payflex'),
                'default'     => __('Payflex', 'woo_payflex'),
            ],
            'section_general_end' => ['type' => 'section_end'],

            // API Credentials
            'section_credentials_start' => [
                'type'  => 'section_start',
                'title' => __('API Credentials', 'woo_payflex'),
                'icon'  => 'lock',
            ],
            'testmode' => [
                'title'       => __('Environment', 'woo_payflex'),
                'type'        => 'select',
                'options'     => $env_values,
                'description' => __('Select Sandbox or Production.', 'woo_payflex'),
            ],
            'client_id' => [
                'title'       => __('Client ID', 'woo_payflex'),
                'type'        => 'text',
                'description' => '<span class="pfConnectionStatus ' . $pf_connection_status_class . '">' . esc_html($pf_connection_status) . '</span>',
                'default'     => '',
            ],
            'client_secret' => [
                'title'   => __('Client Secret', 'woo_payflex'),
                'type'    => 'text',
                'default' => '',
            ],
            'section_credentials_end' => ['type' => 'section_end'],

            // Widget
            'section_widget_start' => [
                'type'  => 'section_start',
                'title' => __('Widget', 'woo_payflex'),
                'icon'  => 'visibility',
                'class' => 'pf-section--widget',
            ],
            'widget_style' => [
                'title'   => __('Style', 'woo_payflex'),
                'type'    => 'select',
                'options' => ['purple' => 'Purple', 'navy' => 'Navy'],
                'default' => 'purple',
            ],
            'widget_theme' => [
                'title'   => __('Theme', 'woo_payflex'),
                'type'    => 'select',
                'options' => ['' => 'Default', 'dark' => 'Dark'],
                'default' => '',
            ],
            'pay_type' => [
                'title'   => __('Pay Type', 'woo_payflex'),
                'type'    => 'select',
                'options' => ['4' => 'Pay in 4', '3' => 'Pay in 3'],
                'default' => '4',
            ],
            'widget_preview' => [
                'type'  => 'widget_preview',
                'title' => __('Preview', 'woo_payflex'),
            ],
            'enable_product_widget' => [
                'title'   => __('Product Page', 'woo_payflex'),
                'type'    => 'checkbox',
                'label'   => __('Show widget on product pages', 'woo_payflex'),
                'default' => 'yes',
            ],
            'enable_checkout_widget' => [
                'title'   => __('Checkout Page', 'woo_payflex'),
                'type'    => 'checkbox',
                'label'   => __('Show widget on the checkout page', 'woo_payflex'),
                'default' => 'yes',
            ],
            'widget_custom_css' => [
                'title'       => __('Custom CSS', 'woo_payflex'),
                'type'        => 'textarea',
                'description' => __('CSS injected alongside the widget on product and checkout pages.', 'woo_payflex'),
                'default'     => '',
                'placeholder' => '.payflexCalculatorWidgetContainer { }',
                'css'         => 'font-family: Consolas, monospace; font-size: 12px; height: 120px; resize: vertical;',
            ],
            'section_widget_end' => ['type' => 'section_end'],

            // Advanced
            'section_advanced_start' => [
                'type'  => 'section_start',
                'title' => __('Advanced', 'woo_payflex'),
                'icon'  => 'admin-tools',
            ],
            'admin_only_enabled' => [
                'title'       => __('Admin Only Mode', 'woo_payflex'),
                'type'        => 'checkbox',
                'label'       => __('Enable Admin Only Mode', 'woo_payflex'),
                'default'     => 'no',
                'description' => __('Only enable Payflex for logged-in admins. "Enable Payflex" must also be checked.', 'woo_payflex'),
            ],
            'payflex_debug' => [
                'title'       => __('Debug Output', 'woo_payflex'),
                'type'        => 'checkbox',
                'label'       => __('Enable Debug Output', 'woo_payflex'),
                'default'     => 'no',
                'description' => __('Enable debug messages. Only enable during testing.', 'woo_payflex'),
            ],
            'section_advanced_end' => ['type' => 'section_end'],
        ];

        return $this->form_fields;
    }

    /**
     * Renders a card section opening: header + inner form-table.
     */
    public function generate_section_start_html($key, $data)
    {
        $icon      = isset($data['icon'])  ? '<span class="dashicons dashicons-' . sanitize_html_class($data['icon']) . '"></span>' : '';
        $title     = isset($data['title']) ? esc_html($data['title']) : '';
        $extra_cls = isset($data['class']) ? ' ' . sanitize_html_class($data['class']) : '';

        $html  = '<div class="pf-section' . $extra_cls . '">';
        $html .= '<div class="pf-section-header">' . $icon . '<h4>' . $title . '</h4></div>';
        $html .= '<table class="form-table pf-section-table"><tbody>';

        return $html;
    }

    /**
     * Closes the inner table and card div opened by section_start.
     */
    public function generate_section_end_html($key, $data)
    {
        return '</tbody></table></div>';
    }

    /**
     * Renders the live widget preview row (display only, not saved).
     */
    public function generate_widget_preview_html($key, $data)
    {
        $title = isset($data['title']) ? esc_html($data['title']) : esc_html__('Preview', 'woo_payflex');
        return '<tr class="pf-widget-preview-row"><th>' . $title . '</th><td><div class="pfwidgetpreview"></div></td></tr>';
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
        function pfUpdateWidgetPreview() {
            var style   = jQuery('#woocommerce_payflex_widget_style').val();
            var theme   = jQuery('#woocommerce_payflex_widget_theme').val();
            var payType = jQuery('#woocommerce_payflex_pay_type').val();
            var preview = jQuery('.pfwidgetpreview');

            preview.toggleClass('dark', theme !== '');
            preview.html('<script src="https://widgets.payflex.co.za/your-merchant-name/2.0.3/payflex-widget.js?type=calculator&amount=1000&logo_type=' + style + '&theme=' + theme + '&pay_type=' + payType + '"><\/script>');
        }

        jQuery(document).ready(function($) {
            pfUpdateWidgetPreview();

            $(document).on('change', '#woocommerce_payflex_widget_style, #woocommerce_payflex_widget_theme, #woocommerce_payflex_pay_type', pfUpdateWidgetPreview);

            $(document).on('keyup', '#woocommerce_payflex_client_id, #woocommerce_payflex_client_secret', function() {
                $('.pfConnectionStatus')
                    .text('Save settings to attempt authentication')
                    .removeClass('payflex_debug_success payflex_debug_error');
            });

            // Sanitise merchant widget reference to URL-safe characters
            $(document).on('keyup', '#woocommerce_payflex_merchant_widget_reference', function() {
                var val = $(this).val()
                    .replace(/ /g, '-')
                    .replace(/-+/g, '-')
                    .replace(/[^a-zA-Z0-9-_]/g, '');
                $(this).val(val);
                $('.pf-merch-value').text(val || 'your-merchant-name');
            });
        });
        </script>

        <style>
            /* ── Outer layout ───────────────────────────────────────────── */
            .pf-settings-wrap {
                max-width: 1100px;
            }

            @media (min-width: 800px) {
                .pf-settings-wrap {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
                    gap: 16px;
                    align-items: start;
                }

                .pf-settings-wrap .pf-section {
                    margin-bottom: 0;
                    min-width: 0;
                }

                .pf-section--widget {
                    grid-column: 1 / -1;
                }
            }

            /* ── Section cards ──────────────────────────────────────────── */
            .pf-section {
                background: #fff;
                border: 1px solid #dcdcdc;
                border-radius: 4px;
                margin-bottom: 16px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, .05);
            }

            .pf-section-header {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 11px 18px;
                background: #f6f7f7;
                border-bottom: 1px solid #dcdcdc;
                border-radius: 4px 4px 0 0;
                border-left: 3px solid #7c3fa0;
            }

            .pf-section-header .dashicons {
                color: #7c3fa0;
                font-size: 17px;
                width: 17px;
                height: 17px;
                line-height: 1;
                flex-shrink: 0;
            }

            .pf-section-header h4 {
                margin: 0;
                font-size: 13px;
                font-weight: 600;
                color: #1d2327;
            }

            /* ── Override WC's side-by-side th/td: stack label above input ── */
            .pf-section-table,
            .pf-section-table tbody {
                display: block;
                width: 100%;
            }

            .pf-section-table tr {
                display: block;
                padding: 12px 18px;
                border-bottom: 1px solid #f0f0f0;
            }

            .pf-section-table tbody tr:last-child {
                border-bottom: none;
            }

            .pf-section-table th,
            .pf-section-table td {
                display: block;
                padding: 0 !important;
                width: 100% !important;
            }

            .pf-section-table th {
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                color: #646970;
                margin-bottom: 5px;
                line-height: 1.4;
            }

            .pf-section-table .description {
                font-size: 12px;
                color: #646970;
                margin-top: 4px;
                display: block;
            }

            .pf-section-table input[type="text"],
            .pf-section-table input[type="password"],
            .pf-section-table input[type="email"],
            .pf-section-table select,
            .pf-section-table textarea {
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box;
            }

            /* ── Widget section: selects in 3-column row ────────────────── */
            .pf-section--widget .pf-section-table tbody {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
            }

            /* First 3 rows sit side-by-side; add vertical separators */
            .pf-section--widget .pf-section-table tbody tr:nth-child(1),
            .pf-section--widget .pf-section-table tbody tr:nth-child(2) {
                border-right: 1px solid #f0f0f0;
            }

            /* Preview and everything after spans all 3 columns */
            .pf-section--widget .pf-section-table tbody tr:nth-child(n+4) {
                grid-column: 1 / -1;
            }

            /* Widget preview container */
            .pfwidgetpreview {
                display: block;
                width: 100%;
                border: 1px solid #dcdcdc;
                border-radius: 4px;
                overflow: auto;
                min-height: 60px;
                margin-top: 6px;
            }

            .pfwidgetpreview.dark {
                background-color: #1e1e1e;
            }

            /* ── Connection status pill ─────────────────────────────────── */
            .pfConnectionStatus {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 500;
                margin-top: 4px;
            }

            .payflex_debug_success {
                background: #edfaef;
                color: #1a7431;
                border: 1px solid #b7dfc0;
            }

            .payflex_debug_error {
                background: #fce8e8;
                color: #a00;
                border: 1px solid #f5c6cb;
            }

            .pf_merchant_ref_example {
                font-size: 12px;
                background: #fff;
                padding: 2px 4px;
                border-radius: 3px;
            }
        </style>
        <?php
    }
}
