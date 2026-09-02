<?php

/**
 * The Payflex column and filter on the WooCommerce products list, and the
 * exclusion counts the settings screen reports.
 */
final class AdminProductsTest extends PF_TestCase
{
    /* --------------------------------------------------------------------- */
    /* Column                                                                */
    /* --------------------------------------------------------------------- */

    public function test_the_column_is_added_to_the_products_list(): void
    {
        $this->set_settings();

        $columns = Payflex_Admin_Products::add_column(['title' => 'Product', 'date' => 'Date']);

        $this->assertArrayHasKey('payflex_excluded', $columns);
        $this->assertSame('Payflex Individually Excluded', $columns['payflex_excluded']);
    }

    public function test_the_column_is_left_out_when_per_product_exclusions_are_off(): void
    {
        $this->set_settings(['enable_product_exclusions' => 'no']);

        $columns = Payflex_Admin_Products::add_column(['title' => 'Product']);

        $this->assertSame(['title' => 'Product'], $columns);
    }

    public function test_an_excluded_product_is_marked_in_the_column(): void
    {
        $this->set_settings();
        update_post_meta(101, Payflex_Eligibility::PRODUCT_META, 'yes');

        $this->assertSame('Excluded', $this->column_output(101));
    }

    public function test_a_product_that_is_not_excluded_shows_a_dash(): void
    {
        $this->set_settings();
        update_post_meta(101, Payflex_Eligibility::PRODUCT_META, 'no');

        $this->assertSame('&mdash;', $this->column_output(101));
    }

    /**
     * A product last saved before the checkbox existed has no meta row at all.
     */
    public function test_a_product_with_no_meta_shows_a_dash(): void
    {
        $this->set_settings();

        $this->assertSame('&mdash;', $this->column_output(999));
    }

    public function test_other_columns_are_left_alone(): void
    {
        $this->set_settings();
        update_post_meta(101, Payflex_Eligibility::PRODUCT_META, 'yes');

        ob_start();
        Payflex_Admin_Products::render_column('sku', 101);

        $this->assertSame('', ob_get_clean());
    }

    /**
     * Without a width the fixed list table layout collapses the column and its
     * header wraps a character per line, pushing the product list off screen.
     */
    public function test_the_column_is_given_a_width(): void
    {
        $this->set_settings();
        $_GET['post_type'] = 'product';

        ob_start();
        Payflex_Admin_Products::render_column_style();
        $css = ob_get_clean();

        $this->assertStringContainsString('.column-payflex_excluded', $css);
        $this->assertStringContainsString('width:150px', $css);
    }

    public function test_the_column_style_stays_off_other_post_types(): void
    {
        $this->set_settings();
        $_GET['post_type'] = 'page';

        ob_start();
        Payflex_Admin_Products::render_column_style();

        $this->assertSame('', ob_get_clean());
    }

    /* --------------------------------------------------------------------- */
    /* Dropdown                                                              */
    /* --------------------------------------------------------------------- */

    public function test_the_dropdown_renders_on_the_products_list(): void
    {
        $this->set_settings();

        $html = $this->filter_output('product');

        $this->assertStringContainsString('name="payflex_excluded"', $html);
        $this->assertStringContainsString('All Payflex statuses', $html);
        $this->assertStringContainsString('Excluded from Payflex', $html);
        $this->assertStringContainsString('Not excluded from Payflex', $html);
    }

    public function test_the_dropdown_stays_off_other_post_types(): void
    {
        $this->set_settings();

        $this->assertSame('', $this->filter_output('post'));
    }

    public function test_the_dropdown_stays_off_when_per_product_exclusions_are_off(): void
    {
        $this->set_settings(['enable_product_exclusions' => 'no']);

        $this->assertSame('', $this->filter_output('product'));
    }

    public function test_the_dropdown_remembers_the_current_selection(): void
    {
        $this->set_settings();
        $_GET['payflex_excluded'] = 'yes';

        $html = $this->filter_output('product');

        $this->assertStringContainsString('<option value="yes" selected="selected">', $html);
        $this->assertStringNotContainsString('<option value="no" selected="selected">', $html);
    }

