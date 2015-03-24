<?php if( !defined('WPINC') ) die;

/** Core class. */
class Leyka {

    /**
     * Plugin version, used for cache-busting of style and script file references.
     * @var string
     */
//    private $_version = LEYKA_VERSION;

    /**
     * Unique identifier for your plugin.
     *
     * Use this value (not the variable name) as the text domain when internationalizing strings of text. It should
     * match the Text Domain file header in the main plugin file.
     * @var string
     */
    private $_plugin_slug = 'leyka';

    /**
     * Instance of this class.
     * @var object
     */
    private static $_instance = null;

    /**
     * Slug of the plugin screen.
     * @var string
     */
    // private $_plugin_screen_hook_suffix = null;

    /**
     * Gateways list.
     * @var array
     */
    private $_gateways = array();

    /** @var bool Set in true if gateways addition already processed. */
    // private $_gateways_added = false;

    /** @var array Of WP_Error instances. */
    private $_form_errors = array();

    /** @var string Gateway URL to process payment data. */
    private $_payment_url = '';

    /** @var array Of key => value pairs of payment form vars to send to the Gateway URL. */
    private $_payment_vars = array();

    /**
     * Template list.
     * @var array
     */
    private $templates = null;

    /** Initialize the plugin by setting localization, filters, and administration functions. */
    private function __construct() {

        if( !get_option('leyka_permalinks_flushed') ) {

            add_action('init', function(){

                flush_rewrite_rules(false);
                update_option('leyka_permalinks_flushed', 1);
            });
        }

        // By default, we'll assume some errors in the payment form, so redirect will get us back to it:
        $this->_payment_form_redirect_url = wp_get_referer();

        // Load public-facing style sheet and JavaScript:
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Post types:
        add_action('init', array($this, 'register_post_types'), 9);

        // User roles and capabilities:
        add_action('init', array($this, 'register_user_capabilities'));

        if( !session_id() )
            add_action('init', 'session_start', -2);

        if(is_admin() && current_user_can('leyka_manage_donations'))
            $this->admin_setup();

        /** Service URLs handler: */
        add_action('parse_request', function($request){
            // Callback URLs are: some-website.org/leyka/service/{gateway_id}/{action_name}/
            // For ex., some-website.org/leyka/service/yandex/check_order/
            $request = $_SERVER['REQUEST_URI']; //$request->request;

            if(stristr($request, 'leyka/service') !== FALSE) { // Leyka service URL
                $request = explode('leyka/service', $_SERVER['REQUEST_URI']);
                $request = explode('/', trim($request[1], '/'));

                // $request[0] - Payment method's ID, $request[1] - service action:
                do_action('leyka_service_call-'.$request[0], $request[1]);
                exit();
            }
        });

        /** Embed campaign URL handler: */
        add_filter('template_include', function($template){

            if(is_main_query() && is_singular(Leyka_Campaign_Management::$post_type) && !empty($_GET['embed'])) {

                $new_template = leyka_get_current_template_data(false, 'embed', true);
                if($new_template && !empty($new_template['file'])) {
                    $template = $new_template['file'];
                }
            }

            return $template;
        }, 100);

        add_action('template_redirect', array($this, 'gateway_redirect_page'), 1, 1);

        $this->apply_formatting_filters(); // Internal formatting filters

//        new Non_existing_class; /** @todo  */

        /** Currency rates auto refreshment: */
        if(leyka_options()->opt('auto_refresh_currency_rates')) {

            if( !wp_next_scheduled('refresh_currencies_rates') )
                wp_schedule_event(time(), 'daily', 'refresh_currencies_rates');

            add_action('refresh_currencies_rates', array($this, 'do_currencies_rates_refresh'));

            // Just in case:
            if( !leyka_options()->opt('currency_rur2usd') || !leyka_options()->opt('currency_rur2eur') )
                $this->do_currency_rates_refresh();
        } else {
            wp_clear_scheduled_hook('refresh_currencies_rates');
        }

        do_action('leyka_initiated');
    }

