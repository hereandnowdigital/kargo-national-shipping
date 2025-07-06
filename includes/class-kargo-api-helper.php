<?php
    /**
     * Kargo API Helper Class
     *
     * @package Kargo_National_Shipping
     */

// Exit if accessed directly
    if (!defined('ABSPATH')) {
        exit;
    }

    /**
     * Class Kargo_NS_API_Helper
     *
     * Handles all communication with the Kargo SOAP API
     */
    class Kargo_NS_API_Helper {
        /**
         * API endpoint URL
         *
         * @var string
         */
        private $api_url = 'http://api.kargo.co.za/API.asmx?WSDL';

        /**
         * API username
         *
         * @var string
         */
        private $username;

        /**
         * API password
         *
         * @var string
         */
        private $password;

        /**
         * API account number
         *
         * @var string
         */
        private string $account_number;

        /**
         * Debug mode
         *
         * @var bool
         */
        private bool $debug;

        /**
         * Constructor
         *
         * @param string $username       API username
         * @param string $password       API password
         * @param string $account_number API account number
         * @param bool $debug          Debug mode
         */
        public function __construct(string $username = '', string $password = '', string $account_number = '', bool $debug = false) {
            $this->username = !empty($username) ? $username : get_option('kargo_ns_username');
            $this->password = !empty($password) ? $password : $this->decrypt_password(get_option('kargo_ns_password'));
            $this->account_number = !empty($account_number) ? $account_number : get_option('kargo_ns_account_number');
            $this->debug = $debug;
        }

        /**
         * Get rate from Kargo API
         *
         * @param string $origin_postcode      Origin postal code
         * @param string $destination_postcode Destination postal code
         * @param float $weight             Shipment weight in kg
         *
         * @return array|bool Rate data on success, false on failure
         */
        public function parse_response( string $origin_postcode, string $destination_postcode, float $weight, array $dimensions): bool|array {
            // Check required parameters
            if (empty($this->username) || empty($this->password) || empty($this->account_number)) {
                $this->log_debug('Missing API credentials');
                return false;
            }

	        if (
		        empty($origin_postcode)
		        || empty($destination_postcode)
		        || (empty($weight) && empty($dimensions))
	        ) {
		        $this->log_debug('Missing required package parameters');
		        return false;
	        }

            try {
                // Create SOAP client
                $client = new SoapClient($this->api_url, array(
                    'trace' => true,
                    'exceptions' => true,
                    'cache_wsdl' => WSDL_CACHE_NONE
                ));

	            // Prepare request parameters
	            $params = array(
		            'username' => $this->username,
		            'password' => $this->password,
		            'accountNumber' => $this->account_number,
		            'postalCodeOrigin' => $origin_postcode,
		            'postalCodeDestination' => $destination_postcode,
		            'weight' => (int) round($weight ?? 1),  //ensure weight is always a minimum of 1
		            'width' => (int) round($dimensions['width'] ?? 0),
		            'height' => (int) round($dimensions['height'] ?? 0),
		            'length' => (int) round($dimensions['length'] ?? 0),
	            );

	            // Make API call
	            $response = $client->RateEnquiry($params);
	            if ( isset( $params['password'] ) ) {
		            $params['password'] = '[redacted]';
	            }
	            $this->log_debug('API Request: ' . print_r($params, true));
	            $this->log_debug('API Response: ' . print_r($response, true));

	            // Check for valid response
	            if ( isset($response->RateEnquiryResult ) && !empty($response->RateEnquiryResult) ) {
		            return $this->process_rate_response($response->RateEnquiryResult);
	            }

                $this->log_debug('Invalid API response');
                return false;

            } catch (Exception $e) {
                $this->log_debug('API Error: ' . $e->getMessage());
                return false;
            }
        }

        /**
         * Process rate response from API
         *
         * @param $response_json
         *
         * @return array|bool Rate data on success, false on failure
         */
	    private function process_rate_response($response_json) {
		    $response_array = json_decode( json_encode($response_json), true );
		    $response = $response_array['any'];
		    if (!is_string($response)) {
			    $this->log_debug('Response is not a string.');
			    return false;
		    }

		    // Extract the KREW section as a raw string
		    $start = strpos($response, '<KREW ');
		    if ($start === false) {
			    $start = strpos($response, '<KREW>');
		    }

		    if ($start === false) {
			    $this->log_debug('No KREW element found in response');
			    return false;
		    }

		    $end = strpos($response, '</KREW>', $start);
		    if ($end === false) {
			    $this->log_debug('No closing KREW tag found');
			    return false;
		    }

		    // Extract the KREW content with its tags
		    $krew_length = $end - $start + 7; // +7 for '</KREW>'
		    $krew_xml = substr($response, $start, $krew_length);

		    // Extract the fields we need using simple string functions
		    $result = array();
		    $fields = array(
			    'RequestStatusSuccess',
			    'RequestErrorMessage',
			    'Subtotal',
			    'VAT',
			    'Total',
			    'AccountActive',
			    'AccountStatus'
		    );

		    foreach ($fields as $field) {
			    $field_start = strpos($krew_xml, '<' . $field . '>');
			    if ($field_start !== false) {
				    $field_start += strlen($field) + 2; // +2 for '<>'
				    $field_end = strpos($krew_xml, '</' . $field . '>', $field_start);
				    if ($field_end !== false) {
					    $value = substr($krew_xml, $field_start, $field_end - $field_start);
					    $result[$field] = $value;
				    }
			    }
		    }

		    if (!empty($result) && isset($result['Subtotal'])) {
			    $this->log_debug('Successfully extracted response data: ' . print_r($result, true));
			    return $result;
		    }

		    $this->log_debug('Could not extract rate data from response');
		    return false;
	    }


        /**
         * Test API connection
         *
         * @return array Result of API test
         */
        public function test_connection(): array
        {
            if (empty($this->username) || empty($this->password) || empty($this->account_number)) {
                return array(
                    'success' => false,
                    'message' => __('Please enter your API credentials before testing.', 'kargo-national-shipping'),
                );
            }

            try {
                // Create SOAP client
                $client = new SoapClient($this->api_url, array(
                    'trace' => true,
                    'exceptions' => true,
                    'cache_wsdl' => WSDL_CACHE_NONE
                ));

				$requestParams = array(
					'username' => $this->username,
					'password' => $this->password,
					'accountNumber' => $this->account_number,
					'postalCodeOrigin' => '2001',
					'postalCodeDestination' => '2001',
					'weight' => 10,
					'width' => 10,
					'height' => 10,
					'length' => 10
				);

                $response = $client->RateEnquiry($requestParams);
	            $response_detailsArr = $this->process_rate_response($response->RateEnquiryResult);

				if ( $response_detailsArr['RequestStatusSuccess'] == 'false') {
					$message = sprintf(__('API connection failed: %s', 'kargo-national-shipping'), $response_detailsArr['RequestErrorMessage'] ?? 'Something went wrong');
					$this->log_debug('API Test Failed: ' . $message);
					return array(
						'success' => false,
						'message' => $message
					);
				}

	            $message = __('API connection successful! Your credentials are working correctly.', 'kargo-national-shipping');
	            $this->log_debug($message);

	            return array(
		            'success' => true,
		            'message' => __('API connection successful! Your credentials are working correctly.', 'kargo-national-shipping'),
	            );

            } catch (Exception $e) {
                return array(
                    'success' => false,
                    'message' => sprintf(__('API connection failed: %s', 'kargo-national-shipping'), $e->getMessage()),
                );
            }
        }

        /**
         * Log debug messages
         *
         * @param string $message Message to log
         */
        private function log_debug(string $message): void
        {
            if ($this->debug) {
                if (!defined('WC_LOG_HANDLER')) {
                    define('WC_LOG_HANDLER', 'WC_Log_Handler_File');
                }

                $logger = new WC_Logger();
                $logger->add('kargo-shipping', $message);
            }
        }

	    /**
	     * Encrypt password before saving
	     *
	     * @param string $password Password to encrypt
	     *
	     * @return string Encrypted password
	     */
	    public function encrypt_password($password) {
		    if (empty($password)) {
			    return '';
		    }

		    // If password already starts with 'kargo_encrypted:', don't encrypt again
		    if (strpos($password, 'kargo_encrypted:') === 0) {
			    return $password;
		    }

		    // Simple encryption - in a real world scenario, use more robust encryption
		    return 'kargo_encrypted:' . base64_encode($password);
	    }

	    /**
	     * Decrypt password
	     *
	     * @param string $encrypted_password Encrypted password
	     *
	     * @return string Decrypted password
	     */
	    public function decrypt_password($encrypted_password) {
		    if (empty($encrypted_password)) {
			    return '';
		    }

		    // Check if password is encrypted
		    if (strpos($encrypted_password, 'kargo_encrypted:') === 0) {
			    // Remove prefix and decrypt
			    $encrypted_part = substr($encrypted_password, strlen('kargo_encrypted:'));
			    return base64_decode($encrypted_part);
		    }

		    // Password is not encrypted
		    return $encrypted_password;
	    }

    }