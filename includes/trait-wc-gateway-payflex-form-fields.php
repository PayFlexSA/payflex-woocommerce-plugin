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

        $category_options = $this->on_settings_screen() ? $this->product_category_options() : [];

        $this->form_fields = [

            // General
            'section_general_start' => [
                'type'  => 'section_start',
                'title' => __('General', 'payflex-payment-gateway'),
                'icon'  => 'admin-settings',
            ],
            'enabled' => [
                'title'   => __('Enable/Disable', 'payflex-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable Payflex', 'payflex-payment-gateway'),
                'default' => 'yes',
            ],
            'widget_only_mode' => [
                'title'       => __('Widget Only Mode', 'payflex-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable Widget Only Mode', 'payflex-payment-gateway'),
                'default'     => 'no',
                'description' => __('Show the Payflex widget on product pages without offering Payflex as a payment method. API credentials are not needed. For stores that already have their own Payflex integration.', 'payflex-payment-gateway'),
            ],
            'title' => [
                'title'       => __('Title', 'payflex-payment-gateway'),
                'type'        => 'text',
                'description' => __('Payment method title shown to the customer during checkout.', 'payflex-payment-gateway'),
                'default'     => __('Payflex', 'payflex-payment-gateway'),
            ],
            'section_general_end' => ['type' => 'section_end'],

            // API Credentials
            'section_credentials_start' => [
                'type'  => 'section_start',
                'title' => __('API Credentials', 'payflex-payment-gateway'),
                'icon'  => 'lock',
                'class' => 'pf-section--credentials',
            ],
            'testmode' => [
                'title'       => __('Environment', 'payflex-payment-gateway'),
                'type'        => 'select',
                'options'     => $env_values,
                'description' => __('Select Sandbox or Production.', 'payflex-payment-gateway'),
            ],
            'client_id' => [
                'title'       => __('Client ID', 'payflex-payment-gateway'),
                'type'        => 'text',
                'description' => '<span class="pfConnectionStatus ' . $pf_connection_status_class . '">' . esc_html($pf_connection_status) . '</span>',
                'default'     => '',
            ],
            'client_secret' => [
                'title'   => __('Client Secret', 'payflex-payment-gateway'),
                'type'    => 'password_toggle',
                'default' => '',
            ],
            'section_credentials_end' => ['type' => 'section_end'],

            // Widget
            'section_widget_start' => [
                'type'  => 'section_start',
                'title' => __('Widget', 'payflex-payment-gateway'),
                'icon'  => 'visibility',
                'class' => 'pf-section--widget',
            ],
            'widget_style' => [
                'title'   => __('Style', 'payflex-payment-gateway'),
                'type'    => 'select',
                'options' => ['purple' => 'Purple', 'navy' => 'Navy'],
                'default' => 'purple',
            ],
            'widget_theme' => [
                'title'   => __('Theme', 'payflex-payment-gateway'),
                'type'    => 'select',
                'options' => ['' => 'Default', 'dark' => 'Dark'],
                'default' => '',
            ],
            'pay_type' => [
                'title'   => __('Pay Type', 'payflex-payment-gateway'),
                'type'    => 'select',
                'options' => ['4' => 'Pay in 4', '3' => 'Pay in 3'],
                'default' => '4',
            ],
            'widget_preview' => [
                'type'  => 'widget_preview',
                'title' => __('Preview', 'payflex-payment-gateway'),
            ],
            'enable_product_widget' => [
                'title'   => __('Product Page', 'payflex-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Show widget on product pages', 'payflex-payment-gateway'),
                'default' => 'yes',
            ],
            'enable_checkout_widget' => [
                'title'   => __('Checkout Page', 'payflex-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Show widget on the checkout page', 'payflex-payment-gateway'),
                'default' => 'yes',
            ],
            // 'widget_custom_css' => [
            //     'title'       => __('Custom CSS', 'payflex-payment-gateway'),
            //     'type'        => 'textarea',
            //     'description' => __('CSS injected alongside the widget on product and checkout pages.', 'payflex-payment-gateway'),
            //     'default'     => '',
            //     'placeholder' => '.payflexCalculatorWidgetContainer { }',
            //     'css'         => 'font-family: Consolas, monospace; font-size: 12px; height: 120px; resize: vertical;',
            // ],
            'section_widget_end' => ['type' => 'section_end'],

            // Eligibility
            'section_eligibility_start' => [
                'type'  => 'section_start',
                'title' => __('Eligibility', 'payflex-payment-gateway'),
                'icon'  => 'filter',
                'class' => 'pf-section--eligibility',
            ],
            'exclude_subscriptions' => [
                'title'       => __('Subscriptions', 'payflex-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Block Payflex on subscription products', 'payflex-payment-gateway'),
                'default'     => 'no',
                'description' => __('Payflex cannot be used for recurring payments.', 'payflex-payment-gateway'),
            ],
            'enable_product_exclusions' => [
                'title'       => __('Per Product', 'payflex-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Allow individual products to be excluded', 'payflex-payment-gateway'),
                'default'     => 'no',
                'description' => __('Adds a Payflex checkbox to the product data panel.', 'payflex-payment-gateway') . $this->product_exclusion_count(),
            ],
            'excluded_product_cats' => [
                'title'       => __('Excluded Categories', 'payflex-payment-gateway'),
                'type'        => 'multiselect',
                'class'       => 'wc-enhanced-select',
                'options'     => $category_options,
                'default'     => [],
                'description' => __('Payflex is hidden when the cart contains a product from these categories.', 'payflex-payment-gateway') . $this->category_exclusion_count(),
            ],
            'section_eligibility_end' => ['type' => 'section_end'],

            // Advanced
            'section_advanced_start' => [
                'type'  => 'section_start',
                'title' => __('Advanced', 'payflex-payment-gateway'),
                'icon'  => 'admin-tools',
            ],
            'admin_only_enabled' => [
                'title'       => __('Admin Only Mode', 'payflex-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable Admin Only Mode', 'payflex-payment-gateway'),
                'default'     => 'no',
                'description' => __('Only enable Payflex for logged-in admins. "Enable Payflex" must also be checked.', 'payflex-payment-gateway'),
            ],
            'payflex_debug' => [
                'title'       => __('Debug Output', 'payflex-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable Debug Output', 'payflex-payment-gateway'),
                'default'     => 'no',
                'description' => __('Enable debug messages. Only enable during testing.', 'payflex-payment-gateway'),
            ],
            'section_advanced_end' => ['type' => 'section_end'],
        ];

        return $this->form_fields;
    }

    /**
     * Product categories as term id => name.
     */
    private function product_category_options()
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);

        if(is_wp_error($terms) OR !is_array($terms)) return [];

        $options = [];

        foreach($terms as $term)
        {
            $options[$term->term_id] = $term->name;
        }

        return $options;
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
        $title = isset($data['title']) ? esc_html($data['title']) : esc_html__('Preview', 'payflex-payment-gateway');
        return '<tr class="pf-widget-preview-row"><th>' . $title . '</th><td><div class="pfwidgetpreview"></div></td></tr>';
    }

    /**
     * Whether this request is the WooCommerce settings screen.
     *
     * form_fields() runs from the gateway constructor on every admin request,
     * so anything that costs a database query has to be limited to the one
     * screen that displays it.
     */
    private function on_settings_screen()
    {
        if(!is_admin()) return false;
        if(!isset($_GET['page'])) return false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check; it only decides whether a count query is worth running.

        return $_GET['page'] === 'wc-settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check; nothing is changed and the value is compared, not used.
    }

    /**
     * Count of the products excluded with the per product checkbox, shown under
     * that setting and linked to the products list filtered to them.
     */
    private function product_exclusion_count()
    {
        if(!$this->on_settings_screen()) return '';

        // Nothing to report while individual exclusions are switched off
        if(get_payflex_option('enable_product_exclusions') !== 'yes') return '';

        $count = Payflex_Admin_Products::excluded_product_count();

        if(!$count) return $this->exclusion_count_badge(__('No products are currently excluded', 'payflex-payment-gateway'), '', true);

        $label = sprintf(
            /* translators: %d: number of excluded products. */
            _n('%d product currently excluded', '%d products currently excluded', $count, 'payflex-payment-gateway'),
            $count
        );

        return $this->exclusion_count_badge($label, Payflex_Admin_Products::excluded_list_url());
    }

    /**
     * Count of the products the excluded categories cover, shown under that
     * setting once at least one category has been chosen.
     */
    private function category_exclusion_count()
    {
        if(!$this->on_settings_screen()) return '';

        $excluded = get_payflex_option('excluded_product_cats');

        // Nothing to report until a category has been chosen
        if(empty($excluded) OR !is_array($excluded)) return '';

        $count = Payflex_Admin_Products::category_excluded_product_count();

        if(!$count) return $this->exclusion_count_badge(__('No products currently sit in these categories', 'payflex-payment-gateway'), '', true);

        $label = sprintf(
            /* translators: %d: number of excluded products. */
            _n('%d product currently excluded', '%d products currently excluded', $count, 'payflex-payment-gateway'),
            $count
        );

        return $this->exclusion_count_badge($label);
    }

    /**
     * The badge a count is shown in. Given a url it becomes a link, and a
     * nothing-to-report message is toned down.
     */
    private function exclusion_count_badge($label, $url = '', $nothing_excluded = false)
    {
        $class = $nothing_excluded ? 'pf-exclusion-count pf-exclusion-count--empty' : 'pf-exclusion-count';
        $inner = '<span class="dashicons dashicons-hidden"></span>' . esc_html($label);

        if(!$url) return '<br /><span class="' . $class . '">' . $inner . '</span>';

        return '<br /><a class="' . $class . '" href="' . esc_url($url) . '">' . $inner . '</a>';
    }

    /**
     * Renders a password input with a reveal/hide eye toggle button.
     */
    public function generate_password_toggle_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $defaults  = [
            'title'       => '',
            'description' => '',
            'placeholder' => '',
            'class'       => '',
            'css'         => '',
        ];
        $data  = wp_parse_args($data, $defaults);
        $value = $this->get_option($key);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
            </th>
            <td class="forminp">
                <div class="pf-password-wrap">
                    <input
                        type="password"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        class="input-text regular-input <?php echo esc_attr($data['class']); ?>"
                        style="<?php echo esc_attr($data['css']); ?>"
                        placeholder="<?php echo esc_attr($data['placeholder']); ?>"
                    />
                    <button type="button" class="pf-toggle-secret" onclick="pfToggleSecret(this)" aria-label="<?php esc_attr_e('Toggle visibility', 'payflex-payment-gateway'); ?>">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                </div>
                <?php if (!empty($data['description'])): ?>
                    <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Save handler for the password_toggle field — delegates to the standard text validator.
     */
    public function validate_password_toggle_field($key, $value)
    {
        return $this->validate_text_field($key, $value);
    }

    /**
     * Checks if the form fields match saved options; returns any fields missing from saved options.
     */
    public function form_field_check()
    {
        $saved_options_full = get_payflex_option();
        $saved_options      = array_keys($saved_options_full);

        $form_fields_full = $this->form_fields();

        // Section markers and the preview row hold no value, so they are never saved
        $presentational = ['section_start', 'section_end', 'widget_preview'];

        $missing_fields = [];

        foreach ($form_fields_full as $key => $field)
        {
            if (in_array($field['type'], $presentational, true)) continue;

            if (!in_array($key, $saved_options))
            {
                $missing_fields[] = $key;
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
        // admin_footer fires on every admin page, and this CSS is only meant
        // for the gateway settings screen
        if(!$this->on_settings_screen()) return;

        ?>
        <script>
        function pfToggleSecret(btn) {
            var input = btn.closest('.pf-password-wrap').querySelector('input');
            var icon  = btn.querySelector('.dashicons');
            var show  = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('dashicons-visibility', !show);
            icon.classList.toggle('dashicons-hidden',    show);
        }

        // Credentials and the checkout widget play no part in widget only mode
        function pfUpdateWidgetOnlyMode() {
            var widgetOnly = jQuery('#woocommerce_payflex_widget_only_mode').is(':checked');

            jQuery('.pf-section--credentials').toggle(!widgetOnly);
            jQuery('#woocommerce_payflex_enable_checkout_widget').closest('tr').toggle(!widgetOnly);
        }

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
            pfUpdateWidgetOnlyMode();

            $(document).on('change', '#woocommerce_payflex_widget_only_mode', pfUpdateWidgetOnlyMode);

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

            /* ── Exclusion counts, shown under their own setting ────────── */
            .pf-exclusion-count {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                margin-top: 7px;
                padding: 2px 10px 2px 7px;
                border-radius: 11px;
                border: 1px solid #e0cdef;
                background: #f6effb;
                color: #6b3a8c;
                font-size: 12px;
                font-weight: 600;
                line-height: 1.7;
                text-decoration: none;
            }

            a.pf-exclusion-count:hover,
            a.pf-exclusion-count:focus {
                background: #ecdcf7;
                border-color: #cdaee3;
                color: #4f2a68;
                box-shadow: none;
            }

            .pf-exclusion-count--empty {
                border-color: #dcdcdc;
                background: #f6f7f7;
                color: #646970;
                font-weight: 400;
            }

            .pf-exclusion-count .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
                line-height: 1.2;
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

            /* ── Password toggle ────────────────────────────────────────── */
            .pf-password-wrap {
                position: relative;
                display: flex;
                align-items: center;
            }

            .pf-password-wrap input[type="password"],
            .pf-password-wrap input[type="text"] {
                padding-right: 34px !important;
            }

            .pf-toggle-secret {
                position: absolute;
                right: 8px;
                background: none !important;
                border: none !important;
                box-shadow: none !important;
                cursor: pointer;
                padding: 0 !important;
                color: #646970 !important;
                text-decoration: none !important;
                line-height: 1;
                outline: none;
            }

            .pf-toggle-secret:hover {
                color: #1d2327 !important;
            }

            .pf-toggle-secret:focus {
                box-shadow: none !important;
                outline: none !important;
            }

            .pf-toggle-secret .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                line-height: 1.1;
            }
        </style>
        <?php
    }
}
