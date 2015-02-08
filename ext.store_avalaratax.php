<?php

if (defined('PATH_THIRD')) {
    require PATH_THIRD.'store/autoload.php';
}

use Store\Model\Tax;

class Store_avalaratax_ext
{
    const VERSION = '1.0.3';

    public $name = 'Store Avalara Tax';
    public $version = self::VERSION;
    public $description = 'Provides Avalara Tax integration for Expresso Store';
    public $settings_exist = 'y';
    public $docs_url = 'https://www.devdemon.com/docs';
    public $settings = array();
    public $hooks    = array('store_order_taxes', 'store_order_complete_end');

    public $live_endpoint = 'https://avatax.avalara.net/1.0/';
    public $test_endpoint = 'https://development.avalara.net/1.0/';

    public function __construct($settings = array())
    {
        $this->settings = $settings;

        $this->mapDefaultSettings($settings);
    }

    public function store_order_taxes($order, $taxes)
    {
        if (ee()->extensions->last_call !== false) {
            $taxes = ee()->extensions->last_call;
        }

        return $taxes;
    }

    public function store_order_complete_end($order, $status, $history, $member_id, $message)
    {

    }

    public function settings_form($current)
    {
        ee()->load->helper('form');
        ee()->load->library('table');

        $this->mapDefaultSettings($current);

        $vars = array();

        // Test Mode
        $vars['settings']['enabled'] = form_dropdown('enabled', array(
            'yes' => lang('yes'),
            'no' => lang('no'),
        ), $this->settings['enabled']);

        // Test Mode
        $vars['settings']['test_mode'] = form_dropdown('test_mode', array(
            'yes' => lang('yes'),
            'no' => lang('no'),
        ), $this->settings['test_mode']);

        $vars['settings']['account_number'] = form_input('account_number', $this->settings['account_number']);
        $vars['settings']['license_key'] = form_input('license_key', $this->settings['license_key']);
        $vars['settings']['customer_code'] = form_input('customer_code', $this->settings['customer_code']);

        // Tax ID
        $items = array('' => 'Select Tax');
        foreach (Tax::where('site_id', config_item('site_id'))->get() as $tax) {
            $items[$tax->id] = $tax->name;
        }

        $vars['settings']['tax_id'] = form_dropdown('tax_id', $items, $this->settings['tax_id']);

        // Origin Address
        $vars['settings']['origin_address1'] = form_input('origin_address1', $this->settings['origin_address1']);
        $vars['settings']['origin_address2'] = form_input('origin_address2', $this->settings['origin_address2']);
        $vars['settings']['origin_city'] = form_input('origin_city', $this->settings['origin_city']);
        $vars['settings']['origin_state'] = form_input('origin_state', $this->settings['origin_state']);
        $vars['settings']['origin_postcode'] = form_input('origin_postcode', $this->settings['origin_postcode']);
        $vars['settings']['origin_country'] = form_input('origin_country', $this->settings['origin_country']);

        return ee()->load->view('settings', $vars, true);
    }

    public function save_settings()
    {
        unset($_POST['submit']);

        ee()->db->set('settings', serialize($_POST));
        ee()->db->where('class', __CLASS__);
        ee()->db->update('extensions');

        ee()->session->set_flashdata(
            'message_success',
            lang('preferences_updated')
        );
    }

    private function mapDefaultSettings($current=false)
    {
        if ($current !== false) $this->settings = $current;

        $defaults = array();
        $defaults['enabled'] = 'yes';
        $defaults['account_number'] = '';
        $defaults['license_key'] = '';
        $defaults['customer_code'] = '';
        $defaults['test_mode'] = 'yes';
        $defaults['tax_id'] = '';
        $defaults['origin_address1'] = '';
        $defaults['origin_address2'] = '';
        $defaults['origin_city'] = '';
        $defaults['origin_state'] = '';
        $defaults['origin_postcode'] = '';
        $defaults['origin_country'] = 'US';

        $this->settings = array_merge($defaults, $this->settings);
    }

    /**
     * Called by ExpressionEngine when the user activates the extension.
     *
     * @access      public
     * @return      void
     **/
    public function activate_extension()
    {
        foreach ($this->hooks as $hook) {
             $data = array( 'class'     =>  __CLASS__,
                            'method'    =>  $hook,
                            'hook'      =>  $hook,
                            'settings'  =>  serialize($this->settings),
                            'priority'  =>  10,
                            'version'   =>  $this->version,
                            'enabled'   =>  'y'
                );

            // insert in database
            ee()->db->insert('exp_extensions', $data);
        }
    }

    /**
     * Called by ExpressionEngine updates the extension
     *
     * @access public
     * @return void
     **/
    public function update_extension($current = '')
    {
        if ($current == $this->version) return false;

        $settings = array();

        //----------------------------------------
        // Get all existing hooks
        //----------------------------------------
        $dbexts = array();
        $query = ee()->db->select('*')->from('exp_extensions')->where('class', __CLASS__)->get();

        foreach ($query->result() as $row) {
            $dbexts[$row->hook] = $row;
            if ($row->settings) $settings = unserialize($row->settings);
        }

        //----------------------------------------
        // Add new hooks
        //----------------------------------------
        foreach ($this->hooks as $hook) {
            if (isset($dbexts[$hook]) === true) continue;

            $data = array(
                'class'     =>  __CLASS__,
                'method'    =>  $hook,
                'hook'      =>  $hook,
                'settings'  =>  serialize($settings),
                'priority'  =>  100,
                'version'   =>  $this->version,
                'enabled'   =>  'y'
            );

            // insert in database
            ee()->db->insert('exp_extensions', $data);
        }

        //----------------------------------------
        // Delete old hooks
        //----------------------------------------
        foreach ($dbexts as $hook => $ext) {
            if (in_array($hook, $this->hooks) === true) continue;

            ee()->db->where('hook', $hook);
            ee()->db->where('class', __CLASS__);
            ee()->db->delete('exp_extensions');
        }

        // Update the version number for all remaining hooks
        ee()->db->where('class', __CLASS__)->update('extensions', array('version' => $this->version));
    }

    /**
     * Called by ExpressionEngine when the user disables the extension.
     *
     * @access      public
     * @return      void
     **/
    public function disable_extension()
    {
        ee()->db->where('class', __CLASS__);
        ee()->db->delete('exp_extensions');
    }
}