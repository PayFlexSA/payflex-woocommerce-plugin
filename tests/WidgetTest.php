<?php

/**
 * woo_payflex_frontend_widget() builds the calculator widget markup that the
 * product page, checkout, shortcode and Gutenberg block all render.
 */
final class WidgetTest extends PF_TestCase
{
    private function withProduct(float $price = 500.00, string $type = 'simple'): WC_Product
    {
        $product = new WC_Product(101, 'SKU-101', $price, $type);
        $GLOBALS['product'] = $product;
        return $product;
    }

    public function test_returns_nothing_without_a_global_product(): void
    {
        $this->set_settings();
        $GLOBALS['product'] = null;

        $this->assertNull(woo_payflex_frontend_widget());
    }

    /**
     * A product Payflex cannot be used for gets no widget at all, rather than a
     * widget that reports its own unavailability to the hosted script.
     */
    public function test_an_ineligible_product_gets_no_widget(): void
    {
        $this->set_settings();
        $this->withProduct(500.00, 'subscription');

        $this->assertEmpty(woo_payflex_frontend_widget());
    }

    public function test_an_eligible_product_gets_the_widget_with_no_eligibility_flag(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringContainsString('class="payflexCalculatorWidgetContainer"', $html);
        $this->assertStringNotContainsString('eligible=', $html);
        $this->assertStringNotContainsString('data-eligible', $html);
    }

    public function test_a_merchant_excluded_product_gets_no_widget(): void
    {
        $this->set_settings();
        $product = $this->withProduct(500.00);
        $product->meta[Payflex_Eligibility::PRODUCT_META] = 'yes';

        $this->assertEmpty(woo_payflex_frontend_widget());
    }

    public function test_renders_the_widget_container_and_script(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringContainsString('class="payflexCalculatorWidgetContainer"', $html);
        $this->assertStringContainsString('https://widgets.payflex.co.za/2.0.3/payflex-widget.min.js', $html);
        $this->assertStringContainsString('type=calculator', $html);
        $this->assertStringContainsString('&amount=500', $html);
    }

    public function test_uses_the_product_price_including_tax_by_default(): void
    {
        $this->set_settings();
        $this->withProduct(1234.50);

        $this->assertStringContainsString('&amount=1234.5', woo_payflex_frontend_widget());
    }

    public function test_an_explicit_amount_overrides_the_product_price(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget(99.99);

        $this->assertStringContainsString('&amount=99.99', $html);
        $this->assertStringNotContainsString('&amount=500', $html);
    }

    public function test_widget_style_theme_and_pay_type_reach_both_the_data_attributes_and_the_script_url(): void
    {
        $this->set_settings(['widget_style' => 'navy', 'widget_theme' => 'dark', 'pay_type' => '3']);
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringContainsString('data-widget-style="navy"', $html);
        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('data-pay_type="3"', $html);

        $this->assertStringContainsString('&logo_type=navy', $html);
        $this->assertStringContainsString('&theme=dark', $html);
        $this->assertStringContainsString('&pay_type=3', $html);
    }

    public function test_empty_widget_options_are_omitted_rather_than_sent_blank(): void
    {
        $this->set_settings(['widget_style' => '', 'widget_theme' => '', 'pay_type' => '']);
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringNotContainsString('data-theme=', $html);
        $this->assertStringNotContainsString('&theme=', $html);
        $this->assertStringNotContainsString('&logo_type=', $html);
        $this->assertStringNotContainsString('&pay_type=', $html);
    }

    public function test_script_tags_in_widget_settings_are_stripped(): void
    {
        $this->set_settings(['widget_theme' => '<script>alert(1)</script>dark']);
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    /**
     * Widget settings are sanitised with sanitize_text_field() and then escaped
     * with esc_attr() on their way into the container's data attributes, so a
     * value containing a double quote is encoded rather than closing the
     * attribute and terminating the container div early.
     *
     */
    public function test_widget_settings_are_escaped_in_the_container_data_attributes(): void
    {
        $this->set_settings(['widget_theme' => '">broken']);
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringContainsString('data-theme="&quot;&gt;broken"', $html);
        $this->assertStringNotContainsString('data-theme="">broken"', $html);
    }

    /**
     * The same settings are appended to the hosted script's query string, where
     * they are rawurlencode()d, so a double quote cannot close the src attribute
     * or inject further parameters.
     */
    public function test_widget_settings_are_url_encoded_in_the_script_query_string(): void
    {
        $this->set_settings(['widget_theme' => '">broken']);
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringContainsString('&theme=%22%3Ebroken', $html);
        $this->assertStringNotContainsString('&theme=">broken', $html);
    }

    public function test_custom_css_is_emitted_in_a_style_block_with_tags_stripped(): void
    {
        $this->set_settings(['widget_custom_css' => '.payflexCalculatorWidgetContainer { color: red; }']);
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringStartsWith('<style>', $html);
        $this->assertStringContainsString('color: red;', $html);
    }

    public function test_custom_css_cannot_close_the_style_block_and_inject_script(): void
    {
        $this->set_settings(['widget_custom_css' => 'body{}</style><script>alert(1)</script>']);
        $this->withProduct(500.00);

        $html = woo_payflex_frontend_widget();

        $this->assertStringNotContainsString('<script>', substr($html, 0, (int) strpos($html, '</style>')));
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_no_style_block_when_custom_css_is_empty(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);

        $this->assertStringStartsWith('<div', woo_payflex_frontend_widget());
    }

    /**
     * Themes rely on this flag to avoid rendering the widget twice.
     */
    public function test_rendering_marks_the_product_page_widget_as_displayed(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);

        $this->assertFalse($GLOBALS['payflex_product_page_widget_displayed']);

        woo_payflex_frontend_widget();

        $this->assertTrue($GLOBALS['payflex_product_page_widget_displayed']);
    }

