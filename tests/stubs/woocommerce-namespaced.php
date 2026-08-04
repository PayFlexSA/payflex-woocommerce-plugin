<?php
/**
 * Namespaced WooCommerce stubs.
 *
 * Kept separate from woocommerce.php because a file may not mix unbraced
 * namespace declarations with global-scope code.
 */

namespace Automattic\WooCommerce\Utilities {

    class FeaturesUtil
    {
        /** @var list<array{feature:string,file:string,positive:bool}> */
        public static array $declarations = [];

        public static function declare_compatibility($feature, $plugin_file, $positive_compatibility = true)
        {
            self::$declarations[] = [
                'feature'  => $feature,
                'file'     => $plugin_file,
                'positive' => $positive_compatibility,
            ];
            return true;
        }

        /** True when compatibility with $feature was declared. */
        public static function pf_declared($feature): bool
        {
            foreach (self::$declarations as $declaration) {
                if ($declaration['feature'] === $feature && $declaration['positive']) return true;
            }
            return false;
        }
    }
}

namespace Automattic\WooCommerce\Internal\Admin\Logging {

    class Settings
    {
        /** Toggled by tests to simulate WooCommerce logging being on or off. */
        public static bool $logging_enabled = true;

        public function logging_is_enabled()
        {
            return self::$logging_enabled;
        }
    }
}

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {

    abstract class AbstractPaymentMethodType
    {
        protected $name = '';
        protected $settings = [];

        public function get_name()
        {
            return $this->name;
        }

        public function get_setting($name, $default = '')
        {
            return $this->settings[$name] ?? $default;
        }

        abstract public function initialize();

        abstract public function is_active();
    }
}

namespace Automattic\WooCommerce\Blocks\Payments {

    class PaymentMethodRegistry
    {
        /** @var list<object> */
        public array $registered = [];

        public function register($payment_method_type)
        {
            $this->registered[] = $payment_method_type;
        }
    }
}
