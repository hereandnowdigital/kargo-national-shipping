<?php
    /**
     * Kargo National Shipping Method Class
     *
     * @package Kargo_National_Shipping
     */

// Exit if accessed directly
    if (!defined('ABSPATH')) {
        exit;
    }

    /**
     * Kargo_NS_Shipping_Method Class
     */
    class Kargo_NS_Shipping_Method extends WC_Shipping_Method {
        /**
         * API Helper instance
         *
         * @var Kargo_NS_API_Helper
         */
        private $api_helper;

        /**
         * Constructor for shipping method class
         */
        public function __construct($instance_id = 0) {
            $this->id                 = 'kargo_national_shipping';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = __('Kargo National Shipping', 'kargo-national-shipping');
            $this->method_description = __('Shipping method that integrates with Kargo National shipping services.', 'kargo-national-shipping');
            $this->supports           = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );

            $this->init();

            // Initialize API helper
            require_once KARGO_NS_PLUGIN_DIR . 'includes/class-kargo-api-helper.php';
            $this->api_helper = new Kargo_NS_API_Helper('', '', '', ('yes' === $this->debug));

            // Save settings in admin
            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Initialize shipping method settings
         */
        public function init() {
            // Load the settings API
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title            = $this->get_option('title', $this->method_title);
            $this->enabled          = $this->get_option('enabled', 'yes');
            $this->origin_postcode  = $this->get_option('origin_postcode', '');
            $this->fallback_rate    = $this->get_option('fallback_rate', '0');
            $this->debug            = $this->get_option('debug', 'no');
        }

        /**
         * Initialize form fields
         */
        public function init_form_fields() {
            $this->instance_form_fields = array(
                'title' => array(
                    'title'       => __('Method Title', 'kargo-national-shipping'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'kargo-national-shipping'),
                    'default'     => __('Kargo National Shipping', 'kargo-national-shipping'),
                    'desc_tip'    => true,
                ),
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'kargo-national-shipping'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable this shipping method', 'kargo-national-shipping'),
                    'default' => 'yes',
                ),
                'origin_postcode' => array(
                    'title'       => __('Origin Postal Code', 'kargo-national-shipping'),
                    'type'        => 'text',
                    'description' => __('Enter the postal code from where you ship your products. If left empty, the store postal code will be used.', 'kargo-national-shipping'),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'fallback_rate' => array(
                    'title'       => __('Fallback Rate', 'kargo-national-shipping'),
                    'type'        => 'price',
                    'description' => __('If the Kargo API is unavailable, this fallback rate will be used. Set to 0 to disable the shipping method when API is unavailable.', 'kargo-national-shipping'),
                    'default'     => '0',
                    'desc_tip'    => true,
                ),
                'debug' => array(
                    'title'       => __('Debug Mode', 'kargo-national-shipping'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable debug mode', 'kargo-national-shipping'),
                    'default'     => 'no',
                    'description' => __('Enable debug mode to log API requests and responses.', 'kargo-national-shipping'),
                ),
            );
        }

        /**
         * Check if shipping method is available
         */
        public function is_available($package) {
            // Get API credentials
            $username = get_option('kargo_ns_username');
            $password = get_option('kargo_ns_password');
            $account_number = get_option('kargo_ns_account_number');

            // If credentials are not set, the method is not available
            if (empty($username) || empty($password) || empty($account_number)) {
                return false;
            }

            return parent::is_available($package);
        }

        /**
         * Calculate shipping cost based on API.
         */
        public function calculate_shipping($package = array()) {
            // Check if all products have weight and dimensions
            $missing_weight_dimensions = $this->check_products_weight_dimensions($package);

            if (!empty($missing_weight_dimensions)) {
                // Don't show this shipping method if products are missing weight/dimensions
                // Optionally, log this issue for admin visibility
                wc_get_logger()->warning(
                    sprintf(
                        'The following products are missing weight or dimensions: %s',
                        implode(', ', $missing_weight_dimensions)
                    ),
                    array('source' => 'kargo-shipping')
                );
                return;
            }

            // Get shipping destination
            $destination_postcode = $package['destination']['postcode'];

            // If no destination postcode, we cannot calculate shipping
            if (empty($destination_postcode)) {
                return;
            }

            // Get origin postcode from settings or store settings
            $origin_postcode = $this->origin_postcode;
            if (empty($origin_postcode)) {
                $origin_postcode = get_option('woocommerce_store_postcode');
            }

            // If no origin postcode, we cannot calculate shipping
            if (empty($origin_postcode)) {
                return;
            }

            // Calculate total weight
            $weight = $this->calculate_shipping_weight($package);

            // Call API to get shipping cost
            $shipping_cost = $this->get_shipping_cost_from_api($origin_postcode, $destination_postcode, $weight);

            // If we couldn't get a valid shipping cost
            if (false === $shipping_cost) {
                // Use fallback rate if set
                if (!empty($this->fallback_rate) && $this->fallback_rate > 0) {
                    $shipping_cost = $this->fallback_rate;
                } else {
                    // Otherwise, don't offer the shipping method
                    return;
                }
            }

            // Register the rate
            $rate = array(
                'id'      => $this->get_rate_id(),
                'label'   => $this->title,
                'cost'    => $shipping_cost,
                'package' => $package,
            );

            $this->add_rate($rate);
        }

        /**
         * Check if all products have weight and dimensions
         */
        private function check_products_weight_dimensions($package) {
            $missing_items = array();

            foreach ($package['contents'] as $item_id => $values) {
                $product = $values['data'];
                if (!$product->has_weight() || !$product->has_dimensions()) {
                    $missing_items[] = $product->get_name();
                }
            }

            return $missing_items;
        }

        /**
         * Calculate total shipping weight, considering volumetric weight.
         */
        private function calculate_shipping_weight($package) {
            $total_weight = 0;
            $volumetric_divisor = 5000; // Standard divisor for volumetric weight (cm³ to kg)

            foreach ($package['contents'] as $item_id => $values) {
                $product = $values['data'];
                $quantity = $values['quantity'];

                // Get product dimensions
                $length = $product->get_length();
                $width = $product->get_width();
                $height = $product->get_height();

                // Calculate volumetric weight
                $volumetric_weight = 0;
                if ($length && $width && $height) {
                    $volumetric_weight = ($length * $width * $height) / $volumetric_divisor;
                }

                // Get actual weight
                $actual_weight = $product->get_weight();

                // Use the greater of actual weight or volumetric weight
                $effective_weight = max((float)$actual_weight, (float)$volumetric_weight);

                // Multiply by quantity and add to total
                $total_weight += $effective_weight * $quantity;
            }

            return $total_weight;
        }

        /**
         * Get shipping cost from Kargo API
         */
        private function get_shipping_cost_from_api($origin_postcode, $destination_postcode, $weight) {
            // Get rate from API
            $rate = $this->api_helper->get_rate($origin_postcode, $destination_postcode, $weight);

            if ($rate && isset($rate['VAT_INC'])) {
                return $rate['VAT_INC'];
            }

            return false;
        }
    }