    public function do_currency_rates_refresh() {

        foreach(leyka_get_actual_currency_rates() as $currency => $rate) {
            update_option('leyka_currency_rur2'.mb_strtolower($currency), $rate);
        }
    }

    /** Return a single instance of this class */
    public static function get_instance() {

        // If the single instance hasn't been set, set it now.
        if( !self::$_instance ) {
            self::$_instance = new self;
        }

        return self::$_instance;
    }

    public function __get($param) {
        switch($param) {
            case 'version': return LEYKA_VERSION;
            case 'plugin_slug': return $this->_plugin_slug;
            case 'payment_url': return $this->_payment_url;
            case 'payment_vars': return $this->_payment_vars;
            default:
                return '';
        }
    }

    public function add_payment_form_error(WP_Error $error) {
        $this->_form_errors[] = $error;
    }

    /** @return bool */
    public function payment_form_has_errors() {
        return count($this->_form_errors) > 0;
    }

    /** @return array Of WP_Error instances, if any. */
    public function get_payment_form_errors() {
        return $this->_form_errors;
    }

    /**
     * Wrapper to work with leyka_errors session var.
     * @param bool $anew
     */
    private function _add_session_errors($anew = false) {
        if(empty($_SESSION['leyka_errors']) || $anew)
            $_SESSION['leyka_errors'] = $this->get_payment_form_errors();
        else
            $_SESSION['leyka_errors'] = array_merge($_SESSION['leyka_errors'], $this->get_payment_form_errors());
    }

    /** @return bool */
    public function has_session_errors() {
        return !empty($_SESSION['leyka_errors']) && count($_SESSION['leyka_errors']);
    }

    /** @return array */
    public function get_session_errors() {
        return empty($_SESSION['leyka_errors']) ? array() : $_SESSION['leyka_errors'];
    }

    /** @return true */
    public function clear_session_errors() {
        return ($_SESSION['leyka_errors'] = array());
    }

    /**
     * Retrieve all available payment/donation statuses.
     *
     * @return array of status_id => status label pairs
     */
    public function get_donation_statuses() {
        return apply_filters('leyka_donation_statuses', array(
            'submitted' => _x('Submitted', '«Submitted» donation status', 'leyka'),
            'funded'    => _x('Funded', '«Completed» donation status', 'leyka'),
            'refunded'  => _x('Refunded', '«Refunded» donation status', 'leyka'),
            'failed'    => _x('Failed', '«Failed» donation status', 'leyka'),
            'trash'     => _x('Trash', '«Deleted» donation status', 'leyka'),
        ));
    }

    /**
     * Retrieve all available campaign target states.
     *
     * @return array of state_id => state label pairs
     */
    public function get_campaign_target_states() {
        return apply_filters('leyka_campaign_target_states', array(
            'no_target'   => _x('No target', 'Campaign state when target is not set', 'leyka'),
            'is_reached'  => _x('Reached', 'Campaign state when target is reached', 'leyka'),
            'in_progress' => _x('In progress', 'Campaign state when target is not reached yet', 'leyka'),
        ));
    }

    /**
     * @return array Of Leyka_Gateway objects.
     */
    public function get_gateways() {
        return $this->_gateways;
    }

    /**
     * @param Leyka_Gateway $gateway
     * @return bool
     */
    public function add_gateway(Leyka_Gateway $gateway) {

        if(empty($this->_gateways[$gateway->id])) {

            $this->_gateways[$gateway->id] = $gateway;
            return true;

        } else
            return false;
    }

    /** Just in case */
    public function remove_gateway($gateway_id) {

        if( !empty($this->_gateways[$gateway_id]) )
            unset($this->_gateways[$gateway_id]);
    }

