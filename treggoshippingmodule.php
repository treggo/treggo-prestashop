<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TreggoShippingModule extends CarrierModule 
{
    const PREFIX = 'treggoshippingmodule_';
 
    protected $_hooks = array(
        'actionCarrierUpdate', // For control change of the carrier's ID (id_carrier), the module must use the updateCarrier hook.
        'actionValidateOrder'  // After an order has been validated. Doesn’t necessarily have to be paid.
    );
     
    protected $_carriers = array(
    //"Public carrier name" => "technical name",
        'Treggo carrier' => 'treggoshipping',
    );
    
    public function __construct()
    {
        $this->name = 'treggoshippingmodule';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Rockstar Solutions';
        $this->bootstrap = TRUE;
     
        parent::__construct();
     
        $this->displayName = $this->l('Treggo');
        $this->description = $this->l('Envío rápido con Treggo.');
    }

    public function install()
    {
        if (parent::install()) {

            $url = 'https://api.treggo.co/1/integrations/prestashop/signup';

            $data = array(
                'email' => Configuration::get('PS_SHOP_EMAIL'), 
                'telefono' => Configuration::get('PS_SHOP_PHONE') , 
                'nombre' => $this->context->shop->name, 
                'store' => array(
                    'nombre' => $this->context->shop->name,
                    'dominio' => $this->context->shop->domain,
                    'id' => $this->context->shop->id_shop_group
                )
            );

            try {
                // Initiating CURL library instance
                $curl = curl_init();
        
                // Setting CURL options... 
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/x-www-form-urlencoded',
                    'cache-control: no-cache'
                ));
        
                // Executing CURL request and parsing it from JSON to a PHP array
                $result = curl_exec($curl);
                $result = json_decode($result);

        
                // Closing CURL connection
                curl_close($curl);
            
            } catch (\Exception $e) {
                throw new \Exception('Error de comunicación con el servidor: ' . $e->getMessage()); 
            }

            foreach ($this->_hooks as $hook) {
                if (!$this->registerHook($hook)) {
                    return FALSE;
                }
            }

            if (!$this->createCarriers()) { //function for creating new carrier
                return FALSE;
            }
    
            return TRUE;
        }
    
        return FALSE;
    }

    protected function createCarriers()
    {
        foreach ($this->_carriers as $key => $value) {
            //Create new carrier
            $carrier = new Carrier();
            $carrier->name = $key;
            $carrier->active = TRUE;
            $carrier->deleted = 0;
            $carrier->shipping_handling = FALSE;
            $carrier->range_behavior = 0;
            $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = $key;
            $carrier->shipping_external = TRUE;
            $carrier->is_module = TRUE;
            $carrier->external_module_name = $this->name;
            $carrier->need_range = TRUE;
    
            if ($carrier->add()) {
                $groups = Group::getGroups(true);
                foreach ($groups as $group) {
                    Db::getInstance()->insert(_DB_PREFIX_ . 'carrier_group', array(
                        'id_carrier' => (int) $carrier->id,
                        'id_group' => (int) $group['id_group']
                    ));
                }
    
                $rangePrice = new RangePrice();
                $rangePrice->id_carrier = $carrier->id;
                $rangePrice->delimiter1 = '0';
                $rangePrice->delimiter2 = '1000000';
                $rangePrice->add();
                
                $rangeWeight = new RangeWeight();
                $rangeWeight->id_carrier = $carrier->id;
                $rangeWeight->delimiter1 = '0';
                $rangeWeight->delimiter2 = '1000000';
                $rangeWeight->add();
    
                $zones = Zone::getZones(true);
                foreach ($zones as $z) {
                    Db::getInstance()->insert(_DB_PREFIX_ . 'carrier_zone', array(
                        'id_carrier' => (int) $carrier->id, 
                        'id_zone' => (int) $z['id_zone']
                    ));

                    Db::getInstance()->insert(_DB_PREFIX_ . 'delivery', array(
                        'id_carrier' => $carrier->id, 
                        'id_range_price' => (int) $rangePrice->id, 
                        'id_range_weight' => NULL, 
                        'id_zone' => (int) $z['id_zone'], 
                        'price' => '0'
                    ));

                    Db::getInstance()->insert(_DB_PREFIX_ . 'delivery', array(
                        'id_carrier' => $carrier->id, 
                        'id_range_price' => NULL, 
                        'id_range_weight' => (int) $rangeWeight->id, 
                        'id_zone' => (int) $z['id_zone'], 
                        'price' => '0'
                    ));
                }
    
                copy(dirname(__FILE__) . '/logo.jpg', _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg'); //assign carrier logo
    
                Configuration::updateValue(self::PREFIX . $value, $carrier->id);
                Configuration::updateValue(self::PREFIX . $value . '_reference', $carrier->id);
            }
        }
    
        return TRUE;
    }

    protected function deleteCarriers()
    {
        foreach ($this->_carriers as $value) {
            $tmp_carrier_id = Configuration::get(self::PREFIX . $value);
            $carrier = new Carrier($tmp_carrier_id);
            $carrier->delete();
        }
    
        return TRUE;
    }
  
    public function uninstall()
    {
        if (parent::uninstall()) {
            foreach ($this->_hooks as $hook) {
                if (!$this->unregisterHook($hook)) {
                    return FALSE;
                }
            }
    
            if (!$this->deleteCarriers()) {
                return FALSE;
            }
    
            return TRUE;
        }
    
        return FALSE;
    }

    // Build path to controller

	public function getHookController($hook_name)
	{
		require_once(dirname(__FILE__).'/controllers/hook/'. $hook_name.'.php');
		$controller_name = $this->name.$hook_name.'Controller';
		$controller = new $controller_name($this, __FILE__, $this->_path);
		return $controller;
	}

    // Create a controller for shipping cost calculation
    public function getOrderShippingCost($params, $shipping_cost)
    {      
        $controller = $this->getHookController('getOrderShippingCost');
        return $controller->run($params, $shipping_cost);
    }
    
    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    public function hookActionCarrierUpdate($params)
    {
        if ($params['carrier']->id_reference == Configuration::get(self::PREFIX . 'swipbox_reference')) {
            Configuration::updateValue(self::PREFIX . 'swipbox', $params['carrier']->id);
        }
    }

    public function hookActionValidateOrder($params)
    {	

        $url = 'https://api.treggo.co/1/integrations/prestashop/notifications';

        $address = new Address($params['cart']->id_address_delivery);
        $order = $params['order'];
        $id_order = (int)$order->id;
        $shippment_data = array(
            'email' => Configuration::get('PS_SHOP_EMAIL'), 
            'dominio' => $this->context->shop->domain,
            'order' => array(
                'id_order'=> $id_order,
                'id_customer' => $address->id_customer,
                'id_country' =>  $address->id_country,
                'id_state' =>  $address->id_state,
                'country' =>  $address->country,
                'alias' =>  $address->alias,
                'company' =>  $address->company,
                'lastname' =>  $address->lastname,
                'firstname' =>  $address->firstname,
                'address1' =>  $address->address1,
                'address2' =>  $address->address2,
                'postcode' =>  $address->postcode,
                'city' =>  $address->city,
                'phone' =>  $address->phone,
                'phone_mobile' =>  $address->phone_mobile,
                'dni' =>  $address->dni
            )
        );

        try {
            // Initiating CURL library instance
            $curl = curl_init();
    
            // Setting CURL options... 
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($shippment_data));
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded',
                'cache-control: no-cache'
            ));
    
            // Executing CURL request and parsing it from JSON to a PHP array
            $result = curl_exec($curl);
            $result = json_decode($result);

    
            // Closing CURL connection
            curl_close($curl);
        
        } catch (\Exception $e) {
            throw new \Exception('Error de comunicación con el servidor: ' . $e->getMessage()); 
        }
    
    }

}
