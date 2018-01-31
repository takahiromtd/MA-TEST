<?php if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Plugin Name: Ninja Forms - Mail Chimp
 * Plugin URI: https://ninjaforms.com/extensions/mail-chimp/
 * Description: Sign up users for your Mail Chimp newsletter when submitting Ninja Forms
 * Version: 3.0.5
 * Author: The WP Ninjas
 * Author URI: http://wpninjas.com/
 * Text Domain: ninja-forms-mail-chimp
 *
 * Copyright 2016 The WP Ninjas.
 */

if( version_compare( get_option( 'ninja_forms_version', '0.0.0' ), '3', '<' ) || get_option( 'ninja_forms_load_deprecated', FALSE ) ) {

    include 'deprecated/ninja-forms-mailchimp.php';

} else {

    if( ! class_exists( 'Mailchimp' ) ) {
        include_once 'includes/Libraries/Mailchimp.php';
    }

    /**
     * Class NF_MailChimp
     */
    final class NF_MailChimp
    {
        const VERSION = '3.0.5';
        const SLUG    = 'mail-chimp';
        const NAME    = 'MailChimp';
        const AUTHOR  = 'The WP Ninjas';
        const PREFIX  = 'NF_MailChimp';

        /**
         * @var NF_MailChimp
         * @since 3.0
         */
        private static $instance;

        /**
         * Plugin Directory
         *
         * @since 3.0
         * @var string $dir
         */
        public static $dir = '';

        /**
         * Plugin URL
         *
         * @since 3.0
         * @var string $url
         */
        public static $url = '';

        /**
         * @var Mailchimp
         */
        private $_api;

        /**
         * Main Plugin Instance
         *
         * Insures that only one instance of a plugin class exists in memory at any one
         * time. Also prevents needing to define globals all over the place.
         *
         * @since 3.0
         * @static
         * @static var array $instance
         * @return NF_MailChimp Highlander Instance
         */
        public static function instance()
        {

            if ( !isset( self::$instance ) && !( self::$instance instanceof NF_MailChimp ) ) {
                self::$instance = new NF_MailChimp();

                self::$dir = plugin_dir_path(__FILE__);

                self::$url = plugin_dir_url(__FILE__);

                spl_autoload_register(array(self::$instance, 'autoloader'));

                new NF_MailChimp_Admin_Settings();
            }

            return self::$instance;
        }

        /**
         * NF_MailChimp constructor.
         *
         */
        public function __construct()
        {

            if ( ! function_exists( 'curl_version' ) ) {
                add_action( 'admin_notices', array( $this, 'curl_error' ) );
                return false;
            }
            add_action( 'admin_init', array( $this, 'setup_license' ) );
            add_filter( 'ninja_forms_register_fields', array( $this, 'register_fields' ) );
            add_filter( 'ninja_forms_register_actions', array( $this, 'register_actions' ) );

            add_action( 'ninja_forms_loaded', array( $this, 'ninja_forms_loaded' ) );
        }

        public function ninja_forms_loaded()
        {
            new NF_MailChimp_Admin_Metaboxes_Submission();
        }

        /**
         * Register Fields
         *
         * @param array $actions
         * @return array $actions
         */
        public function register_fields($actions)
        {
            $actions[ 'mailchimp-optin' ] = new NF_MailChimp_Fields_OptIn();

            return $actions;
        }

        /**
         * Register Actions
         *
         * @param array $actions
         * @return array $actions
         */
        public function register_actions($actions)
        {
            $actions[ 'mailchimp' ] = new NF_MailChimp_Actions_MailChimp();

            return $actions;
        }

        /**
         * Autoloader
         *
         * @param $class_name
         */
        public function autoloader($class_name)
        {
            if (class_exists($class_name)) return;

            if ( false === strpos( $class_name, self::PREFIX ) ) return;

            $class_name = str_replace( self::PREFIX, '', $class_name );
            $classes_dir = realpath(plugin_dir_path(__FILE__)) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR;
            $class_file = str_replace('_', DIRECTORY_SEPARATOR, $class_name) . '.php';

            if (file_exists($classes_dir . $class_file)) {
                require_once $classes_dir . $class_file;
            }
        }

        /**
         * Setup License
         */
        public function setup_license()
        {
            if ( ! class_exists( 'NF_Extension_Updater' ) ) return;

            new NF_Extension_Updater( self::NAME, self::VERSION, self::AUTHOR, __FILE__, self::SLUG );
        }

        /*
         * API
         */

        public function get_lists()
        {
            if( ! $this->api() ) return array();

            $lists = array();

            // Sets the list limit to 100, so that when the call is made up to 100 items will be returned.
            $list_limit = 100;
            try {
                $response = $this->api()->call( 'lists/list', array(
                        'limit' => $list_limit
                    )
                );
            } catch( Exception $e ){
                return $lists;
            }

            foreach( $response[ 'data' ] as $data ) {

                Ninja_Forms()->update_setting( 'mail_chimp_list_' . $data[ 'id' ], $data[ 'name' ] );

                $lists[] = array(
                    'value' => $data[ 'id' ],
                    'label' => $data[ 'name' ],
                    'groups' => $this->get_list_groups( $data[ 'id' ] ),
                    'fields' => $this->get_list_merge_vars( $data[ 'id' ] )
                );
            }

            return $lists;
        }

        public function get_list_merge_vars( $list_id )
        {
            if( ! $this->api() ) return array();

            $response = $this->api()->lists->mergeVars( array( $list_id ) );

            $merge_vars = array();
            $list = $response[ 'data' ][ 0 ];
            foreach( $list[ 'merge_vars' ] as $merge_var ){

                $required_text = ( $merge_var[ 'req' ] ) ? ' <small style="color:red">(required)</small>' : '';

                $merge_vars[] = array(
                    'value' => $list[ 'id' ] . '_' . $merge_var[ 'tag' ],
                    'label' => $merge_var[ 'name' ] . $required_text
                );
            }

            return $merge_vars;
        }

        public function get_list_groups( $list_id )
        {
            if( ! $this->api() ) return array();

            try {
                $response = $this->api()->lists->interestGroupings($list_id);
            }  catch( Mailchimp_Error $e ) {
                return array();
            } catch( Exception $e ) {
                // TODO: Log error for System Status page.
                return array();
            }

            $groups = array();

            if( $response ) {
                foreach( $response as $grouping ) {
                    foreach ($grouping['groups'] as $group) {
                        $groups[] = array(
                            'value' => $list_id . '_group_' . $grouping['id'] . '_' . $group['name'],
                            'label' => $group['name']
                        );
                    }
                }
            }

            return $groups;
        }

        public function subscribe( $list_id, $merge_vars, $double_opt_in )
        {
            try {
                return NF_MailChimp()->api()->call('lists/subscribe', array(
                    'id' => $list_id,
                    'email' => array( 'email' => $merge_vars[ 'EMAIL' ] ),
                    'merge_vars' => $merge_vars,
                    'double_optin' => $double_opt_in,
                    'update_existing' => true,
                    'replace_interests' => false,
                    'send_welcome' => false,
                ));
            } catch( Mailchimp_Error $e ) {
                // TODO: Log error for System Status page.
                return array( 'error' => $e->getMessage() );
            } catch( Exception $e ) {
                // TODO: Log error for System Status page.
                return FALSE;
            }
        }

        public function api()
        {
            if( ! $this->_api ) {

                $debug = defined('WP_DEBUG') && WP_DEBUG;
                $api_key = trim(Ninja_Forms()->get_setting('ninja_forms_mc_api'));
                $ssl_verifypeer = (Ninja_Forms()->get_setting('ninja_forms_mc_disable_ssl_verify')) ? FALSE : TRUE;

                $options = array(
                    'debug' => $debug,
                    'ssl_verifypeer' => $ssl_verifypeer,
                );

                try {
                    $this->_api = new Mailchimp($api_key, $options);
                } catch (Exception $e) {
                    // TODO: Log Error, $e->getMessage(), for System Status Report
                }
            }
            return $this->_api;
        }

        /*
         * STATIC METHODS
         */

        /**
         * Load Template File
         *
         * @param string $file_name
         * @param array $data
         */
        public static function template( $file_name = '', array $data = array() )
        {
            if( ! $file_name ) return;

            extract( $data );

            include self::$dir . 'includes/Templates/' . $file_name;
        }

        /**
         * Load Config File
         *
         * @param $file_name
         * @return array
         */
        public static function config( $file_name )
        {
            return include self::$dir . 'includes/Config/' . $file_name . '.php';
        }

        /**
         * Output an admin notice if curl is not available
         *
         * @return  void;
         */
        public static function curl_error() {
            ?>
            <div class="notice notice-error">
                <p>
                    <?php _e( '<strong>Please contact your host:</strong> PHP cUrl is not installed; Mailchimp for Ninja Forms requires cUrl and will not function properly. ', 'ninja-forms-mailchimp' ); ?>
                </p>
            </div>

            <?php
        }
    }

    /**
     * The main function responsible for returning The Highlander Plugin
     * Instance to functions everywhere.
     *
     * Use this function like you would a global variable, except without needing
     * to declare the global.
     *
     * @since 3.0
     * @return NF_MailChimp
     */
    function NF_MailChimp()
    {
        return NF_MailChimp::instance();
    }

    NF_MailChimp();
}

add_filter( 'ninja_forms_upgrade_action_mailchimp', 'NF_MailChimp_Upgrade' );
function NF_MailChimp_Upgrade( $action ){

    // newsletter_list
    if( ! isset( $action[ 'list-id' ] ) ) return $action;

    $list_id = $action[ 'list-id' ];
    $action[ 'newsletter_list' ] = $list_id;

    // 93c8c814a4_EMAIL
    if( isset( $action[ 'merge-vars' ] ) ) {
        $merge_vars = maybe_unserialize($action['merge-vars']);
        foreach ($merge_vars as $key => $value) {
            $action[$list_id . '_' . $key] = $value;
        }
    }

    //	93c8c814a4_group_8373_Group B
    if( isset( $action[ 'groups' ] ) ) {
        $groups = maybe_unserialize($action['groups']);
        foreach ($groups as $id => $group) {
            foreach ($group as $key => $name) {
                $action[$list_id . '_group_' . $id . '_' . $name] = 1;
            }
        }
    }

    if( isset( $action[ 'double-opt' ] ) ) {
        if ('yes' == $action['double-opt']) {
            $action['double_opt_in'] = 1;
            unset($action['double-opt']);
        }
    }

    return $action;
}
