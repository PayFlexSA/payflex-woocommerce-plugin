<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The Payflex exclusion column and filter on the WooCommerce products list,
 * plus the counts the settings screen reports.
 *
 * Everything here covers the per product checkbox only. Category and
 * subscription exclusions are not shown per product because they cannot be
 * changed from there.
 */
final class Payflex_Admin_Products
{
    /** Column id on the products list, which doubles as the filter's query parameter. */
    const COLUMN = 'payflex_excluded';

    /**
     * Product statuses a merchant thinks of as their catalogue, which is also
     * what the products list shows by default.
     */
    const COUNTED_STATUSES = ['publish', 'private', 'draft', 'pending', 'future'];

    /**
     * Hook the products list additions. Each callback checks the setting itself.
     */
    public static function register()
    {
        add_filter('manage_edit-product_columns', [__CLASS__, 'add_column']);
        add_action('manage_product_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);
        add_action('restrict_manage_posts', [__CLASS__, 'render_filter']);
        add_filter('parse_query', [__CLASS__, 'apply_filter']);
        add_action('admin_head-edit.php', [__CLASS__, 'render_column_style']);
    }

    /**
     * Whether the merchant has per product exclusions switched on.
     */
    public static function enabled()
    {
        return get_payflex_option('enable_product_exclusions') === 'yes';
    }

    /**
     * Adds the Payflex column to the products list.
     */
    public static function add_column($columns)
    {
        if(!self::enabled()) return $columns;

        $columns[self::COLUMN] = __('Payflex Individually Excluded', 'woo_payflex');

        return $columns;
    }

    /**
     * Gives the column a width.
     *
     * The list table uses a fixed layout and every other column already claims
     * a width, so without one here the column collapses to nothing and its
     * header wraps a character per line, pushing the list far down the page.
     */
    public static function render_column_style()
    {
        if(!self::enabled()) return;
        if(!isset($_GET['post_type']) OR $_GET['post_type'] !== 'product') return;

        echo '<style>.wp-list-table .column-' . self::COLUMN . '{width:150px}</style>';
    }

    /**
     * Prints the column value for one product.
     */
    public static function render_column($column, $post_id)
    {
        if($column !== self::COLUMN) return;

        $excluded = get_post_meta($post_id, Payflex_Eligibility::PRODUCT_META, true) === 'yes';

        echo $excluded ? esc_html__('Excluded', 'woo_payflex') : '&mdash;';
    }

    /**
     * Prints the Payflex status dropdown above the products list.
     */
    public static function render_filter($post_type)
    {
        if($post_type !== 'product') return;
        if(!self::enabled()) return;

        $current = self::requested_filter();

        $options = [
            ''    => __('All Payflex statuses', 'woo_payflex'),
            'yes' => __('Excluded from Payflex', 'woo_payflex'),
            'no'  => __('Not excluded from Payflex', 'woo_payflex'),
        ];

        echo '<select name="' . esc_attr(self::COLUMN) . '">';

        foreach($options as $value => $label)
        {
            $selected = $current === $value ? ' selected="selected"' : '';

            echo '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }

        echo '</select>';
    }

    /**
     * Narrows the products list to the selected Payflex status.
     */
    public static function apply_filter($query)
    {
        if(!is_admin()) return $query;

        // parse_query fires for every WP_Query in the admin. Without this, any
        // secondary product query on the list screen inherits the filter too.
        if(is_callable([$query, 'is_main_query']) AND !$query->is_main_query()) return $query;

        if(!self::enabled()) return $query;
        if(!isset($query->query_vars['post_type']) OR $query->query_vars['post_type'] !== 'product') return $query;

        $filter = self::requested_filter();

        if($filter !== 'yes' AND $filter !== 'no') return $query;

        $meta_query = isset($query->query_vars['meta_query']) ? $query->query_vars['meta_query'] : [];

        $meta_query[] = $filter === 'yes' ? self::excluded_meta_clause() : self::not_excluded_meta_clause();

        $query->query_vars['meta_query'] = $meta_query;

        return $query;
    }

    /**
     * How many products the merchant has excluded with the per product checkbox.
     */
    public static function excluded_product_count()
    {
        return self::count_products([
            'meta_key'   => Payflex_Eligibility::PRODUCT_META,
            'meta_value' => 'yes',
        ]);
    }

    /**
     * How many products sit in one of the excluded categories.
     */
    public static function category_excluded_product_count()
    {
        $excluded = get_payflex_option('excluded_product_cats');

        if(empty($excluded) OR !is_array($excluded)) return 0;

        return self::count_products([
            'tax_query' => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_map('intval', $excluded),
            ]],
        ]);
    }

    /**
     * How many products match, without pulling their ids into PHP.
     *
     * A store with tens of thousands of products would otherwise load every
     * matching id just to render a number, so ask for a single row and read the
     * total the query had to count anyway.
     */
    private static function count_products($args)
    {
        $query = new WP_Query(array_merge([
            'post_type'           => 'product',
            'post_status'         => self::COUNTED_STATUSES,
            'posts_per_page'      => 1,
            'fields'              => 'ids',
            'no_found_rows'       => false,
            'ignore_sticky_posts' => true,
        ], $args));

        return (int)$query->found_posts;
    }

    /**
     * The products list filtered to the excluded products.
     */
    public static function excluded_list_url()
    {
        return admin_url('edit.php?post_type=product&' . self::COLUMN . '=yes');
    }

    /**
     * The Payflex status asked for in the request, or an empty string.
     */
    private static function requested_filter()
    {
        if(!isset($_GET[self::COLUMN])) return '';

        return sanitize_text_field(wp_unslash($_GET[self::COLUMN]));
    }

    /**
     * Matches products the merchant has excluded.
     */
    private static function excluded_meta_clause()
    {
        return [
            'key'   => Payflex_Eligibility::PRODUCT_META,
            'value' => 'yes',
        ];
    }

    /**
     * Matches everything else, including products last saved before the
     * checkbox existed, which carry no meta row at all.
     */
    private static function not_excluded_meta_clause()
    {
        return [
            'relation' => 'OR',
            [
                'key'     => Payflex_Eligibility::PRODUCT_META,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => Payflex_Eligibility::PRODUCT_META,
                'value'   => 'yes',
                'compare' => '!=',
            ],
        ];
    }
}
