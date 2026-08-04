<?php

/**
 * The payflex_*_enabled() gate functions decide whether the gateway and the
 * widgets appear at all, so each combination is pinned down here.
 */
final class GatingTest extends PF_TestCase
{
    public function test_disabled_when_no_settings_exist(): void
    {
        $this->assertFalse(payflex_enabled());
    }

    public function test_enabled_when_enabled_is_yes(): void
    {
        $this->set_settings(['enabled' => 'yes']);
        $this->assertTrue(payflex_enabled());
    }

    public function test_disabled_when_enabled_is_no(): void
    {
        $this->set_settings(['enabled' => 'no']);
        $this->assertFalse(payflex_enabled());
    }

    /**
     * 'enabled' is a checkbox field, so only the exact string 'yes' counts.
     */
    public function test_truthy_but_non_yes_values_do_not_enable_the_gateway(): void
    {
        foreach (['1', 'true', 'YES', 'on'] as $value) {
            $this->set_settings(['enabled' => $value]);
            $this->assertFalse(payflex_enabled(), "'$value' should not enable the gateway");
        }
    }

    public function test_admin_only_mode_hides_gateway_from_non_admins(): void
    {
        $this->set_settings(['enabled' => 'yes', 'admin_only_enabled' => 'yes']);
        PF_State::$user_can = false;

        $this->assertTrue(payflex_admin_only_enabled());
        $this->assertFalse(payflex_enabled());
    }

    public function test_admin_only_mode_shows_gateway_to_admins(): void
    {
        $this->set_settings(['enabled' => 'yes', 'admin_only_enabled' => 'yes']);
        PF_State::$user_can = true;

        $this->assertTrue(payflex_enabled());
    }

    /**
     * Admin-only mode is not a substitute for the main switch: with the gateway
     * disabled, even an admin must not see it.
     */
    public function test_admin_only_mode_does_not_override_the_disabled_switch(): void
    {
        $this->set_settings(['enabled' => 'no', 'admin_only_enabled' => 'yes']);
        PF_State::$user_can = true;

        $this->assertFalse(payflex_enabled());
    }

    public function test_admin_only_defaults_to_off(): void
    {
        $this->set_settings();
        $this->assertFalse(payflex_admin_only_enabled());
    }

    /* --------------------------------------------------------------------- */

    public function test_product_widget_requires_both_switches(): void
    {
        $this->set_settings(['enabled' => 'yes', 'enable_product_widget' => 'yes']);
        $this->assertTrue(payflex_product_widget_enabled());

        $this->set_settings(['enabled' => 'yes', 'enable_product_widget' => 'no']);
        $this->assertFalse(payflex_product_widget_enabled());

        $this->set_settings(['enabled' => 'no', 'enable_product_widget' => 'yes']);
        $this->assertFalse(payflex_product_widget_enabled());
    }

    /* --------------------------------------------------------------------- */

    public function test_checkout_widget_enabled_when_there_is_no_cart(): void
    {
        $this->set_settings(['enable_checkout_widget' => 'yes']);
        PF_State::$cart_total = null;

        $this->assertTrue(payflex_checkout_widget_enabled());
    }

    public function test_checkout_widget_shown_when_cart_total_is_within_limits(): void
    {
        $this->gateway(['enable_checkout_widget' => 'yes']);
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 750.0;

        $this->assertTrue(payflex_checkout_widget_enabled());
    }

    public function test_checkout_widget_hidden_when_cart_total_is_below_the_minimum(): void
    {
        $this->gateway(['enable_checkout_widget' => 'yes']);
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 10.0;

        $this->assertFalse(payflex_checkout_widget_enabled());
    }

    public function test_checkout_widget_hidden_when_cart_total_is_above_the_maximum(): void
    {
        $this->gateway(['enable_checkout_widget' => 'yes']);
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 25000.0;

        $this->assertFalse(payflex_checkout_widget_enabled());
    }

    public function test_checkout_widget_hidden_when_its_own_switch_is_off(): void
    {
        $this->gateway(['enable_checkout_widget' => 'no']);
        $this->withLimits(50.0, 20000.0);
        PF_State::$cart_total = 750.0;

        $this->assertFalse(payflex_checkout_widget_enabled());
    }

    /* --------------------------------------------------------------------- */

    public function test_environment_reports_production_and_develop(): void
    {
        $this->set_settings(['testmode' => 'production']);
        $this->assertSame('production', payflex_environment());

        $this->set_settings(['testmode' => 'develop']);
        $this->assertSame('develop', payflex_environment());
    }

    public function test_environment_is_unknown_when_unset_or_unrecognised(): void
    {
        $this->set_settings([], true);
        $this->assertSame('unknown', payflex_environment());

        $this->set_settings(['testmode' => 'staging']);
        $this->assertSame('unknown', payflex_environment());
    }

    public function test_environment_matching_is_case_insensitive(): void
    {
        $this->set_settings(['testmode' => 'Production']);
        $this->assertSame('production', payflex_environment());
    }
}
