<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Decides whether products, carts and orders may be paid for with Payflex.
 */
final class Payflex_Eligibility
{
    const PRODUCT_META = '_payflex_ineligible';

    const REASON_PRODUCT_TYPE   = 'product_type';
    const REASON_PRODUCT_OPTOUT = 'product_optout';
    const REASON_CATEGORY       = 'category';
    const REASON_BELOW_MIN      = 'below_minimum';
    const REASON_ABOVE_MAX      = 'above_maximum';

    /** Account limits for this request, read at most once. */
    private static $limits = null;

    /** Gateway the limits are read from, when one is already in hand. */
    private static $gateway = null;

    /**
     * Use this gateway to read the account limits.
     *
     * check_cart_within_limits() runs on woocommerce_available_payment_gateways,
     * and resolving the singleton from in there would construct a second gateway
     * whose constructor adds that same callback to the hook currently being
     * iterated, running the whole evaluation again.
     */
    public static function use_gateway($gateway)
    {
        if($gateway instanceof WC_Gateway_PartPay) self::$gateway = $gateway;
    }

    /**
     * Drops the per request memo. The suite runs many requests through one
     * process, so it has to start each test with the limits unread.
     */
    public static function reset_cache()
    {
        self::$limits  = null;
        self::$gateway = null;
    }

    /**
     * The account amount limits, resolved once per request.
     */
    private static function limits()
    {
        if(self::$limits !== null) return self::$limits;

        $gateway = self::$gateway ? self::$gateway : WC_Gateway_PartPay::instance();

        return self::$limits = $gateway->get_payflex_limits();
    }

    /**
     * Product types Payflex cannot be used for.
     */
    public static function ineligible_product_types()
    {
        return apply_filters('payflex_ineligible_product_types', [
            'subscription',
            'subscription_variation',
            'variable-subscription',
        ]);
    }

    /**
     * Reason a product cannot use Payflex, or null when it can.
     */
    public static function product_reason($product)
    {
        if(!$product instanceof WC_Product) return null;

        if(self::excluded_by_type($product))         $reason = self::REASON_PRODUCT_TYPE;
        elseif(self::excluded_by_optout($product))   $reason = self::REASON_PRODUCT_OPTOUT;
        elseif(self::excluded_by_category($product)) $reason = self::REASON_CATEGORY;
        else                                         $reason = null;

        return apply_filters('payflex_product_reason', $reason, $product);
    }

    /**
     * Whether a single product may be paid for with Payflex.
     */
    public static function is_product_eligible($product)
    {
        return self::product_reason($product) === null;
    }

    /**
     * Eligibility of a cart, including the account amount limits.
     */
    public static function evaluate_cart($cart = null)
    {
        if(!$cart AND function_exists('WC')) $cart = WC()->cart;

        $items = $cart ? self::ineligible_cart_items($cart) : [];
        $total = $cart ? (float)$cart->get_total('edit') : 0;

        return self::build_result($items, $total);
    }

    /**
     * Product eligibility of a placed order, for when the cart is no longer authoritative.
     *
     * The amount limits are deliberately not applied here. WooCommerce re-runs
     * get_available_payment_gateways() on the checkout submission, so the cart
     * total has already been measured against them, and an order placed within
     * the limits should stay payable if the merchant later changes them.
     */
    public static function evaluate_order($order)
    {
        $items = $order instanceof WC_Order ? self::ineligible_order_items($order) : [];

        return self::build_result($items, 0, true);
    }

    /**
     * Whether the product is of a type Payflex cannot be used for.
     */
    private static function excluded_by_type($product)
    {
        if(get_payflex_option('exclude_subscriptions') === 'no') return false;

        return in_array($product->get_type(), self::ineligible_product_types(), true);
    }

    /**
     * Whether the merchant has excluded this product, or the variation's parent.
     */
    private static function excluded_by_optout($product)
    {
        if(get_payflex_option('enable_product_exclusions') === 'no') return false;

        if($product->get_meta(self::PRODUCT_META) === 'yes') return true;

        $parent_id = $product->get_parent_id();

        if(!$parent_id) return false;

        $parent = wc_get_product($parent_id);

        return $parent AND $parent->get_meta(self::PRODUCT_META) === 'yes';
    }

