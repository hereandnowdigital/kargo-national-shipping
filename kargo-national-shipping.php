<?php
    /**
     * Plugin Name: Kargo National Shipping
     * Plugin URI:
     * Description: A custom shipping method for WooCommerce that integrates with Kargo National shipping services.
     * Version: 0.1.0
     * Author: Dezel
     * Author URI:
     * Text Domain: kargo-national-shipping
     * Domain Path: /languages
     * WC requires at least: 3.0.0
     * WC tested up to: 8.0.0
     * Contributor: Elizabeth Meyer <elizabeth@hereandnowdigital.co.za>
     * @package Kargo_National_Shipping
     */

// Exit if accessed directly
    if (!defined('ABSPATH'))
        exit;


// Define plugin constants
    define('KARGO_NS_VERSION', '0.1.0');
    define('KARGO_NS_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('KARGO_NS_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('KARGO_NS_PLUGIN_BASENAME', plugin_basename(__FILE__));

    /**
     * Check if WooCommerce is active
     */
    if (!function_exists('kargo_ns_is_woocommerce_active')) {
        function kargo_ns_is_woocommerce_active() {
            return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
        }
    }

    /**
     * Initialize the plugin
     */
    function kargo_ns_init() {
        // Load plugin textdomain
        load_plugin_textdomain('kargo-national-shipping', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Include required files
        require_once KARGO_NS_PLUGIN_DIR . 'includes/class-kargo-national-shipping.php';
        require_once KARGO_NS_PLUGIN_DIR . 'includes/class-kargo-shipping-method.php';

        // Hook into WooCommerce
        add_action('woocommerce_shipping_init', 'kargo_ns_shipping_init');
        add_filter('woocommerce_shipping_methods', 'kargo_ns_add_shipping_method');

        // Initialize admin
        if (is_admin()) {
            require_once KARGO_NS_PLUGIN_DIR . 'includes/admin/class-kargo-admin.php';
            new Kargo_NS_Admin();
        }

        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, 'kargo_ns_activate');
        register_deactivation_hook(__FILE__, 'kargo_ns_deactivate');
    }

    /**
     * Initialize the shipping method class
     */
    function kargo_ns_shipping_init() {
        // Only load if WooCommerce is active
        if (!kargo_ns_is_woocommerce_active()) {
            return;
        }
    }

    /**
     * Add the shipping method to WooCommerce
     */
    function kargo_ns_add_shipping_method($methods) {
        $methods['kargo_national_shipping'] = 'Kargo_NS_Shipping_Method';
        return $methods;
    }

    /**
     * Activation hook
     */
    function kargo_ns_activate() {
        // Check if WooCommerce is active
        if (!kargo_ns_is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Kargo National Shipping requires WooCommerce to be installed and activated.', 'kargo-national-shipping'));
        }

        // Create any required tables or options
        update_option('kargo_ns_version', KARGO_NS_VERSION);

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Deactivation hook
     */
    function kargo_ns_deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
    }

    /**
     * Check if WooCommerce is active and plugin can be initialized
     */
    if (kargo_ns_is_woocommerce_active()) {
        // Initialize the plugin
        add_action('plugins_loaded', 'kargo_ns_init');
    } else {
        // Display admin notice if WooCommerce is not active
        add_action('admin_notices', function() {
            ?>
            <div class="error">
                <p><?php _e('Kargo National Shipping requires WooCommerce to be installed and activated.', 'kargo-national-shipping'); ?></p>
            </div>
            <?php
        });
    }