<?php

/**
 * get_payflex_option() — the single accessor every other feature reads through.
 */
final class OptionsTest extends PF_TestCase
{
    public function test_returns_empty_array_when_no_settings_saved(): void
    {
        $this->assertSame([], get_payflex_option());
    }

    public function test_returns_false_for_a_key_that_is_not_saved(): void
    {
        $this->set_settings();
        $this->assertFalse(get_payflex_option('a_key_that_does_not_exist'));
    }

    public function test_returns_the_value_for_a_saved_key(): void
    {
        $this->set_settings(['client_id' => 'abc-123']);
        $this->assertSame('abc-123', get_payflex_option('client_id'));
    }

    public function test_returns_the_whole_settings_array_with_no_argument(): void
    {
        $settings = $this->set_settings();
        $this->assertSame($settings, get_payflex_option());
    }

    /**
     * A corrupted option (WordPress can hand back a serialised string) must not
     * be returned as-is — downstream code indexes into it as an array.
     */
    public function test_non_array_option_is_coerced_to_an_empty_array(): void
    {
        update_option('woocommerce_payflex_settings', 'corrupted-not-an-array');

        $this->assertSame([], get_payflex_option());
        $this->assertFalse(get_payflex_option('enabled'));
    }

    public function test_falsy_saved_values_are_returned_verbatim(): void
    {
        $this->set_settings(['widget_theme' => '']);
        $this->assertSame('', get_payflex_option('widget_theme'));
    }

    /**
     * get_payflex_option(false) is the documented "give me everything" call, so
     * a literal false argument must not be treated as a key lookup.
     */
    public function test_explicit_false_argument_returns_all_settings(): void
    {
        $settings = $this->set_settings();
        $this->assertSame($settings, get_payflex_option(false));
    }
}
