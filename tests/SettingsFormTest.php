<?php

/**
 * The WooCommerce settings screen: field definitions, the custom field
 * renderers in WC_Gateway_Payflex_Form_Fields, and credential saving.
 */
final class SettingsFormTest extends PF_TestCase
{
    /** Keys that must exist for the plugin to be configurable at all. */
    private const REQUIRED_FIELDS = [
        'enabled',
        'title',
        'testmode',
        'client_id',
        'client_secret',
        'widget_style',
        'widget_theme',
        'pay_type',
        'enable_product_widget',
        'enable_checkout_widget',
        'admin_only_enabled',
        'payflex_debug',
    ];

    public function test_all_required_fields_are_defined(): void
    {
        $fields = $this->gateway()->form_fields();

        foreach (self::REQUIRED_FIELDS as $key) {
            $this->assertArrayHasKey($key, $fields);
        }
    }

    public function test_every_field_declares_a_type(): void
    {
        foreach ($this->gateway()->form_fields() as $key => $field) {
            $this->assertArrayHasKey('type', $field, "'$key' has no type");
        }
    }

    /**
     * Each custom type must have a matching generate_{type}_html() renderer, or
     * WooCommerce falls back to rendering nothing for that row.
     */
    public function test_every_custom_field_type_has_a_renderer(): void
    {
        $gateway = $this->gateway();
        $builtin = ['text', 'password', 'select', 'checkbox', 'textarea', 'title', 'multiselect'];

        foreach ($gateway->form_fields() as $key => $field) {
            if (in_array($field['type'], $builtin, true)) continue;

            $this->assertTrue(
                method_exists($gateway, 'generate_' . $field['type'] . '_html'),
                "Field '$key' uses type '{$field['type']}' with no generate_{$field['type']}_html()"
            );
        }
    }

    public function test_every_section_start_is_matched_by_a_section_end(): void
    {
        $types = array_column($this->gateway()->form_fields(), 'type');

        $this->assertSame(
            count(array_keys($types, 'section_start', true)),
            count(array_keys($types, 'section_end', true)),
            'Unbalanced section_start/section_end will produce broken markup'
        );
    }

    public function test_environment_options_come_from_the_config_file(): void
    {
        $fields = $this->gateway()->form_fields();

        $this->assertSame(
            ['develop' => 'Sandbox', 'production' => 'Production'],
            $fields['testmode']['options']
        );
    }

    public function test_the_client_secret_is_rendered_as_a_masked_field(): void
    {
        $this->assertSame('password_toggle', $this->gateway()->form_fields()['client_secret']['type']);
    }

    public function test_field_defaults_match_the_documented_behaviour(): void
    {
        $fields = $this->gateway()->form_fields();

        $this->assertSame('yes', $fields['enabled']['default']);
        $this->assertSame('yes', $fields['enable_product_widget']['default']);
        $this->assertSame('yes', $fields['enable_checkout_widget']['default']);
        $this->assertSame('no', $fields['admin_only_enabled']['default'], 'Admin-only must be opt-in');
        $this->assertSame('no', $fields['payflex_debug']['default'], 'Debug output must be opt-in');
        $this->assertSame('4', $fields['pay_type']['default']);
    }

    /* --------------------------------------------------------------------- */

    public function test_form_field_check_reports_nothing_when_every_field_is_saved(): void
    {
        $gateway = $this->gateway();

        // Save the settings screen exactly as WooCommerce would.
        $settings = [];
        foreach ($gateway->form_fields() as $key => $field) {
            $settings[$key] = $field['default'] ?? '';
        }
        update_option('woocommerce_payflex_settings', $settings);

        $this->assertSame([], $gateway->form_field_check());
    }

    public function test_form_field_check_lists_fields_that_were_never_saved(): void
    {
        $gateway = $this->gateway(['payflex_debug' => 'no']);
        $missing = $gateway->form_field_check();

        $this->assertContains('section_general_start', $missing);
        $this->assertNotContains('client_id', $missing);
    }

