<?php
    /**
     * Admin class for Kargo National Shipping
     *
     * @package Kargo_National_Shipping
     */

// Exit if accessed directly
    if (!defined('ABSPATH')) {
        exit;
    }

    /**
     * Admin class
     */
    class Kargo_NS_Admin {
        /**
         * API Helper instance
         *
         * @var Kargo_NS_API_Helper
         */
        private $api_helper;

        /**
         * Constructor
         */
        public function __construct() {
            // Initialize API Helper
            require_once KARGO_NS_PLUGIN_DIR . 'includes/class-kargo-api-helper.php';
            $this->api_helper = new Kargo_NS_API_Helper();

            // Add menu item
            add_action('admin_menu', array($this, 'add_admin_menu'));

            // Register settings
            add_action('admin_init', array($this, 'register_settings'));

            // Add settings link on plugin page
            add_filter('plugin_action_links_' . KARGO_NS_PLUGIN_BASENAME, array($this, 'plugin_settings_link'));
        }

        /**
         * Add admin menu item
         */
        public function add_admin_menu() {
            add_submenu_page(
                'woocommerce',
                __('Kargo National Shipping', 'kargo-national-shipping'),
                __('Kargo National Shipping', 'kargo-national-shipping'),
                'manage_woocommerce',
                'kargo-national-shipping',
                array($this, 'admin_page')
            );
        }

        /**
         * Register settings
         */
        public function register_settings() {
            // Register settings
            register_setting('kargo_ns_settings', 'kargo_ns_username');
            register_setting('kargo_ns_settings', 'kargo_ns_password', array($this, 'encrypt_password'));
            register_setting('kargo_ns_settings', 'kargo_ns_account_number');

            // Add sections
            add_settings_section(
                'kargo_ns_api_settings',
                __('API Settings', 'kargo-national-shipping'),
                array($this, 'api_settings_section'),
                'kargo_ns_settings'
            );

            // Add fields
            add_settings_field(
                'kargo_ns_username',
                __('Username', 'kargo-national-shipping'),
                array($this, 'username_field'),
                'kargo_ns_settings',
                'kargo_ns_api_settings'
            );

            add_settings_field(
                'kargo_ns_password',
                __('Password', 'kargo-national-shipping'),
                array($this, 'password_field'),
                'kargo_ns_settings',
                'kargo_ns_api_settings'
            );

            add_settings_field(
                'kargo_ns_account_number',
                __('Account Number', 'kargo-national-shipping'),
                array($this, 'account_number_field'),
                'kargo_ns_settings',
                'kargo_ns_api_settings'
            );
        }

        /**
         * API settings section
         */
        public function api_settings_section() {
            echo '<p>' . __('Enter your Kargo National API credentials. These are required for the shipping method to work.', 'kargo-national-shipping') . '</p>';
        }

        /**
         * Username field
         */
        public function username_field() {
            $username = get_option('kargo_ns_username');
            echo '<input type="text" name="kargo_ns_username" value="' . esc_attr($username) . '" class="regular-text" />';
            echo '<p class="description">' . __('Your My Kargo Online Username', 'kargo-national-shipping') . '</p>';
        }

        /**
         * Password field
         */
        public function password_field() {
            $password = $this->decrypt_password(get_option('kargo_ns_password'));
            echo '<input type="password" name="kargo_ns_password" value="' . esc_attr($password) . '" class="regular-text" />';
            echo '<p class="description">' . __('Your My Kargo Online Password', 'kargo-national-shipping') . '</p>';
        }

        /**
         * Account number field
         */
        public function account_number_field() {
            $account_number = get_option('kargo_ns_account_number');
            echo '<input type="text" name="kargo_ns_account_number" value="' . esc_attr($account_number) . '" class="regular-text" />';
            echo '<p class="description">' . __('Your Kargo Account Number', 'kargo-national-shipping') . '</p>';
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

        /**
         * Admin page
         */
        public function admin_page() {
            // Check user capabilities
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            // Test API connection if requested
            $test_result = null;
            if (isset($_POST['test_api']) && check_admin_referer('kargo_ns_test_api')) {
                $test_result = $this->api_helper->test_connection();
            }

            // Display settings page
            ?>
            <div class="wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

                <?php if ($test_result) : ?>
                    <div class="<?php echo $test_result['success'] ? 'notice notice-success' : 'notice notice-error'; ?>">
                        <p><?php echo esc_html($test_result['message']); ?></p>
                        <?php if ($test_result['success'] && isset($test_result['data'])): ?>
                            <pre><?php print_r($test_result['data']); ?></pre>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php
                        settings_fields('kargo_ns_settings');
                        do_settings_sections('kargo_ns_settings');
                        submit_button();
                    ?>
                </form>

                <hr>

                <h2><?php _e('Test API Connection', 'kargo-national-shipping'); ?></h2>
                <p><?php _e('Test your API credentials to make sure they are working correctly.', 'kargo-national-shipping'); ?></p>

                <form method="post" action="">
                    <?php wp_nonce_field('kargo_ns_test_api'); ?>
                    <input type="hidden" name="test_api" value="1">
                    <input type="submit" class="button button-secondary" value="<?php _e('Test Connection', 'kargo-national-shipping'); ?>">
                </form>
            </div>
            <?php
        }

        /**
         * Add settings link on plugin page
         */
        public function plugin_settings_link($links) {
            $settings_link = '<a href="admin.php?page=kargo-national-shipping">' . __('Settings', 'kargo-national-shipping') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        }
    }