    /* --------------------------------------------------------------------- */
    /* Query                                                                 */
    /* --------------------------------------------------------------------- */

    public function test_excluded_filters_the_query_to_the_opted_out_products(): void
    {
        $this->set_settings();
        PF_State::$is_admin = true;
        $_GET['payflex_excluded'] = 'yes';

        $query = Payflex_Admin_Products::apply_filter($this->query());

        $this->assertSame(
            [['key' => Payflex_Eligibility::PRODUCT_META, 'value' => 'yes']],
            $query->query_vars['meta_query']
        );
    }

    /**
     * Products saved before the checkbox existed carry no meta row, so a plain
     * != would hide them from the "not excluded" view.
     */
    public function test_not_excluded_also_matches_products_with_no_meta_row(): void
    {
        $this->set_settings();
        PF_State::$is_admin = true;
        $_GET['payflex_excluded'] = 'no';

        $query = Payflex_Admin_Products::apply_filter($this->query());
        $clause = $query->query_vars['meta_query'][0];

        $this->assertSame('OR', $clause['relation']);
        $this->assertSame('NOT EXISTS', $clause[0]['compare']);
        $this->assertSame('!=', $clause[1]['compare']);
        $this->assertSame('yes', $clause[1]['value']);
    }

    public function test_an_existing_meta_query_is_kept(): void
    {
        $this->set_settings();
        PF_State::$is_admin = true;
        $_GET['payflex_excluded'] = 'yes';

        $existing = ['key' => '_stock_status', 'value' => 'instock'];
        $query    = Payflex_Admin_Products::apply_filter($this->query(['meta_query' => [$existing]]));

        $this->assertCount(2, $query->query_vars['meta_query']);
        $this->assertSame($existing, $query->query_vars['meta_query'][0]);
    }

    public function test_the_query_is_untouched_without_a_filter_value(): void
    {
        $this->set_settings();
        PF_State::$is_admin = true;

        $query = Payflex_Admin_Products::apply_filter($this->query());

        $this->assertArrayNotHasKey('meta_query', $query->query_vars);
    }

    public function test_the_query_is_untouched_for_other_post_types(): void
    {
        $this->set_settings();
        PF_State::$is_admin = true;
        $_GET['payflex_excluded'] = 'yes';

        $query = Payflex_Admin_Products::apply_filter($this->query(['post_type' => 'post']));

        $this->assertArrayNotHasKey('meta_query', $query->query_vars);
    }

    public function test_the_query_is_untouched_on_the_front_end(): void
    {
        $this->set_settings();
        PF_State::$is_admin = false;
        $_GET['payflex_excluded'] = 'yes';

        $query = Payflex_Admin_Products::apply_filter($this->query());

        $this->assertArrayNotHasKey('meta_query', $query->query_vars);
    }

    public function test_the_query_is_untouched_when_per_product_exclusions_are_off(): void
    {
        $this->set_settings(['enable_product_exclusions' => 'no']);
        PF_State::$is_admin = true;
        $_GET['payflex_excluded'] = 'yes';

        $query = Payflex_Admin_Products::apply_filter($this->query());

        $this->assertArrayNotHasKey('meta_query', $query->query_vars);
    }

    /**
     * parse_query fires for every WP_Query in the admin, not just the list
     * table's. Narrowing the others would hand related-product pickers and
     * report widgets a silently filtered catalogue while the filter is in the URL.
     */
    public function test_a_secondary_query_is_untouched(): void
    {
        $this->set_settings();
        PF_State::$is_admin = true;
        $_GET['payflex_excluded'] = 'yes';

        $query = Payflex_Admin_Products::apply_filter($this->wp_query([], false));

        $this->assertArrayNotHasKey('meta_query', $query->query_vars);
    }