    public function test_form_field_check_reports_everything_on_a_fresh_install(): void
    {
        $gateway = $this->gateway([], true);

        $this->set_settings([], true);

        $this->assertCount(count($gateway->form_fields()), $gateway->form_field_check());
    }

    /* --------------------------------------------------------------------- */

    public function test_section_markup_opens_and_closes_a_card(): void
    {
        $gateway = $this->gateway();

        $start = $gateway->generate_section_start_html('section_general_start', [
            'title' => 'General',
            'icon'  => 'admin-settings',
        ]);

        $this->assertStringContainsString('<div class="pf-section', $start);
        $this->assertStringContainsString('dashicons-admin-settings', $start);
        $this->assertStringContainsString('<h4>General</h4>', $start);
        $this->assertStringContainsString('<table class="form-table pf-section-table"><tbody>', $start);

        $this->assertSame('</tbody></table></div>', $gateway->generate_section_end_html('x', []));
    }

    public function test_section_titles_and_classes_are_escaped(): void
    {
        $gateway = $this->gateway();

        $html = $gateway->generate_section_start_html('s', [
            'title' => '<script>alert(1)</script>',
            'icon'  => 'evil" onload="x',
            'class' => 'bad" onload="x',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('onload=', $html);
    }

    public function test_widget_preview_row_is_display_only(): void
    {
        $html = $this->gateway()->generate_widget_preview_html('widget_preview', ['title' => 'Preview']);

        $this->assertStringContainsString('pfwidgetpreview', $html);
        $this->assertStringNotContainsString('<input', $html, 'The preview row must not submit a value');
    }

    public function test_password_toggle_renders_a_masked_input_with_the_saved_value(): void
    {
        $gateway = $this->gateway(['client_secret' => 's3cr3t']);

        $html = $gateway->generate_password_toggle_html('client_secret', ['title' => 'Client Secret']);

        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('name="woocommerce_payflex_client_secret"', $html);
        $this->assertStringContainsString('value="s3cr3t"', $html);
        $this->assertStringContainsString('pf-toggle-secret', $html);
    }

    public function test_password_toggle_escapes_the_saved_value(): void
    {
        $gateway = $this->gateway(['client_secret' => '"><script>alert(1)</script>']);

        $html = $gateway->generate_password_toggle_html('client_secret', ['title' => 'Client Secret']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;&gt;', $html);
    }

    public function test_password_toggle_validator_trims_whitespace(): void
    {
        $this->assertSame('abc', $this->gateway()->validate_password_toggle_field('client_secret', '  abc  '));
    }

    /* --------------------------------------------------------------------- */

    /**
     * Credentials pasted from an email routinely carry a leading or trailing
     * space, which used to produce an authentication failure that looked like
     * bad credentials.
     */
    public function test_saving_trims_whitespace_around_the_credentials(): void
    {
        $gateway = $this->gateway();

        $_POST = [
            'woocommerce_payflex_enabled'       => '1',
            'woocommerce_payflex_title'         => 'Payflex',
            'woocommerce_payflex_testmode'      => 'production',
            'woocommerce_payflex_client_id'     => "  spaced-client-id \n",
            'woocommerce_payflex_client_secret' => "\t spaced-secret  ",
        ];

        $gateway->process_admin_options();
        $saved = get_option('woocommerce_payflex_settings');

        $this->assertSame('spaced-client-id', $saved['client_id']);
        $this->assertSame('spaced-secret', $saved['client_secret']);
    }

    public function test_saving_leaves_the_posted_data_trimmed_for_later_handlers(): void
    {
        $gateway = $this->gateway();
        $_POST = ['woocommerce_payflex_client_id' => '  trim-me  '];

        $gateway->process_admin_options();

        $this->assertSame('trim-me', $_POST['woocommerce_payflex_client_id']);
    }

    public function test_saving_settings_resets_the_cached_access_token(): void
    {
        $gateway = $this->gateway();
        $this->assertSame('cached-access-token', get_transient('payflex_access_token'));

        PF_State::queue_auth_token('token-after-save');
        $gateway->on_save_settings();

        $this->assertSame('token-after-save', get_transient('payflex_access_token'));
    }

    public function test_saving_settings_records_when_it_happened(): void
    {
        $gateway = $this->gateway();
        PF_State::queue_auth_token();

        $gateway->on_save_settings();

        $this->assertGreaterThanOrEqual(PF_State::$now, (int) get_option('payflex_settings_last_saved'));
    }

    public function test_saving_settings_logs_a_successful_authentication(): void
    {
        $gateway = $this->gateway(['testmode' => 'production']);
        PF_State::queue_auth_token();

        $gateway->on_save_settings();

        $this->assertLogged('Environment (production) Authentication state: Success!');
    }

    public function test_saving_settings_logs_a_failed_authentication_as_an_error(): void
    {
        $gateway = $this->gateway(['testmode' => 'develop']);
        PF_State::queue_json(401, ['error' => 'access_denied'], '/auth/merchant');
        PF_State::queue_json(401, ['error' => 'access_denied'], '/auth/merchant');

        $gateway->on_save_settings();

        $this->assertLogged('Environment (develop) Authentication state: Failed');
        $this->assertContains('error', array_column(PF_State::$logs, 'level'));
    }

    /* --------------------------------------------------------------------- */

    public function test_admin_screen_renders_the_settings_grid_and_a_support_link(): void
    {
        $gateway = $this->gateway();

        ob_start();
        $gateway->admin_options();
        $output = ob_get_clean();

        $this->assertStringContainsString('Payflex Gateway', $output);
        $this->assertStringContainsString('class="pf-settings-wrap"', $output);
        $this->assertStringContainsString('page=payflex-support', $output);
    }

    public function test_admin_footer_script_is_registered_for_the_settings_page(): void
    {
        $gateway = $this->gateway();

        $this->assertNotFalse(has_action('admin_footer', [$gateway, 'add_script_to_settings_page']));
    }

    public function test_settings_page_assets_include_the_preview_and_toggle_helpers(): void
    {
        $gateway = $this->gateway();

        ob_start();
        $gateway->add_script_to_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('function pfToggleSecret', $output);
        $this->assertStringContainsString('function pfUpdateWidgetPreview', $output);
        $this->assertStringContainsString('.pf-section', $output);
    }

    /**
     * The inline script must not terminate its own <script> element.
     */
    public function test_settings_page_script_escapes_its_nested_script_tag(): void
    {
        $gateway = $this->gateway();

        ob_start();
        $gateway->add_script_to_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('<\\/script>', $output);
    }

    public function test_checkout_payment_fields_render_the_four_instalment_breakdown(): void
    {
        $gateway = $this->gateway(['enable_checkout_widget' => 'yes']);
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 400.0;

        ob_start();
        $gateway->payment_fields();
        $output = ob_get_clean();

        $this->assertStringContainsString('Four interest-free payments totalling R400', $output);
        $this->assertStringContainsString('R100', $output, 'Each instalment is a quarter of the total');
        $this->assertStringContainsString('PIE-CHART-01.png', $output);
        $this->assertStringContainsString('PIE-CHART-04.png', $output);
    }

    public function test_checkout_payment_fields_are_suppressed_when_the_widget_is_off(): void
    {
        $gateway = $this->gateway(['enable_checkout_widget' => 'no']);
        PF_State::$cart_total = 400.0;

        ob_start();
        $gateway->payment_fields();

        $this->assertSame('', ob_get_clean());
    }

    public function test_the_gateway_title_comes_from_the_settings(): void
    {
        $this->assertSame('Pay with Payflex', $this->gateway(['title' => 'Pay with Payflex'])->title);
    }

    public function test_the_gateway_description_names_both_payment_plans(): void
    {
        $description = $this->gateway()->description;

        $this->assertStringContainsString('4 interest-free payments', $description);
        $this->assertStringContainsString('3 interest-free payments', $description);
    }
}