    /**
     * Fired when the plugin is activated.
     * @param boolean $network_wide True if WPMU superadmin uses "Network Activate" action,
     * false if WPMU is disabled or plugin is activated on an individual blog.
     */
    public static function activate($network_wide) {

        $leyka_last_ver = get_option('leyka_last_ver');
        if( !$leyka_last_ver || (float)$leyka_last_ver < 2.1 ) {

            /** Upgrade options structure in the DB */
            if(get_option('leyka_modules'))
                delete_option('leyka_modules');

            if(get_option('leyka_options_installed'))
                delete_option('leyka_options_installed');

            require_once(LEYKA_PLUGIN_DIR.'inc/leyka-options-meta.php');

            global $options_meta;

            foreach($options_meta as $name => $meta) {

                $option = get_option("leyka_$name");
                if(is_array($option) && isset($option['type']) && isset($option['title'])) // Update option data
                    update_option("leyka_$name", $option['value']);
            }

            // Mostly to initialize gateways' and PM's options before updating them
            //            if( !did_action('leyka_init_actions') )
            //                do_action('leyka_init_actions');

            /** Upgrade gateway and PM options structure in the DB */
            foreach(leyka_get_gateways() as $gateway) {

                /** @var $gateway Leyka_Gateway */
                delete_option("leyka_{$gateway->id}_payment_methods");

                foreach($gateway->get_options_names() as $name) {

                    $option = get_option("leyka_$name");

                    if(is_array($option) && isset($option['type']) && isset($option['title'])) // Update option data
                        update_option("leyka_$name", $option['value']);
                }

                foreach($gateway->get_payment_methods() as $pm) {

                    /** @var $pm Leyka_Payment_Method */
                    foreach($pm->get_pm_options_names() as $name) {

                        $option = get_option("leyka_$name");
                        if(is_array($option) && isset($option['type']) && isset($option['title'])) // Update option data
                            update_option("leyka_$name", $option['value']);
                    }
                }
            }
        }

        /** Set a flag to flush permalinks (needs to be done a bit later, than this activation itself): */
        update_option('leyka_permalinks_flushed', 0);

        update_option('leyka_last_ver', LEYKA_VERSION);
    }

    /**
     * Fired when the plugin is deactivated.
     * @param boolean $network_wide True if WPMU superadmin uses "Network Deactivate" action,
     * false if WPMU is disabled or plugin is deactivated on an individual blog.
     */
    public static function deactivate($network_wide) {

        delete_option('leyka_permalinks_flushed');
    }

    function apply_formatting_filters() {

        add_filter('leyka_the_content', 'wptexturize');
        add_filter('leyka_the_content', 'convert_smilies');
        add_filter('leyka_the_content', 'convert_chars');
        add_filter('leyka_the_content', 'wpautop');
    }

    /** Register and enqueue public-facing style sheet. */
    public function enqueue_styles() {

        wp_enqueue_style($this->_plugin_slug.'-plugin-styles', LEYKA_PLUGIN_BASE_URL.'css/public.css', array(), LEYKA_VERSION);
    }

    /** Register and enqueues public-facing JavaScript files. */
    public function enqueue_scripts() {

        wp_enqueue_script(
            $this->_plugin_slug.'-modal',
            LEYKA_PLUGIN_BASE_URL.'js/jquery.leanModal.min.js', array('jquery'),
            LEYKA_VERSION,
            true
        );

        wp_enqueue_script(
            $this->_plugin_slug.'-plugin-script',
            LEYKA_PLUGIN_BASE_URL.'js/public.js', array('jquery', $this->_plugin_slug.'-modal'),
            LEYKA_VERSION,
            true
        );

        $js_data = apply_filters('leyka_js_localized_strings', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'correct_donation_amount_required' => __('Donation amount must be specified to submit the form', 'leyka'),
            'donation_amount_too_great' => __('Donation amount you entered is too great (maximum %s allowed)', 'leyka'),
            'donation_amount_too_small' => __('Donation amount you entered is too small (minimum %s allowed)', 'leyka'),
            'checkbox_check_required' => __('This checkbox must be checked to submit the form', 'leyka'),
            'amount_incorrect' => __('The amount must be filled with non-zero, non-negative number', 'leyka'),
            'text_required' => __('This field must be filled to submit the form', 'leyka'),
            'email_required' => __('Email must be filled to submit the form', 'leyka'),
            'email_invalid' => __('You have entered an invalid email', 'leyka'),
//            'email_regexp' => '',
        ));