    /**
     * Whether the product sits in one of the excluded categories.
     */
    private static function excluded_by_category($product)
    {
        $excluded = get_payflex_option('excluded_product_cats');

        if(empty($excluded) OR !is_array($excluded)) return false;

        $product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
        $terms      = wc_get_product_term_ids($product_id, 'product_cat');

        if(empty($terms) OR !is_array($terms)) return false;

        return count(array_intersect(array_map('intval', $excluded), self::category_ids_with_ancestors($terms))) > 0;
    }

    /**
     * The product's categories, plus every ancestor of them.
     *
     * Excluding a parent category has to exclude what is filed under it. That is
     * what the settings count already reports, because its tax_query leaves
     * include_children at the WP_Tax_Query default of true, while
     * wc_get_product_term_ids() returns only directly assigned terms.
     */
    private static function category_ids_with_ancestors($terms)
    {
        $ids = [];

        foreach($terms as $term_id)
        {
            $term_id = (int)$term_id;
            $ids[]   = $term_id;

            $ancestors = get_ancestors($term_id, 'product_cat', 'taxonomy');

            if(!is_array($ancestors)) continue;

            foreach($ancestors as $ancestor) $ids[] = (int)$ancestor;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The cart lines Payflex cannot be used for.
     */
    private static function ineligible_cart_items($cart)
    {
        $items = [];

        foreach($cart->get_cart() as $cart_item_key => $cart_item)
        {
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            $reason  = self::product_reason($product);

            if($reason) $items[] = self::item($cart_item_key, $product, $reason);
        }

        return $items;
    }

    /**
     * The order lines Payflex cannot be used for.
     */
    private static function ineligible_order_items($order)
    {
        $items = [];

        foreach($order->get_items() as $item_id => $item)
        {
            $product = is_callable([$item, 'get_product']) ? $item->get_product() : null;
            $reason  = self::product_reason($product);

            if($reason) $items[] = self::item($item_id, $product, $reason);
        }

        return $items;
    }

    /**
     * One offending line, as reported in the result.
     */
    private static function item($key, $product, $reason)
    {
        return [
            'key'        => $key,
            'product_id' => $product->get_id(),
            'name'       => $product->get_name(),
            'reason'     => $reason,
        ];
    }

    /**
     * The eligibility result for a set of offending lines and an amount.
     */
    private static function build_result($items, $total, $skip_limits = false)
    {
        $reasons      = array_values(array_unique(array_column($items, 'reason')));
        $limit_reason = $skip_limits ? null : self::limit_reason($total);

        if($limit_reason) $reasons[] = $limit_reason;

        $result = [
            'eligible' => empty($reasons),
            'reasons'  => $reasons,
            'items'    => $items,
            'message'  => self::message($reasons, $items),
        ];

        return apply_filters('payflex_eligibility_result', $result, $items, $total);
    }

    /**
     * An amount measured against the Payflex account limits.
     */
    private static function limit_reason($total)
    {
        $limits = self::limits();

        if(!isset($limits['minimum']) OR !isset($limits['maximum'])) return null;
        if($limits['minimum'] === false OR $limits['maximum'] === false) return null;

        if($total < $limits['minimum']) return self::REASON_BELOW_MIN;
        if($total > $limits['maximum']) return self::REASON_ABOVE_MAX;

        return null;
    }

    /**
     * Shopper facing explanation for a blocked cart or order.
     */
    private static function message($reasons, $items)
    {
        if(empty($reasons)) return '';

        if(!empty($items))
        {
            return sprintf(
                __('Payflex is not available with %s. Remove it to pay with Payflex.', 'woo_payflex'),
                implode(', ', array_column($items, 'name'))
            );
        }

        $limits = self::limits();

        if(in_array(self::REASON_BELOW_MIN, $reasons, true))
        {
            return sprintf(__('Payflex is available on orders from %s.', 'woo_payflex'), self::plain_price($limits['minimum']));
        }

        if(in_array(self::REASON_ABOVE_MAX, $reasons, true))
        {
            return sprintf(__('Payflex is available on orders up to %s.', 'woo_payflex'), self::plain_price($limits['maximum']));
        }

        return __('Payflex is not available for this order.', 'woo_payflex');
    }

    /**
     * Currency formatted amount without the markup wc_price() wraps it in.
     */
    private static function plain_price($amount)
    {
        return trim(html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES));
    }
}
