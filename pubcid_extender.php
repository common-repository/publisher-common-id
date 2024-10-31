<?php
/*
Plugin Name: Publisher Common ID
Plugin URI: http://www.conversant.com
Description: PubCID is a free privacy centric first party cookie solution proven to improve site performance and increase earnings.
Version: 1.0.0
Author: Conversant
Author URI: http://www.conversant.com
License: Apache-2.0
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
defined( 'PUBCID_ALL_PAGES' ) or define ( 'PUBCID_ALL_PAGES', false );
defined( 'PUBCID_PIXEL_MAX_AGE' ) or define( 'PUBCID_PIXEL_MAX_AGE', 1 );

if (!class_exists('Pubcid_Extender')) {
	class Pubcid_Extender {
	    const OPTION_ID = 'pubcid_extender_options';
	    const SETTING_ID = 'pubcid_extender_settings';
	    const PAGE_ID = 'pubcid_extender_page';
	    const SECTION_ID = 'pubcid_extender_section';

		const CFG_VERSION = 'version';
	    const CFG_COOKIE_NAME = 'cookie_name';
		const CFG_MAX_AGE = 'max_age';
		const CFG_COOKIE_DOMAIN = 'cookie_domain';
		const CFG_CONSENT_FUNC = 'consent_func';
		const CFG_GEN_FUNC = 'gen_func';

        function __construct() {
	        register_activation_hook( __FILE__, array( &$this, 'set_default_options' ) );

	        if ( PUBCID_ALL_PAGES ) {
		        add_action( 'init', array( &$this, 'updatePubcid' ) );
	        }

	        add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
	        add_action( 'admin_init', array( &$this, 'admin_init' ) );
	        add_action( 'rest_api_init', array( &$this, 'register_route' ) );
        }

		/**
		 * Main hook to create, update and remove cookies during page load
		 */
        function updatePubcid() {
	        $options = $this->get_current_options();

	        // If the cookie name is empty, then there is nothing to do

	        if ( empty( $options[ self::CFG_COOKIE_NAME ] ) ) {
		        return;
	        }

	        // Extract values from saved settings

	        $cookie_name  = $options[ self::CFG_COOKIE_NAME ];
	        $cookie_domain = $options[ self::CFG_COOKIE_DOMAIN ];
	        $max_age      = $options[ self::CFG_MAX_AGE ];
	        $consent_func = $options[ self::CFG_CONSENT_FUNC ];
	        $gen_func     = $options[ self::CFG_GEN_FUNC ];

	        // Initialize cookie value

	        $value = null;
	        $crc = null;

	        // See if the cookie exist already

	        if ( isset( $_COOKIE[ $cookie_name ] ) ) {
		        $value = sanitize_text_field( $_COOKIE[ $cookie_name ] );
	        }

	        // Check consent.  There is consent when
	        // 1. Consent function is empty
	        // 2. Consent function returns true

	        if ( ! empty( $consent_func ) && is_callable( $consent_func ) && ! call_user_func( $consent_func ) ) {
		        // Delete old cookie if there is no consent
		        if ( isset( $value ) ) {
			        setcookie( $cookie_name, '', time() - 3600, "/", $cookie_domain );
		        }

		        return;
	        }


	        // If the cookie doesn't exist, and there is a generate function,
	        // then call the function to get a value.

	        if ( ! isset( $value ) ) {
		        if ( ! empty( $gen_func ) && is_callable( $gen_func ) ) {
			        $value = sanitize_text_field( call_user_func( $gen_func ) );
		        }
	        }

	        // Update the cookie

	        if ( isset( $value ) ) {
	            // Set cookie using header method in order to support SameSite before PHP 7.3
	            $expires = gmdate('D, d M Y H:i:s T', time() + $max_age * DAY_IN_SECONDS);
	            header('Set-Cookie: ' . $cookie_name . '=' . $value . '; expires=' . $expires . '; path=/; domain=' . $cookie_domain . '; SameSite=Lax' );
	        }
        }

		/**
		 * Define the setting page
		 */
        function admin_init() {
	        // Register a setting group with a validation function
	        register_setting(self::SETTING_ID, self::OPTION_ID, array(&$this, 'sanitize_options'));

	        // Add a new section within the group
	        add_settings_section(
		        self::SECTION_ID,
		        '',
		        array(&$this, 'main_setting_section_callback'),
		        self::PAGE_ID
	        );

	        $fields = array(
		        array( 'id' => self::CFG_COOKIE_NAME,
		               'label' => 'Cookie Name',
		               'type' => 'text',
		               'placeholder' => 'example: _pubcid'
		        ),
		        array( 'id' => self::CFG_MAX_AGE,
		               'label' => 'Max Age',
		               'type' => 'number',
		               'min' => 0,
		               'max' => 395,
		               'helper' => 'days',
		               'desc' => 'Maximum age of the cookie.'
		        ),
		        array( 'id' => self::CFG_COOKIE_DOMAIN,
		               'label' => 'Cookie Domain',
		               'type' => 'text',
		               'placeholder' => '',
		               'desc' => 'Leave it blank to use the current domain.  To cover all subdomains, specify the top level domain prepended with a period.'
		        ),
		        array( 'id' => self::CFG_CONSENT_FUNC,
		               'label' => 'Consent Function',
		               'type' => 'text',
		               'placeholder' => 'example: cn_cookies_accepted',
		               'desc' => 'If specified, then cookie is not updated unless the function returns true.'
		        ),
		        array( 'id' => self::CFG_GEN_FUNC,
		               'label' => 'Generate Function',
		               'type' => 'text',
		               'placeholder' => 'example: wp_generate_uuid4',
		               'desc' => 'If specified, then cookie is automatically generated using the value returned by the function.'
		        )
	        );


	        foreach ($fields as $field) {
		        add_settings_field($field['id'], $field['label'], array(&$this, 'display_field'), self::PAGE_ID, self::SECTION_ID, $field);
	        }
        }

		/**
		 * Save default options
		 */
        function set_default_options() {
            $this->get_current_options();
        }

		/**
         * Retrive saved options and merge with default values
		 * @return array current options
		 */
        function get_current_options() {
	        $options = get_option(self::OPTION_ID, array());

	        $new_options[ self::CFG_VERSION ] = '1.0.0';
	        $new_options[ self::CFG_CONSENT_FUNC ] = '';
	        $new_options[ self::CFG_COOKIE_NAME ] = '_pubcid';
	        $new_options[ self::CFG_GEN_FUNC ] = '';
	        $new_options[ self::CFG_MAX_AGE ] = 365;
	        $new_options[ self::CFG_COOKIE_DOMAIN ] = '';

	        $merged_options = wp_parse_args($options, $new_options);

	        $compare_options = array_diff_key($merged_options, $options);
	        if (empty($options) || !empty($compare_options)) {
		        update_option(self::OPTION_ID, $merged_options);
	        }

	        return $merged_options;
        }

		/**
		 * Append to the setting menu
		 */
        function admin_menu() {
	        add_options_page( 'Publisher Common ID Settings',
		        'Publisher Common ID',
		        'manage_options',
		        self::PAGE_ID,
		        array( &$this, 'make_config_page' ) );
        }

		/**
		 * General template for the setting page
		 */
        function make_config_page() {
	        $this->validate_options();
	        ?>
            <div id="pubcid_extender_general" class="wrap">
                <h2>Publisher Common ID Settings</h2>

                <form name="pubcid_extender_options_form" method="post" action="options.php">

			        <?php settings_fields( self::SETTING_ID ); ?>
			        <?php do_settings_sections( self::PAGE_ID ); ?>
			        <?php settings_errors( self::SETTING_ID ); ?>
                    <input type="submit" value="Save Changes" class="button-primary"/>
                </form>
            </div>
	        <?php
        }

		/**
		 * Flag any errors when the setting page is being displayed
		 */
		function validate_options() {
			$options = $this->get_current_options();
			if ( $options[ self::CFG_COOKIE_NAME ] == 'bogus' ) {
				add_settings_error( self::SETTING_ID, 'setting_updated', 'Bad cookie name' );
			}
			if ( ! empty( $options[ self::CFG_CONSENT_FUNC ] ) ) {
				$func_name = $options[ self::CFG_CONSENT_FUNC ];
				if ( ! is_callable( $func_name ) ) {
					add_settings_error( self::SETTING_ID, 'setting_updated', 'Function ' . $func_name . ' not found' );
				}
			}
		}

		/**
         * Clean up incoming data before it's saved
		 * @param $input mixed Incoming post data
		 *
		 * @return mixed Sanitized data
		 */
		function sanitize_options( $input ) {
			$text_fields = array(
				self::CFG_COOKIE_NAME,
				self::CFG_COOKIE_DOMAIN,
				self::CFG_CONSENT_FUNC,
				self::CFG_GEN_FUNC
			);

			foreach ( $text_fields as $text_field ) {
				if ( isset( $input[ $text_field ] ) ) {
					$input[ $text_field ] = sanitize_text_field( $input[ $text_field ] );
				}
			}

			if ( isset( $input[ self::CFG_MAX_AGE ] ) ) {
			    if ( $input[ self::CFG_MAX_AGE ] > 395)
			        $input[ self::CFG_MAX_AGE ] = 395;
			    elseif ( $input[ self::CFG_MAX_AGE ] < 0 )
                    $input[ self::CFG_MAX_AGE ] = 0;
            }

			return $input;
		}

		/**
		 * Add section description, if any
		 */
		function main_setting_section_callback() {
		}

		/**
         * Helper to add form elements
		 * @param $field array Array containing id, type, placeholder, helper, and desc.
		 */
		function display_field( $field ) {
			$options = $this->get_current_options();
			$id = $field['id'];
			$full_name = esc_attr( self::OPTION_ID . '[' . $id . ']' );
			$type = esc_attr( $field['type'] );

			if ( isset( $field['placeholder'] ) ) {
				$placeholder = esc_attr( $field['placeholder'] );
			} else {
				$placeholder = '';
			}

			switch ( $type ) {
				case 'text':
					printf( '<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" />',
						$full_name, $type, $placeholder, esc_html( $options[ $id ] ) );
					break;
				case 'number':
					printf( '<input name="%1$s" id="%1$s" type="%2$s" step="1" value="%3$s" min="%4$d" max="%5$d"/>',
						$full_name, $type, esc_attr( $options[ $id ] ), esc_attr( $field['min'] ), esc_attr( $field['max'] ) );
					break;
			}

			if ( isset( $field['helper'] ) ) {
				printf( '<span class="helper"> %s</span>', esc_html( $field['helper'] ) );
			}

			if ( isset( $field['desc'] ) ) {
				printf( '<p class="description">%s</p>', esc_html( $field['desc'] ) );
			}
		}

		function register_route() {
			register_rest_route( 'pubcid/v1', 'extend',
				array(
					'method'   => 'GET',
					'callback' => array( &$this, 'extend' )
				) );
        }

        function extend() {
		    if ( !PUBCID_ALL_PAGES )
		        $this->updatePubcid();

	        $options = $this->get_current_options();
	        $cookie_name  = $options[ self::CFG_COOKIE_NAME ];

            header( 'Content-Encoding: none' );
            header( 'Content-Type: image/gif' );
            header( 'Content-Length: 43' ) ;

            // Set different caching options based on having cookie
            if ( isset($_COOKIE[$cookie_name]) ) {
                $max_age = PUBCID_PIXEL_MAX_AGE * DAY_IN_SECONDS;
	            $expires = gmdate('D, d M Y H:i:s T', time() + $max_age );
	            header( 'Cache-Control: private, max-age=' . $max_age );
	            header( 'Expries: ' . $expires);

            }
            else {
	            header( 'Cache-Control: no-cache' );
	            header( 'Pragma: no-cache');
            }

            echo "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x90\x00\x00\xff\x00\x00\x00\x00\x00\x21\xf9\x04\x05\x10\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x04\x01\x00\x3b";
        }
	}

	$pubcid_extender = new Pubcid_Extender();
}

