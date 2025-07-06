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
        private Kargo_NS_API_Helper $api_helper;

        private int $max_length = 120; //cm
        private int $max_width  = 120; //cm
        private int $max_height = 100; //cm

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
            $this->origin_postcode  = $this->get_option('origin_postcode', '');
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
                    'description' => __('The title the user sees during checkout.', 'kargo-national-shipping'),
                    'default'     => __('Kargo National Shipping', 'kargo-national-shipping'),
                    'desc_tip'    => true,
                ),
                'origin_postcode' => array(
                    'title'       => __('Origin Postal Code', 'kargo-national-shipping'),
                    'type'        => 'text',
                    'description' => __('Enter the postal code from where you ship your products.  Defaults to store base location postal code.', 'kargo-national-shipping'),
                    'default'     => wc_format_postcode( WC()->countries->get_base_postcode(), WC()->countries->get_base_country() ),
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
	        $available = $this->is_enabled();

	        if ( ! parent::is_available( $package ) )
		        wc_get_logger()->warning(
			        sprintf(
				        'Unavailable: %s',
				        implode(', ', ['Parent not available'])
			        ),
			        array('source' => 'kargo-shipping')
		        );

            if ( ! parent::is_available( $package ) )
                return false;

	        if ( isset( $package['destination']['country'] ) && $package['destination']['country'] !== 'ZA' )
			        wc_get_logger()->warning(
				        sprintf(
					        'Unavailable: %s',
					        implode(', ', ['Destination not ZA'])
				        ),
				        array('source' => 'kargo-shipping')
			        );

            // Only allow shipping to South Africa (ZA)
            if ( isset( $package['destination']['country'] ) && $package['destination']['country'] !== 'ZA' )
                return false;


	        if ( $this->exceeds_max_dimensions( $package ) )
			        wc_get_logger()->warning(
				        sprintf(
					        'Unavailable: %s',
					        implode(', ', ['Exceeds max dimenions'])
				        ),
				        array('source' => 'kargo-shipping')
			        );

            if ( $this->exceeds_max_dimensions( $package ) )
                return false;

            // Get API credentials
            $username = get_option('kargo_ns_username');
            $password = get_option('kargo_ns_password');
            $account_number = get_option('kargo_ns_account_number');

	        if (empty($username) || empty($password) || empty($account_number))
		        if ( $this->exceeds_max_dimensions( $package ) )
			        wc_get_logger()->warning(
				        sprintf(
					        'Unavailable: %s',
					        implode(', ', ['Empty API details'])
				        ),
				        array('source' => 'kargo-shipping')
			        );

            // If credentials are not set, the method is not available
            if (empty($username) || empty($password) || empty($account_number))
                return false;

            return true;
        }

        private function exceeds_max_dimensions( $package ): bool {
            $unit = get_option( 'woocommerce_dimension_unit', 'cm' );

            // Conversion multipliers to cm
            $conversion_factors = [
                'mm' => 0.1,
                'cm' => 1,
                'm'  => 100,
                'in' => 2.54,
                'yd' => 91.44,
            ];

            $max_length = $this->max_length;
            $max_width  = $this->max_width;
            $max_height = $this->max_height;

            $factor = $conversion_factors[$unit] ?? 1;

            foreach ( $package['contents'] as $item ) {
                $product = $item['data'];

                if ( ! $product instanceof WC_Product )
                    continue;

                // Convert dimensions to cm
                $length = (float) $product->get_length() * $factor;
                $width  = (float) $product->get_width()  * $factor;
                $height = (float) $product->get_height() * $factor;

                if ( $length > $max_length || $width > $max_width || $height > $max_height )
                    return true;

            }

            return false;

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
            if (empty($destination_postcode))
                return;

            // Get origin postcode from settings or store settings
            $origin_postcode = $this->origin_postcode ?? '';
            if (empty($origin_postcode))
                $origin_postcode = get_option('woocommerce_store_postcode');

            // If no origin postcode, we cannot calculate shipping
            if (empty($origin_postcode))
                return;

            // Calculate total weight - Enforce a minimum weight of 1kg
            $weight = $weight = max( 1, WC()->cart->get_cart_contents_weight() );
	        $dimensions = $this->calculate_bounding_box_dimensions($package) ?? [0, 0, 0];

            // Call API to get shipping cost
            $shipping_cost = $this->get_shipping_cost_from_api($origin_postcode, $destination_postcode, $weight, $dimensions );

	        // If we couldn't get a valid shipping cost - don't offer the shipping method
	        if (false === $shipping_cost)
		        return;

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
        private function check_products_weight_dimensions($package): array {
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
	     * Calculate bounding box dimensions for WooCommerce cart items.
	     *
	     * @return array Associative array with 'length', 'width', and 'height' in cm.
	     */
	    public function calculate_bounding_box_dimensions( $package ) {
		    $length = 0;
		    $width  = 0;
		    $height = 0;
		    $total_volume = 0;


		    foreach ( $package['contents'] as $item ) {
			    $product = $item['data'];
			    if ( ! $product instanceof WC_Product )
				    continue;

			    $quantity = $item['quantity'];
			    $p_length = $product->get_length();
			    $p_width  = $product->get_width();
			    $p_height = $product->get_height();

			    // Ensure dimensions are numeric
			    $p_length = is_numeric( $p_length ) ? $p_length : 0;
			    $p_width  = is_numeric( $p_width ) ? $p_width : 0;
			    $p_height = is_numeric( $p_height ) ? $p_height : 0;

			    $volume = $p_length * $p_width * $p_height * $quantity;
			    $total_volume += $volume;

			    // Add height cumulatively
			    $height += $p_height * $quantity;

			    // Take the max of length and width
			    $length = max( $length, $p_length );
			    $width  = max( $width, $p_width );
		    }

		    // Prevent division by zero
		    $base_area = max( 1, $length * $width );

		    // Calculate height based on total volume and base area
		    $calculated_height = $total_volume / $base_area;

		    // Ensure height is at least as tall as the tallest item
		    $height = max( $calculated_height, $height );

		    // Apply inefficiency factor (e.g., 10% extra space)
		    $inefficiency_factor = 1.1;
		    $height *= $inefficiency_factor;

		    $unit = get_option( 'woocommerce_dimension_unit', 'cm' );
		    $conversion_factors = [
			    'mm' => 0.1,
			    'cm' => 1,
			    'm'  => 100,
			    'in' => 2.54,
			    'yd' => 91.44,
		    ];

		    $factor = $conversion_factors[ $unit ] ?? 1;

		    return [
				'length' => ceil( $length * $factor ) ?? 0,
				'width' => ceil( $width * $factor ) ?? 0,
		        'height' => ceil( $height * $factor ) ?? 0
		    ];

	    }


        /**
         * Get shipping cost from Kargo API
         */
        private function get_shipping_cost_from_api($origin_postcode, $destination_postcode, $weight, $dimensions = []) {
            // Get rate from API
	        $api_response = $this->api_helper->parse_response( $origin_postcode, $destination_postcode, $weight, $dimensions );

	        if (
		        is_array( $api_response )
		        && ! empty( $api_response['AccountActive'] )
		        && $api_response['AccountActive'] === 'true'
		        && isset( $api_response['Subtotal'] )
		        && is_numeric( $api_response['Subtotal'] )
		        && $api_response['Subtotal'] > 0
	        )

				return $api_response['Subtotal'];

            return false;
        }
    }