    public function test_the_main_query_is_still_filtered(): void
    {
        $this->set_settings();
        PF_State::$is_admin = true;
        $_GET['payflex_excluded'] = 'yes';

        $query = Payflex_Admin_Products::apply_filter($this->wp_query());

        $this->assertSame(
            [['key' => Payflex_Eligibility::PRODUCT_META, 'value' => 'yes']],
            $query->query_vars['meta_query']
        );
    }

    /* --------------------------------------------------------------------- */
    /* Counts                                                                */
    /* --------------------------------------------------------------------- */

    public function test_the_excluded_product_count_asks_for_the_opted_out_products(): void
    {
        $this->set_settings();
        PF_State::$post_query_result = [11, 22, 33];

        $this->assertSame(3, Payflex_Admin_Products::excluded_product_count());

        $args = PF_State::$post_queries[0];

        $this->assertSame('product', $args['post_type']);
        $this->assertSame('ids', $args['fields']);
        $this->assertSame(Payflex_Eligibility::PRODUCT_META, $args['meta_key']);
        $this->assertSame('yes', $args['meta_value']);
    }

    public function test_the_category_count_asks_for_the_excluded_categories(): void
    {
        $this->set_settings(['excluded_product_cats' => ['12', '15']]);
        PF_State::$post_query_result = [11, 22];

        $this->assertSame(2, Payflex_Admin_Products::category_excluded_product_count());

        $tax_query = PF_State::$post_queries[0]['tax_query'][0];

        $this->assertSame('product_cat', $tax_query['taxonomy']);
        $this->assertSame('term_id', $tax_query['field']);
        $this->assertSame([12, 15], $tax_query['terms']);
    }

    public function test_the_category_count_runs_no_query_without_excluded_categories(): void
    {
        $this->set_settings(['excluded_product_cats' => []]);

        $this->assertSame(0, Payflex_Admin_Products::category_excluded_product_count());
        $this->assertSame([], PF_State::$post_queries);
    }

    /**
     * A large catalogue would otherwise pull every matching id into PHP just to
     * render a number in a badge.
     */
    public function test_the_count_asks_for_a_total_rather_than_every_id(): void
    {
        $this->set_settings();
        PF_State::$post_query_result = [11, 22, 33];

        Payflex_Admin_Products::excluded_product_count();

        $args = PF_State::$post_queries[0];

        $this->assertSame(1, $args['posts_per_page']);
        $this->assertFalse($args['no_found_rows'], 'The total has to be computed to be read');
    }

    /**
     * The products list shows scheduled products in its default view, so leaving
     * them out makes the badge disagree with the list it links to.
     */
    public function test_scheduled_products_are_counted(): void
    {
        $this->set_settings();
        PF_State::$post_query_result = [11];

        Payflex_Admin_Products::excluded_product_count();

        $this->assertContains('future', PF_State::$post_queries[0]['post_status']);
    }

    public function test_the_excluded_list_url_carries_the_filter(): void
    {
        $this->assertStringContainsString(
            'edit.php?post_type=product&payflex_excluded=yes',
            Payflex_Admin_Products::excluded_list_url()
        );
    }

    /* --------------------------------------------------------------------- */

    private function column_output(int $post_id): string
    {
        ob_start();
        Payflex_Admin_Products::render_column('payflex_excluded', $post_id);
        return ob_get_clean();
    }

    private function filter_output(string $post_type): string
    {
        ob_start();
        Payflex_Admin_Products::render_filter($post_type);
        return ob_get_clean();
    }

    /**
     * A stand-in for WP_Query. The filter only reads and writes query_vars.
     */
    private function query(array $vars = []): object
    {
        $query = new stdClass();
        $query->query_vars = array_merge(['post_type' => 'product'], $vars);

        return $query;
    }

    /** A real query object, which unlike the stand-in can answer is_main_query(). */
    private function wp_query(array $vars = [], bool $main = true): WP_Query
    {
        $query = new WP_Query();

        $query->query_vars    = array_merge(['post_type' => 'product'], $vars);
        $query->is_main_query = $main;

        return $query;
    }
}
