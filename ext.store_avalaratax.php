<?php

if (defined('PATH_THIRD')) {
    require PATH_THIRD.'store/autoload.php';
}

use Store\Model\Tax;
use Guzzle\Http\Client;
use Guzzle\Http\Exception\BadResponseException;
use AvaTax\Line;
use AvaTax\ATConfig;
use AvaTax\Address;
use AvaTax\GetTaxRequest;
use AvaTax\TaxServiceSoap;
use AvaTax\SeverityLevel;

class Store_avalaratax_ext
{
    const VERSION = '1.0.0';

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

        if (ee()->store) {
            ee()->store->composer->setPsr4("AvaTax\\", __DIR__ . "/AvaTax");
        }
    }

    public function store_order_taxes($order, $taxes)
    {
        if (ee()->extensions->last_call !== false) {
            $taxes = ee()->extensions->last_call;
        }

        if ($this->settings['enabled'] != 'yes') {
            return $taxes;
        }

        $taxes = array();
        $tax = $this->getAvalaraTax($order, 'sales_order');

        if ($tax) {
            $taxes[] = $tax;
        }

        return $taxes;
    }

    public function store_order_complete_end($order)
    {
        $this->getAvalaraTax($order, 'sales_invoice');
    }

    public function getAvalaraTax($order, $action='sales_order')
    {
        if ($this->settings['enabled'] != 'yes') {
            return false;
        }

        // No Zip ? Quit
        if (!$order->shipping_postcode) {
            return false;
        }

        // FREE?
        if (!$order->order_subtotal_inc_discount) {
            return false;
        }

        $tax = Tax::find($this->settings['tax_id']);

        if (!$tax) {
            $this->ee->output->show_user_error(false, array('Store: MISSING TAX ID'));
        }

        $taxSvc = $this->getTaxSvc();
        $getTaxRequest = new GetTaxRequest();

        //Document Level
        $getTaxRequest->setCompanyCode($this->settings['company_code']);
        $getTaxRequest->setDocType(($action == 'sales_invoice') ? 'SalesInvoice' : 'SalesOrder');
        $getTaxRequest->setDocCode('INV' . $order->id);
        $getTaxRequest->setDocDate(date('Y-m-d'));
        $getTaxRequest->setCustomerCode(($order->member_id) ? $order->member_id : 'GUEST' );
        $getTaxRequest->setCommit(($action == 'sales_invoice') ? true : false);

        //Origin Address
        $address01 = new Address();
        $address01->setLine1($this->settings['origin_address1']);
        $address01->setLine2($this->settings['origin_address2']);
        $address01->setCity($this->settings['origin_city']);
        $address01->setRegion($this->settings['origin_state']);
        $address01->setPostalCode($this->settings['origin_postcode']);
        $address01->setCountry($this->settings['origin_country']);
        $getTaxRequest->setOriginAddress($address01);

        //Destination Address
        $address02 = new Address();
        $address02->setLine1($order->shipping_address1);
        $address02->setLine2($order->shipping_address2);
        $address02->setCity($order->shipping_city);
        $address02->setRegion($order->shipping_state);
        $address02->setPostalCode($order->shipping_postcode);
        $address02->setCountry($order->shipping_country);
        $getTaxRequest->setDestinationAddress($address02);


        $lines = array();

        foreach ($order->items as $item) {
            if (ee()->store->tax->is_item_taxable($item, $tax) == false) continue;

            $line = new Line();
            $line->setNo($item->id);
            $line->setItemCode($item->sku);
            $line->setDescription($item->title);
            $line->setQty($item->item_qty);
            $line->setAmount($item->item_subtotal_inc_discount);
            //$line1->setTaxCode("NT");

            $lines[] = $line;
        }

        // No Items? Stop here then
        if (count($lines) == 0) {
            return false;
        }

        // Compile all three lines into an array
        $getTaxRequest->setLines($lines);

        try {
            $getTaxResult = $taxSvc->getTax($getTaxRequest);

            if ($getTaxResult->getResultCode() == SeverityLevel::$Success) {


                $tax->rate = $getTaxResult->getTotalTax() / $order->order_subtotal_inc_discount;
                $tax->enabled = 1;
                $tax->country_code = null;
                $tax->state_code = null;
                $tax->tax_override = true;
                $tax->tax_override_amount = $getTaxResult->getTotalTax();

                return $tax;
            } else {
                foreach ($getTaxResult->getMessages() as $message) {
                  //echo $message->getName() . ": " . $message->getSummary() . "\n";
                }
            }

        } catch (SoapFault $exception) {
            $message = "Exception: ";

            if ($exception) {
                $message .= $exception->faultstring;
            }

            echo $message . "\n";
            echo $taxSvc->__getLastRequest() . "\n";
            echo $taxSvc->__getLastResponse() . "\n ";
        }
    }

    public function settings_form($current)
    {
        ee()->load->helper('form');
        ee()->load->library('table');

        // Are We Testing?
        if (ee()->input->get('action') == 'test_connection') {
            return $this->testConnection();
        }

        $this->mapDefaultSettings($current);

        $vars = array();

        // Enabled?
        $vars['settings']['enabled'] = form_dropdown('enabled', array(
            'yes' => lang('yes'),
            'no' => lang('no'),
        ), $this->settings['enabled']);

        // Test Mode
        $vars['settings']['test_mode'] = form_dropdown('test_mode', array(
            'yes' => lang('yes'),
            'no' => lang('no'),
        ), $this->settings['test_mode'], ' class="avalara_test" ');

        $vars['settings']['account_number'] = form_input('account_number', $this->settings['account_number'], ' class="avalara_test" ');
        $vars['settings']['license_key'] = form_input('license_key', $this->settings['license_key'], ' class="avalara_test" ');
        $vars['settings']['company_code'] = form_input('company_code', $this->settings['company_code'], ' class="avalara_test" ');

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

    private function testConnection()
    {
        $out = array();
        $out['success'] = false;
        $out['test_mode'] =  ee()->input->get('test_mode');
        $out['account_number'] =  ee()->input->get('account_number');
        $out['license_key'] =  ee()->input->get('license_key');
        $out['company_code'] =  ee()->input->get('company_code');
        $out['error'] = '';

        try {
            $taxSvc = $this->getTaxSvc($out);
            $pingResult = $taxSvc->ping('');

            if ($pingResult->getResultCode() == SeverityLevel::$Success) {
                $out['success'] = true;
            } else {
                foreach ($pingResult->Messages() as $messages) {
                    $out['error'] .= $messages->Name() . ': ' . $messages->Summary() . "\n";
                }
            }
        } catch (SoapFault $e) {
            $out['error'] = "Exception: ";

            if ($e) {
                $out['error'] .= $e->faultstring;
            }

            $out['error'] . "\n";
            $out['error'] .= $taxSvc->__getLastRequest() . "\n";
            $out['error'] .= $taxSvc->__getLastResponse() . "\n   ";
        }

        exit(json_encode($out));
    }

    private function getTaxSvc($config=array())
    {
        $settings = $config ? $config : $this->settings;
        $url = 'https://avatax.avalara.net';

        if ($settings['test_mode'] == 'yes') {
            $url = 'https://development.avalara.net';
        }

        $atConfig = new ATConfig('Production', array(
                'url'       => $url,
                'account'   => $settings['account_number'],
                'license'   => $settings['license_key'],
                'trace'     => false, // change to false for development
                'client' => 'DevDemon_Store',
                'name' => self::VERSION
            )
        );

        return new TaxServiceSoap('Production');
    }

    private function mapDefaultSettings($current=false)
    {
        if ($current !== false) $this->settings = $current;

        $defaults = array();
        $defaults['enabled'] = 'yes';
        $defaults['account_number'] = '';
        $defaults['license_key'] = '';
        $defaults['company_code'] = '';
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