    /* --------------------------------------------------------------------- */

    public function test_widget_content_outputs_nothing_when_the_product_widget_is_disabled(): void
    {
        $this->set_settings(['enable_product_widget' => 'no']);
        $this->withProduct(500.00);

        ob_start();
        widget_content();
        $this->assertSame('', ob_get_clean());
    }

    public function test_widget_content_outputs_the_widget_when_enabled(): void
    {
        $this->set_settings(['enable_product_widget' => 'yes']);
        $this->withProduct(500.00);

        ob_start();
        widget_content();
        $output = ob_get_clean();

        $this->assertStringContainsString('payflexCalculatorWidgetContainer', $output);
    }

    /**
     * Nothing is rendered and the flag stays down, so a theme fallback and the
     * variation price script both know there is no widget on the page.
     */
    public function test_widget_content_outputs_nothing_for_an_ineligible_product(): void
    {
        $this->set_settings(['enable_product_widget' => 'yes']);
        $this->withProduct(500.00, 'subscription');

        ob_start();
        widget_content();

        $this->assertSame('', ob_get_clean());
        $this->assertFalse($GLOBALS['payflex_product_page_widget_displayed']);
    }

    /**
     * The point of widget only mode: the widget still renders on product pages
     * for a store that has turned the gateway off.
     */
    public function test_widget_content_outputs_the_widget_in_widget_only_mode(): void
    {
        $this->set_settings(['enabled' => 'no', 'widget_only_mode' => 'yes', 'enable_product_widget' => 'yes']);
        $this->withProduct(500.00);

        ob_start();
        widget_content();
        $output = ob_get_clean();

        $this->assertStringContainsString('payflexCalculatorWidgetContainer', $output);
    }

    public function test_shortcode_returns_the_widget_markup(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);

        $this->assertStringContainsString('payflexCalculatorWidgetContainer', widget_shortcode_content());
    }

    /* --------------------------------------------------------------------- */

    public function test_gutenberg_block_renders_a_placeholder_image_in_the_editor(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);
        PF_State::$is_admin = true;

        $html = render_payflex_widget_block([]);

        $this->assertStringContainsString('assets/widget-icon.png', $html);
        $this->assertStringNotContainsString('payflexCalculatorWidgetContainer', $html);
    }

    public function test_gutenberg_block_renders_the_real_widget_on_the_front_end(): void
    {
        $this->set_settings();
        $this->withProduct(500.00);
        PF_State::$is_admin = false;

        $this->assertStringContainsString('payflexCalculatorWidgetContainer', render_payflex_widget_block([]));
    }

    /* --------------------------------------------------------------------- */

    /**
     * The variation-price script drives PayflexWidget.update() when a shopper
     * picks a variation; without it a variable product shows a stale amount.
     */
    public function test_variation_price_script_is_output_when_the_widget_is_enabled(): void
    {
        $this->gateway(['enable_product_widget' => 'yes']);
        $this->withProduct(500.00);

        ob_start();
        payflex_update_price_on_variation();
        $output = ob_get_clean();

        $this->assertStringContainsString('found_variation', $output);
        $this->assertStringContainsString('PayflexWidget.update', $output);
        $this->assertStringContainsString('var debug_mode = false;', $output);
    }

    public function test_variation_price_script_reflects_debug_mode(): void
    {
        $this->gateway(['enable_product_widget' => 'yes', 'payflex_debug' => 'yes']);
        $this->withProduct(500.00);

        ob_start();
        payflex_update_price_on_variation();
        $output = ob_get_clean();

        $this->assertStringContainsString('var debug_mode = true;', $output);
    }

    /**
     * There is no widget on the page for an ineligible product, so the script
     * has nothing to update.
     */
    public function test_variation_price_script_is_suppressed_for_an_ineligible_product(): void
    {
        $this->gateway(['enable_product_widget' => 'yes']);
        $this->withProduct(500.00, 'subscription');

        ob_start();
        payflex_update_price_on_variation();
        $this->assertSame('', ob_get_clean());
    }

    public function test_variation_price_script_is_suppressed_when_the_widget_is_disabled(): void
    {
        $this->gateway(['enabled' => 'no', 'enable_product_widget' => 'no']);
        $this->withProduct(500.00);
        $GLOBALS['payflex_product_page_widget_displayed'] = false;

        ob_start();
        payflex_update_price_on_variation();
        $this->assertSame('', ob_get_clean());
    }
}
