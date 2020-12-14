<?php

/**
 * @author Rockstar e-Commerce Solutions
 * @copyright  2020 Rockstar e-Commerce Solutions
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

/**
 * Implements install().
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TreggoShippingModule extends CarrierModule
{
    const PREFIX = 'treggoshippingmodule_';

    public $id_carrier;
 
    protected $hooks = array(
        'actionCarrierUpdate',
        'actionOrderStatusUpdate'
    );

    public $carriers = array(
        'Envío On Demand' => 'ondemand',
        'Envío Same Day' => 'sameday',
        'Envío Next Day' => 'nextday',
        'Envío con Redespacho' => 'redespacho'
    );
    
    public function __construct()
    {
        $this->name = 'treggoshippingmodule';
        $this->tab = 'shipping_logistics';
        $this->version = '2.0.8';
        $this->author = 'Rockstar Solutions';
        $this->bootstrap = true;
     
        parent::__construct();
     
        $this->displayName = $this->l('Treggo');
        $this->description = $this->l('Envío rápido con Treggo');
        $this->shippingCostController = $this->getHookController('getOrderShippingCost');
    }

    /**
     * Module install.
     */
    public function install()
    {
        if (parent::install()) {

            $url = 'https://api.treggo.co/1/integrations/prestashop/signup';

            $data = array(
                'email' => Configuration::get('PS_SHOP_EMAIL'),
                'telefono' => Configuration::get('PS_SHOP_PHONE'),
                'nombre' => $this->context->shop->name,
                'store' => array(
                    'nombre' => $this->context->shop->name,
                    'dominio' => $this->context->shop->domain,
                    'id' => $this->context->shop->id_shop_group,
                    'version_plugin' => $this->version,
                    'version_presta' => _PS_VERSION_,
                    'devmode' => _PS_MODE_DEV_
                )
            );

            try {
                $curl = curl_init();
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

            foreach ($this->hooks as $hook) {
                if (!$this->registerHook($hook)) {
                    return  false;
                }
            }

            if (!$this->createCarriers()) {
                return  false;
            }

            $prev_multiplicador = Configuration::get('treggo_multiplicador');
            $prev_etiquetas_a4_pdf = Configuration::get('treggo_etiquetas_a4_pdf');
            $prev_etiquetas_zebra_pdf = Configuration::get('treggo_etiquetas_zebra_pdf');

            $multiplicador = Tools::getValue('treggo_multiplicador', empty($prev_multiplicador) ? 1 : $prev_multiplicador);
            $etiquetas_a4_pdf = Tools::getValue('treggo_etiquetas_a4_pdf', empty($prev_etiquetas_a4_pdf) ? 'on' : $prev_etiquetas_a4_pdf);
            $etiquetas_zebra_pdf = Tools::getValue('treggo_etiquetas_zebra_pdf', empty($prev_etiquetas_zebra_pdf) ? 'on' : $prev_etiquetas_zebra_pdf);

            Configuration::updateValue('treggo_multiplicador', $multiplicador);
            Configuration::updateValue('treggo_etiquetas_a4_pdf', $etiquetas_a4_pdf);
            Configuration::updateValue('treggo_etiquetas_zebra_pdf', $etiquetas_zebra_pdf);

            return true;
        }

        return  false;
    }

    protected function createCarriers()
    {
        foreach ($this->carriers as $name => $code) {
            $carrier = new Carrier();
            $carrier->name = $this->description;
            $carrier->active = true;
            $carrier->deleted = 0;
            $carrier->shipping_handling =  false;
            $carrier->range_behavior = 0;
            $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = $name;
            $carrier->shipping_external = true;
            $carrier->id_zone = 6;
            $carrier->is_module = true;
            $carrier->external_module_name = $this->name;
            $carrier->need_range = true;

            if ($carrier->add()) {
                $groups = Group::getGroups(true);
                foreach ($groups as $group) {
                    Db::getInstance()->insert('carrier_group', array(
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


                Db::getInstance()->insert('carrier_zone', array(
                    'id_carrier' => (int) $carrier->id,
                    'id_zone' => $carrier->id_zone
                ));

                Db::getInstance()->insert('delivery', array(
                    'id_carrier' => $carrier->id,
                    'id_range_price' => (int) $rangePrice->id,
                    'id_range_weight' => null,
                    'id_zone' => $carrier->id_zone,
                    'price' => '0'
                ));

                Db::getInstance()->insert('delivery', array(
                    'id_carrier' => $carrier->id,
                    'id_range_price' => null,
                    'id_range_weight' => (int) $rangeWeight->id,
                    'id_zone' => $carrier->id_zone,
                    'price' => '0'
                ));

                // Assign carrier logo
                copy(dirname(__FILE__) . '/views/img/logo.jpg', _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg');

                Configuration::updateValue(self::PREFIX . $code, $carrier->id);
            }
        }

        return true;
    }

    protected function deleteCarriers()
    {
        foreach ($this->carriers as $name => $code) {
            $carrier_id = Configuration::get(self::PREFIX . $code);
            $carrier = new Carrier($carrier_id);
            $carrier->delete();
            Configuration::deleteByName(self::PREFIX . $code);
        }

        // Old version carrier
        $old_carrier_id = Configuration::get(self::PREFIX . 'treggoshipping');
        if (!empty($old_carrier_id)) {
            $carrier = new Carrier($old_carrier_id);
            $carrier->delete();
            Configuration::deleteByName(self::PREFIX . 'treggoshipping');
        }

        return true;
    }

    public function uninstall()
    {
        if (parent::uninstall()) {
            foreach ($this->hooks as $hook) {
                if (!$this->unregisterHook($hook)) {
                    return  false;
                }
            }

            if (!$this->deleteCarriers()) {
                return  false;
            }

            return true;
        }

        return  false;
    }

    public function getHookController($hook_name)
    {
        require_once(dirname(__FILE__) . '/controllers/hook/' . $hook_name . '.php');
        $controller_name = $this->name . $hook_name . 'Controller';
        $controller = new $controller_name($this, __FILE__, $this->_path);
        return $controller;
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        return $this->shippingCostController->run($params, $shipping_cost);
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    public function hookActionCarrierUpdate($params)
    {
        $old_id_carrier = (int)$params['id_carrier'];
        $new_id_carrier = (int)$params['carrier']->id;

        foreach ($this->carriers as $name => $code) {
            if (Configuration::get(self::PREFIX . $code) == $old_id_carrier) {
                Configuration::updateValue(self::PREFIX . $code, $new_id_carrier);
            }
        }
    }

    public function hookActionOrderStatusUpdate($params)
    {
        $id_order = $params['id_order'];
        $order = new Order((int) $id_order);

        $shipping_methods = $order->getShipping();

        if (!is_array($shipping_methods) || count($shipping_methods) === 0) {
            return;
        }

        $shipping = null;
        $shipping_code = null;
        $shipping_service = null;
        foreach ($shipping_methods as $shipping_method) {
            foreach ($this->carriers as $name => $code) {
                if ($shipping_method['id_carrier'] === Configuration::get(self::PREFIX . $code)) {
                    $shipping = $shipping_method;
                    $shipping_code = $code;
                    $shipping_service = $name;
                }
            }
        }
        
        if (!$shipping) {
            return;
        }

        $new_order_status = $params['newOrderStatus'];
        $state_name = $new_order_status->name;

        $address = new Address((int)($order->id_address_delivery));

        $url = 'https://api.treggo.co/1/integrations/prestashop/notifications';

        $address = new Address($params['cart']->id_address_delivery);
        $state = State::getNameById($address->id_state);
        $id_shop_group = $params['cart']->id_shop_group;
        $id_shop = $params['cart']->id_shop;

        if (!ctype_digit($address->postcode)) {
            $postcode = Tools::substr($address->postcode, 1, -3);
        } else {
            $postcode = $address->postcode;
        }

        $shipment_data = array(
            'email' => Configuration::get('PS_SHOP_EMAIL'),
            'dominio' => $this->context->shop->domain,
            'order' => array(
                'id_shop_group' => $id_shop_group,
                'id_shop' => $id_shop,
                'id_order' => $id_order,
                'reference' => $order->reference,
                'order_status' => $state_name,
                'id_customer' => $address->id_customer,
                'id_country' =>  $address->id_country,
                'id_state' =>  $address->id_state,
                'state' => $state,
                'country' =>  $address->country,
                'alias' =>  $address->alias,
                'company' =>  $address->company,
                'lastname' =>  $address->lastname,
                'firstname' =>  $address->firstname,
                'address1' =>  $address->address1,
                'address2' =>  $address->address2,
                'postcode' =>  $postcode,
                'city' =>  $address->city,
                'phone' =>  $address->phone,
                'phone_mobile' =>  $address->phone_mobile,
                'dni' =>  $address->dni,
                'date' => $order->date_add,
                'carrier_name' => $shipping['carrier_name'],
                'code' => $shipping_code,
                'service' => $shipping_service,
                'shipping_price' => (float) $shipping['shipping_cost_tax_excl'],
                'version_plugin' => $this->version,
                'version_presta' => _PS_VERSION_,
                'devmode' => _PS_MODE_DEV_
            )
        );

        try {
            $curl = curl_init();

            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($shipment_data));
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded',
                'cache-control: no-cache'
            ));
            curl_exec($curl);
            curl_close($curl);
        } catch (\Exception $e) {
            throw new \Exception('Error de comunicación con el servidor: ' . $e->getMessage());
        }
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $multiplicador = strval(Tools::getValue('treggo_multiplicador'));
            $a4 = strval(Tools::getValue('treggo_etiquetas_a4_pdf'));
            $zebra = strval(Tools::getValue('treggo_etiquetas_zebra_pdf'));

            $checkboxValues = ['on', 'off'];

            if (empty($a4)) $a4 = 'off';
            if (empty($zebra)) $zebra = 'off';

            if (
                empty($multiplicador) ||
                !Validate::isFloat($multiplicador) ||
                ((float) $multiplicador < 0) ||
                !in_array($a4, $checkboxValues) ||
                !in_array($zebra, $checkboxValues)
            ) {
                $output .= $this->displayError($this->l('Configuración inválida'));
            } else {
                Configuration::updateValue('treggo_multiplicador', $multiplicador);
                Configuration::updateValue('treggo_etiquetas_a4_pdf', $a4);
                Configuration::updateValue('treggo_etiquetas_zebra_pdf', $zebra);
                Configuration::updateValue('treggo_first_configuration', 'true');
                $output .= $this->displayConfirmation($this->l('Configuración actualizada'));
            }
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $header = null;

        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fieldsForm[0]['form'] = [
            'legend' => [
                'title' => $this->l('Configuración'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Multiplicador'),
                    'name' => 'treggo_multiplicador',
                    'required' => true
                ],
                [
                    'type' => 'checkbox',
                    'label' => $this->l('Opciones de impresión'),
                    'name' => 'treggo_etiquetas',
                    'required' => true,
                    'values' => [
                        'id' => 'id',
                        'name' => 'name',
                        'query' => [
                            [
                                'id' => 'a4_pdf',
                                'name' => 'A4 PDF'
                            ],
                            [
                                'id' => 'zebra_pdf',
                                'name' => 'Zebra PDF'
                            ]
                        ]
                    ],
                    'is_bool' => true
                ]
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                    '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        $prev_multiplicador = Configuration::get('treggo_multiplicador');
        $prev_etiquetas_a4_pdf = Configuration::get('treggo_etiquetas_a4_pdf');
        $prev_etiquetas_zebra_pdf = Configuration::get('treggo_etiquetas_zebra_pdf');

        $helper->fields_value['treggo_multiplicador'] = Tools::getValue('treggo_multiplicador', empty($prev_multiplicador) ? 1 : $prev_multiplicador);
        $helper->fields_value['treggo_etiquetas_a4_pdf'] = Tools::getValue('treggo_etiquetas_a4_pdf', empty($prev_etiquetas_a4_pdf) ? 1 : ($prev_etiquetas_a4_pdf === 'on' ? 1 : 0));
        $helper->fields_value['treggo_etiquetas_zebra_pdf'] = Tools::getValue('treggo_etiquetas_zebra_pdf', empty($prev_etiquetas_zebra_pdf) ? 1 : ($prev_etiquetas_zebra_pdf === 'on' ? 1 : 0));

        $form = $helper->generateForm($fieldsForm);

        $this->context->controller->addCSS($this->_path . 'views/css/style.css', 'all');

        $baseUrl = _PS_BASE_URL_ . __PS_BASE_URI__;

        $header = "
        <div class=\"treggo-container\">
            <a target=\"_blank\" class=\"banner\" href=\"https://treggo.co/\">
                <img class=\"img-fluid\" src=\"{$baseUrl}/modules/treggoshippingmodule/views/img/logo-transp.png\">
            </a>";

        if (Configuration::get('treggo_first_configuration') !== 'true') {
            $email = Configuration::get('PS_SHOP_EMAIL');
            $header .= "<p>Para completar el proceso de alta, podés acceder desde el correo que enviamos a {$email} y terminar el proceso de registro.</p>";
        }

        $header .= "
            <p>
                <b>No podrás utilizar este método de envío hasta que haya un acuerdo comercial sobre las coberturas.</b>
            </p>
            <img class=\"footer\" src=\"{$baseUrl}/modules/treggoshippingmodule/views/img/blue-bar.png\">
        </div>
        ";

        $formArray = explode("\n", $form);

        // Multiplier example
        for ($i = 0; $i < count($formArray); $i++) {
            if (strpos($formArray[$i], 'id="treggo_multiplicador"') !== false) {
                $formArray[$i + 7] .= "                
                    <div class=\"col-lg-3\"></div>
                    <div class=\"col-lg-9\">
                        <p>
                            Ejemplos de uso:
                            <b>0.5</b> = 50% del total -
                            <b>1.21</b> = 21% de sobrecargo
                        </p>
                    </div>
                ";
                break;
            }
        }

        return $header . implode("\n", $formArray);
    }
}
