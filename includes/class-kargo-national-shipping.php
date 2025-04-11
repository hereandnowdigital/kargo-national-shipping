<?php
    /**
     * Main Plugin Class
     *
     * @package Kargo_National_Shipping
     */

// Exit if accessed directly
    if (!defined('ABSPATH')) {
        exit;
    }

    /**
     * Main plugin class
     */
    class Kargo_National_Shipping {
        /**
         * Singleton instance
         *
         * @var Kargo_National_Shipping
         */
        private static $instance = null;

        /**
         * Constructor
         */
        public function __construct() {
            // Hooks and filters
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('woocommerce_before_cart', array($this, 'check_cart_items_weight_dimensions'));
            add_action('woocommerce_before_checkout_form', array($this, 'check_cart_items_weight_dimensions'));

            // Initialize
            $this->init();
        }

        /**
         * Get instance - singleton pattern
         */
        public static function get_instance() {
            if (self::$instance == null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Initialize the plugin
         */
        public function init() {
            // Any initialization code
        }

        /**
         * Enqueue scripts and styles
         */
        public function enqueue_scripts() {
            // Only load on cart and checkout pages
            if (is_cart() || is_checkout()) {
                wp_enqueue_style(
                    'kargo-national-shipping',
                    KARGO_NS_PLUGIN_URL . 'assets/css/kargo-shipping.css',
                    array(),
                    KARGO_NS_VERSION
                );

                wp_enqueue_script(
                    'kargo-national-shipping',
                    KARGO_NS_PLUGIN_URL . 'assets/js/kargo-shipping.js',
                    array('jquery'),
                    KARGO_NS_VERSION,
                    true
                );

                // Localize script
                wp_localize_script('kargo-national-shipping', 'kargoNSData', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('kargo-shipping-nonce')
                ));
            }
        }

        /**
         * Check if cart items have the required weight and dimensions
         */
        public function check_cart_items_weight_dimensions() {
            if (WC()->cart->is_empty()) {
                return;
            }

            $missing_items = array();
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                if (!$product->has_weight() || !$product->has_dimensions()) {
                    $missing_items[] = $product->get_name();
                }
            }

            if (!empty($missing_items)) {
                $message = sprintf(
                    __('Some products are missing weight or dimensions which are required for Kargo National Shipping: %s', 'kargo-national-shipping'),
                    implode(', ', $missing_items)
                );

                wc_print_notice($message, 'notice');
            }
        }
    }

// Initialize main class
    function kargo_ns_get_plugin() {
        return Kargo_National_Shipping::get_instance();
    }