<?php if( !defined('WPINC') ) die;
/**
 * Leyka_Quittance_Gateway class
 */

class Leyka_Quittance_Gateway extends Leyka_Gateway {

    protected static $_instance; // Gateway is always a singleton

    /** There are no gateway options */
    protected function _set_options_defaults() {

        if($this->_options) // Create Gateway options, if needed
            return;

        $this->_options = array(
            'quittance_redirect_page' => array(
                'type' => 'select',
                'default' => leyka_get_default_success_page(),
                'title' => __('Page to redirect a donor after a donation', 'leyka'),
                'description' => __('Select a page for donor to redirect to after he has acquired a quittance.', 'leyka'),
                'required' => 0, // 1 if field is required, 0 otherwise
                'placeholder' => '', // For text fields
                'length' => '', // For text fields
                'list_entries' => leyka_get_pages_list(),
                'validation_rules' => array(), // List of regexp?..
            ),
        );
    }
    
    protected function _set_gateway_attributes() {

        $this->_id = 'quittance';
        $this->_title = __('Quittances', 'leyka');
    }

    protected function _initialize_pm_list() {

        // Instantiate and save each of PM objects, if needed:
        if(empty($this->_payment_methods['bank_order'])) {
            $this->_payment_methods['bank_order'] = new Leyka_Bank_Order();
//            $this->_payment_methods['bank_order']->save_settings();
        }
    }

    public function process_form($gateway_id, $pm_id, $donation_id, $form_data) {

        // Localize a quittance first:
        $res = load_textdomain('leyka', LEYKA_PLUGIN_DIR.'lang/leyka-'.get_locale().'.mo');

        header('HTTP/1.1 200 OK');
        header('Content-Type: text/html; charset=utf-8');

        $campaign = new Leyka_Campaign($form_data['leyka_campaign_id']);
        $quittance_html = str_replace(
            array(
                '#BACK_TO_DONATION_FORM_TEXT#',
                '#PRINT_THE_QUITTANCE_TEXT#',
                '#QUITTANCE_RECEIVED_TEXT#',
                '#SUCCESS_URL#',
                '#PAYMENT_COMMENT#',
                '#PAYER_NAME#',
                '#RECEIVER_NAME#',
                '#SUM#',
                '#INN#',
                '#KPP#',
                '#ACC#',
                '#RECEIVER_BANK_NAME#',
                '#BIC#',
                '#CORR#',
            ),
            array( // Form field values
                __('Return to the donation form', 'leyka'),
                __('Print the quittance', 'leyka'),
                __("OK, I've received the quittance", 'leyka'),
                get_permalink(leyka_options()->opt('quittance_redirect_page')),
                $campaign->payment_title,
                $form_data['leyka_donor_name'],
                leyka_options()->opt('org_full_name'),
                (int)$form_data['leyka_donation_amount'],
                leyka_options()->opt('org_inn'),
                leyka_options()->opt('org_kpp'),
                leyka_options()->opt('org_bank_account'),
                leyka_options()->opt('org_bank_name'),
                leyka_options()->opt('org_bank_bic'),
                leyka_options()->opt('org_bank_corr_account'),
            ),
            $this->_payment_methods[$pm_id]->get_quittance_html()
        );

        for($i=0; $i<10; $i++) {
            $quittance_html = str_replace("#INN_$i#", substr(leyka_options()->opt('org_inn'), $i, 1), $quittance_html);
        }
        for($i=0; $i<20; $i++) {
            $quittance_html = str_replace(
                "#ACC_$i#", substr(leyka_options()->opt('org_bank_account'), $i, 1), $quittance_html
            );
        }
        for($i=0; $i<9; $i++) {
            $quittance_html = str_replace(
                "#BIC_$i#", substr(leyka_options()->opt('org_bank_bic'), $i, 1), $quittance_html
            );
        }
        for($i=0; $i<20; $i++) {
            $quittance_html = str_replace(
                "#CORR_$i#", substr(leyka_options()->opt('org_bank_corr_account'), $i, 1), $quittance_html
            );
        }

        die($quittance_html);
    }

    /** Quittance don't use any specific redirects, so this method is empty. */
    public function submission_redirect_url($current_url, $pm_id) {

        return $current_url;
    }
    
    /** Quittance don't have some form data to send to the gateway site */
    public function submission_form_data($form_data_vars, $pm_id, $donation_id) {     
                
        return $form_data_vars;
    }

    /** Quittance don't have any of gateway service calls */
    public function _handle_service_calls($call_type = '') {}

    public function get_gateway_response_formatted(Leyka_Donation $donation) {
        return array();
    }

    /** Quittance don't use any specific fields, so this method is empty. */
    public function log_gateway_fields($donation_id) {
        
    }
}

class Leyka_Bank_Order extends Leyka_Payment_Method {

    private $_quittance_html = '';

    /** @var $_instance Leyka_Yandex_Card */
    protected static $_instance = null;

    final protected function __clone() {}

    public final static function get_instance() {

        if(null === static::$_instance) {
            static::$_instance = new static();
        }

        return static::$_instance;
    }

    public function __construct(array $params = array()) {

        if(static::$_instance) /** We can't make a public __construct() to private */ {
            return static::$_instance;
        }

        $this->initialize_pm_options();
        
        /** @todo For now, we're taking quittance template HTML from static file. We can also store it in the DB (PM's options) to let user edit it easily in Leyka settings. */
        $this->_quittance_html = file_get_contents(LEYKA_PLUGIN_DIR.'gateways/quittance/bank_order.html');

        $this->_id = empty($params['id']) ? 'bank_order' : $params['id'];

        $this->_gateway_id = 'quittance';

        $this->_active = isset($params['active']) ? $params['active'] : true;

        $this->_label = empty($params['label']) ? __('Bank order quittance', 'leyka') : $params['label'];

        $this->_description = empty($params['desc']) ?
            leyka_options()->opt_safe('bank_order_description') : $params['desc'];

        $this->_support_global_fields = isset($params['has_global_fields']) ? $params['has_global_fields'] : true;

        $this->_custom_fields = empty($params['custom_fields']) ? array() : (array)$params['custom_fields'];

        $this->_icons = apply_filters('leyka_icons_'.$this->_gateway_id.'_'.$this->_id, array(
            LEYKA_PLUGIN_BASE_URL.'gateways/quittance/icons/sber_s.png',
        ));

        $this->_submit_label = empty($params['submit_label']) ?
            __('Get bank order quittance', 'leyka') : $params['submit_label'];

        $this->_supported_currencies = empty($params['currencies']) ? array('rur') : $params['currencies'];

        $this->_default_currency = empty($params['default_currency']) ? 'rur' : $params['default_currency'];

        static::$_instance = $this;

        return static::$_instance;
    }
    
    protected function _set_pm_options_defaults() {

        if($this->_options)
            return;

        $this->_options = array(
            'bank_order_description' => array(
                'type' => 'html',
                'default' => __('Bank order payment allows you to make a donation through any bank. You can print out a bank order paper and bring it to the bank to make a payment.', 'leyka'),
                'title' => __('Bank order payment description', 'leyka'),
                'description' => __('Please, enter Bank order description that will be shown to the donor when this payment method will be selected to make a donation.', 'leyka'),
                'required' => 0,
                'validation_rules' => array(),
            ),
        );
    }

    function get_quittance_html() {
        return $this->_quittance_html;
    }
}

function leyka_add_gateway_quittance() { // Use named function to leave a possibility to remove/replace it on the hook
    leyka()->add_gateway(Leyka_Quittance_Gateway::get_instance());
}
add_action('leyka_init_actions', 'leyka_add_gateway_quittance', 35);