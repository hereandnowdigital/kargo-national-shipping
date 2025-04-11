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
        private $account_number;

        /**
         * Debug mode
         *
         * @var bool
         */
        private $debug;

        /**
         * Constructor
         *
         * @param string $username       API username
         * @param string $password       API password
         * @param string $account_number API account number
         * @param bool   $debug          Debug mode
         */
        public function __construct($username = '', $password = '', $account_number = '', $debug = false) {
            $this->username = !empty($username) ? $username : get_option('kargo_ns_username');
            $this->password = !empty($password) ? $password : get_option('kargo_ns_password');
            $this->account_number = !empty($account_number) ? $account_number : get_option('kargo_ns_account_number');
            $this->debug = $debug;
        }

        /**
         * Get rate from Kargo API
         *
         * @param int $origin_postcode      Origin postal code
         * @param int $destination_postcode Destination postal code
         * @param float $weight             Shipment weight in kg
         *
         * @return array|bool Rate data on success, false on failure
         */
        public function get_rate($origin_postcode, $destination_postcode, $weight) {
            // Check required parameters
            if (empty($this->username) || empty($this->password) || empty($this->account_number)) {
                $this->log_debug('Missing API credentials');
                return false;
            }

            if (empty($origin_postcode) || empty($destination_postcode) || empty($weight)) {
                $this->log_debug('Missing required parameters');
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
                    'postalCodeOrigin' => (int) $origin_postcode,
                    'postalCodeDestination' => (int) $destination_postcode,
                    'weight' => (float) $weight
                );

                $this->log_debug('API Request: ' . print_r($params, true));

                // Make API call
                $response = $client->RateEnquiry($params);

                $this->log_debug('API Response: ' . print_r($response, true));

                // Check for valid response
                if (isset($response->RateEnquiryResult) && !empty($response->RateEnquiryResult)) {
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
         * @param mixed $response API response
         *
         * @return array|bool Rate data on success, false on failure
         */
        private function process_rate_response($response) {
            if (is_string($response)) {
                $xml = simplexml_load_string($response);

                if ($xml === false) {
                    $this->log_debug('Failed to parse XML response');
                    return false;
                }

                $json = json_encode($xml);
                $array = json_decode($json, true);

                if (isset($array['diffgr:diffgram']['NewDataSet']['KREW'])) {
                    return $array['diffgr:diffgram']['NewDataSet']['KREW'];
                } elseif (isset($array['diffgram']['NewDataSet']['KREW'])) {
                    return $array['diffgram']['NewDataSet']['KREW'];
                }
            }

            $this->log_debug('Could not extract rate data from response');
            return false;
        }

        /**
         * Test API connection
         *
         * @return array Result of API test
         */
        public function test_connection() {
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

                // Test with sample data
                $response = $client->RateEnquiry(array(
                    'username' => $this->username,
                    'password' => $this->password,
                    'accountNumber' => $this->account_number,
                    'postalCodeOrigin' => 2000, // Example postal code
                    'postalCodeDestination' => 8000, // Example postal code
                    'weight' => 1.0 // Example weight
                ));

                if (isset($response->RateEnquiryResult) && !empty($response->RateEnquiryResult)) {
                    // Try to parse the result
                    $result = $this->process_rate_response($response->RateEnquiryResult);

                    if ($result) {
                        return array(
                            'success' => true,
                            'message' => __('API connection successful! Your credentials are working correctly.', 'kargo-national-shipping'),
                            'data' => $result
                        );
                    } else {
                        return array(
                            'success' => false,
                            'message' => __('API connection failed. Could not parse the response.', 'kargo-national-shipping'),
                        );
                    }
                } else {
                    return array(
                        'success' => false,
                        'message' => __('API connection failed. The API returned an unexpected response.', 'kargo-national-shipping'),
                    );
                }

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
        private function log_debug($message) {
            if ($this->debug) {
                if (!defined('WC_LOG_HANDLER')) {
                    define('WC_LOG_HANDLER', 'WC_Log_Handler_File');
                }

                $logger = new WC_Logger();
                $logger->add('kargo-shipping', $message);
            }
        }
    }