        wp_localize_script($this->_plugin_slug.'-plugin-script', 'leyka', $js_data);
    }

    /**
     * Setup admin for the plugin.
     **/
    public function admin_setup() {

        require_once(LEYKA_PLUGIN_DIR.'inc/leyka-class-options-allocator.php');
        require_once(LEYKA_PLUGIN_DIR.'inc/leyka-render-settings.php');
        require_once(LEYKA_PLUGIN_DIR.'/inc/leyka-admin.php');
        require_once(LEYKA_PLUGIN_DIR.'/inc/leyka-donations-export.php');

        Leyka_Admin_Setup::get_instance();
    }

    /** Register leyka user roles and caps. */
    function register_user_capabilities() {

        /** Create all roles and capabilities: */
        $caps = array(
            'read' => true, 'edit_#base#' => true, 'read_#base#' => true, 'delete_#base#' => true,
            'edit_#base#s' => true, 'edit_others_#base#s' => true, 'publish_#base#s' => true,
            'read_private_#base#s' => true, 'delete_#base#s' => true, 'delete_private_#base#s' => true,
            'delete_published_#base#s' => true, 'delete_others_#base#s' => true,
            'edit_private_#base#s' => true, 'edit_published_#base#s' => true,
            'upload_files' => true, 'unfiltered_html' => true, 'leyka_manage_donations' => true,
        );

        $role = get_role('administrator');
        foreach($caps as $cap => $true) {

            $cap_donation = str_replace('#base#', 'donation', $cap);
            $role->add_cap($cap_donation, true);
            $caps[$cap_donation] = true;

            $cap_campaign = str_replace('#base#', 'campaign', $cap);
            $role->add_cap($cap_campaign, true);
            $caps[$cap_campaign] = true;

            if(stristr($cap, '#base#') !== false)
                unset($caps[$cap]);
        }
        $role->add_cap('leyka_manage_options', true);

        add_role('donations_manager', __('Donations Manager', 'leyka'), $caps);
        add_role('donations_administrator', __('Donations Administrator', 'leyka'), array_merge($caps, array('leyka_manage_options' => true,)));
    }

    /**
     * Register leyka post types.
     */
    function register_post_types(){

        /** Donation CPT: */
        $args = array(
            'label' => __('Donations', 'leyka'),
            'labels' => array(
                'name'          => __('Donations', 'leyka'),
                'singular_name' => __('Donation', 'leyka'),
                'menu_name'     => __('Donations', 'leyka'),
                'all_items'     => __('Donations', 'leyka'),
                'add_new'       => __('New donation', 'leyka'),
                'add_new_item'  => __('Add new donation', 'leyka'),
                'edit_item'     => __('Donation profile', 'leyka'),
                'new_item'      => __('New donation', 'leyka'),
                'view_item'     => __('View donation', 'leyka'),
                'search_items'  => __('Search donation', 'leyka'),
                'not_found'     => __('Donations not found', 'leyka'),
                'not_found_in_trash' => __('Donations not found in Trash', 'leyka')
            ),
            'exclude_from_search' => true,
            'public' => true,
            'show_ui' => true,
            'show_in_nav_menus' => false,
            'show_in_menu' => false,
            'show_in_admin_bar' => false,
            'supports' => false,
            'taxonomies' => array(),
            'has_archive' => false,
            'capability_type' => 'donation',
            'map_meta_cap' => true,
            'rewrite' => array('slug' => 'donation', 'with_front' => false)
        );

        register_post_type(Leyka_Donation_Management::$post_type, $args);

        /** Donation editing messages */
        add_filter('post_updated_messages', array(Leyka_Donation_Management::get_instance(), 'set_admin_messages'));

        /** Campaign CPT: */
        $args = array(
            'labels' => array(
                'name'          => __('Campaigns', 'leyka'),
                'singular_name' => __('Campaign', 'leyka'),
                'menu_name'     => __('Campaigns', 'leyka'),
                'all_items'     => __('All Campaigns', 'leyka'),
                'add_new'       => __('New campaign', 'leyka'),
                'add_new_item'  => __('Add new campaign', 'leyka'),
                'edit_item'     => __('Edit campaign', 'leyka'),
                'new_item'      => __('New campaign', 'leyka'),
                'view_item'     => __('View campaign', 'leyka'),
                'search_items'  => __('Search campaigns', 'leyka'),
                'not_found'     => __('Campaigns not found', 'leyka'),
                'not_found_in_trash' => __('Campaigns not found in Trash', 'leyka')
            ),
            'exclude_from_search' => false,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_nav_menus' => true,
            'show_in_menu' => false,
            'show_in_admin_bar' => true,
            'supports' => array('title', 'editor', 'thumbnail', 'excerpt'),
            'taxonomies' => array(),
            'has_archive' => true,
            'capability_type' => 'campaign',
            'map_meta_cap' => true,
            'rewrite' => array('slug' => 'campaign', 'with_front' => false)
        );

        register_post_type(Leyka_Campaign_Management::$post_type, $args);

        /** Campaign editing messages */
        add_filter('post_updated_messages', array(Leyka_Campaign_Management::get_instance(), 'set_admin_messages'));

        register_post_status('submitted', array(
            'label'                     => _x('Submitted', '«Submitted» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Submitted <span class="count">(%s)</span>',
                'Submitted <span class="count">(%s)</span>',
                'leyka'
            )
        ));

        register_post_status('funded', array(
            'label'                     => _x('Funded', '«Completed» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Funded <span class="count">(%s)</span>',
                'Funded <span class="count">(%s)</span>',
                'leyka'
            )
        ));

        register_post_status('refunded', array(
            'label'                     => _x('Refunded', '«Refunded» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Refunded <span class="count">(%s)</span>',
                'Refunded <span class="count">(%s)</span>',
                'leyka'
            )
        ));

        register_post_status('failed', array(
            'label'                     => _x('Failed', '«Failed» donation status', 'leyka'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Failed <span class="count">(%s)</span>',
                'Failed <span class="count">(%s)</span>',
                'leyka'
            )
        ));

        do_action('leyka_cpt_registered');
    }

    /**
     * Payment form submissions.
     */
    public function gateway_redirect_page() {

        if(stristr($_SERVER['REQUEST_URI'], 'leyka-process-donation')) {

            if(empty($_POST)) {
                wp_redirect(site_url());
                exit();
            }

            do_action('leyka_init_gateway_redirect_page');

            $this->do_payment_form_submission();

            if($this->payment_form_has_errors() || !$this->_payment_url) {

                $this->_add_session_errors(); // Error handling

                $referer = wp_get_referer();
                if(strstr($referer, '#') !== false) {
                    $referer = reset(explode('#', $referer));
                }

                wp_redirect($referer.'#leyka-submit-errors');
            } else {

                header('HTTP/1.1 200 OK');

                require_once(LEYKA_PLUGIN_DIR.'templates/service/leyka-gateway-redirect-page.php');
                exit();
            }
        }
    } // template_redirect

    public function do_payment_form_submission() {

        $this->clear_session_errors(); // Clear all previous submits errors, if there are some

        if( !wp_verify_nonce($_REQUEST['_wpnonce'], 'leyka_payment_form') ) {

            $error = new WP_Error('wrong_form_submission', __('Wrong nonce in submitted form data', 'leyka'));
            $this->add_payment_form_error($error);
        }

        $pm = explode('-', $_POST['leyka_payment_method']);
        if( !$pm || count($pm) < 2 ) {

            $error = new WP_Error('wrong_gateway_pm_data', __('Wrong gateway or/and payment method in submitted form data', 'leyka'));
            $this->add_payment_form_error($error);
        }

        if($this->payment_form_has_errors())
            return;

        $donation_id = $this->log_submission();

        /** @todo We may want to replace whole $_POST with some specially created array */
        do_action('leyka_payment_form_submission-'.$pm[0], $pm[0], implode('-', array_slice($pm, 1)), $donation_id, $_POST);

        $this->_payment_vars = apply_filters('leyka_submission_form_data-'.$pm[0], $this->_payment_vars, $pm[1], $donation_id);

        $this->_payment_url = apply_filters('leyka_submission_redirect_url-'.$pm[0], $this->_payment_url, $pm[1]);

        if($this->payment_form_has_errors()) // No logging needed if submit attempt have failed
            wp_delete_post($donation_id, true);
    }

    /** Save a base submission info and return new donation ID, so gateway can add it's specific data to the logs. */
    public function log_submission() {

        add_action('save_post', array($this, 'finalize_log_submission'), 2, 2);

        $campaign = get_post((int)$_POST['leyka_campaign_id']);
        $purpose_text = get_post_meta($campaign->ID, 'payment_title', true);
        $purpose_text = empty($purpose_text) && $campaign->post_title ? $campaign->post_title : $purpose_text;

        $pm_data = leyka_pf_get_payment_method_value();
        $donation_id = Leyka_Donation::add(apply_filters('leyka_new_donation_data', array(
            'purpose_text' => $purpose_text,
        )));

        if(is_wp_error($donation_id))
            /** @todo Modify this method so it can take any WP_Error as a param, then call it here: */
            return false;
        else {

            do_action('leyka_log_donation-'.$pm_data['gateway_id'], $donation_id);
            return $donation_id;
        }
    }

    /**
     * A save_post hook wrapper method. It must be used by gateways to add their specific data
     * to the donation in DB while it's saving.
     *
     * @param $donation_id integer
     * @param $donation WP_Post
     */
    public function finalize_log_submission($donation_id, WP_Post $donation) {
        if($donation->post_type != Leyka_Donation_Management::$post_type)
            return;

        do_action('leyka_logging_new_donation', $donation_id, $donation);
    }

    /**
     * Templates manipulations.
     *
     * @param $is_service boolean True if templates is of service group, false otherwise.
     * @return array Template files.
     **/
    public function get_templates($is_service = false) {

        if(empty($this->templates)) {

            $this->templates = glob(STYLESHEETPATH.'/leyka-template-*.php');
            if($this->templates === false || empty($this->templates)) { // If global hits an error, it returns false

                // Let's search in own folder:
                $this->templates = glob(
                    LEYKA_PLUGIN_DIR.'templates'.($is_service ? '/service' : '').'/leyka-template-*.php'
                );

                if($this->templates === false) {
                    $this->templates = array();
                }
            }

            $this->templates = array_map(array($this, 'get_template_data'), $this->templates);
        }

        return (array)$this->templates;
    }

    public function get_template_data($file) {

        $headers = array(
            'name' => 'Leyka Template',
            'description' => 'Description',
        );

        $data = get_file_data($file, $headers);
        $data['file'] = $file;
        $data['basename'] = basename($file);
        $id = explode('-', str_replace('.php', '', $data['basename']));
        $data['id'] = end($id); // Otherwise error appears in php 5.4.x

        if(empty($data['name'])) {
            $data['name'] = $data['basename'];
        }

        return $data;
    }

    public function get_template($basename, $is_service = false) {

        $templates = $this->get_templates($is_service);
        if( !$templates )
            return false;

        $active = '';
        foreach($templates as $template) {

            $cur_basename = explode('-', str_replace('.php', '', $template['basename']));
            $cur_basename = end($cur_basename); // Otherwise error appears in PHP 5.4.x
            if($cur_basename == $basename) {
                $active = $template;
                break;
            }
        }

        return $active;
    }

} // Leyka class end

// Shorthands for singletons instances:
/**
 * @return Leyka Core object
 */
function leyka() {
    return Leyka::get_instance();
}

/** Orphan strings to localize */
__('Radios', 'leyka');
__('Radio options for each payment method', 'leyka');
__('Toggles', 'leyka');
__('Toggled options for each payment method', 'leyka');
__('single', 'leyka');
__('rebill', 'leyka');
__('correction', 'leyka');
__('The donations management system for your WP site', 'leyka');
__('Lev Zvyagincev aka Ahaenor', 'leyka');