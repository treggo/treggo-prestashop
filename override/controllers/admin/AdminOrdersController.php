<?php

class AdminOrdersController extends AdminOrdersControllerCore 
{
    public $toolbar_title;
    public $type = null;
    protected $statuses_array = array();

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'order';
        $this->className = 'Order';
        $this->lang = false;
        $this->addRowAction('view');
        $this->explicitSelect = true;
        $this->allow_export = true;
        $this->deleted = false;

        parent::__construct();
                 
        $this->_select = '
        a.id_currency,
        a.id_order AS id_pdf,
        a.id_order as id_temp,
        CONCAT(LEFT(c.`firstname`, 1), \'. \', c.`lastname`) AS `customer`,
        osl.`name` AS `osname`,
        os.`color`,
        IF((SELECT so.id_order FROM `' . _DB_PREFIX_ . 'orders` so WHERE so.id_customer = a.id_customer AND so.id_order < a.id_order LIMIT 1) > 0, 0, 1) as new,
        country_lang.name as cname,
        IF(a.valid, 1, 0) badge_success';

        $this->_join = '
        LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = a.`id_customer`)
        INNER JOIN `' . _DB_PREFIX_ . 'address` address ON address.id_address = a.id_address_delivery
        INNER JOIN `' . _DB_PREFIX_ . 'country` country ON address.id_country = country.id_country
        INNER JOIN `' . _DB_PREFIX_ . 'country_lang` country_lang ON (country.`id_country` = country_lang.`id_country` AND country_lang.`id_lang` = ' . (int) $this->context->language->id . ')
        LEFT JOIN `' . _DB_PREFIX_ . 'order_state` os ON (os.`id_order_state` = a.`current_state`)
        LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON (os.`id_order_state` = osl.`id_order_state` AND osl.`id_lang` = ' . (int) $this->context->language->id . ')';
        $this->_orderBy = 'id_order';
        $this->_orderWay = 'DESC';
        $this->_use_found_rows = true;

        $statuses = OrderState::getOrderStates((int) $this->context->language->id);
        foreach ($statuses as $status) {
            $this->statuses_array[$status['id_order_state']] = $status['name'];
        }

        $this->fields_list = array(
            'id_order' => array(
                'title' => $this->trans('ID', array(), 'Admin.Global'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs',
            ),
            'reference' => array(
                'title' => $this->trans('Reference', array(), 'Admin.Global'),
            ),
            'new' => array(
                'title' => $this->trans('New client', array(), 'Admin.Orderscustomers.Feature'),
                'align' => 'text-center',
                'type' => 'bool',
                'tmpTableFilter' => true,
                'orderby' => false,
            ),
            'customer' => array(
                'title' => $this->trans('Customer', array(), 'Admin.Global'),
                'havingFilter' => true,
            ),
        );

        if (Configuration::get('PS_B2B_ENABLE')) {
            $this->fields_list = array_merge($this->fields_list, array(
                'company' => array(
                    'title' => $this->trans('Company', array(), 'Admin.Global'),
                    'filter_key' => 'c!company',
                ),
            ));
        }

        $this->fields_list = array_merge($this->fields_list, array(
            'total_paid_tax_incl' => array(
                'title' => $this->trans('Total', array(), 'Admin.Global'),
                'align' => 'text-right',
                'type' => 'price',
                'currency' => true,
                'callback' => 'setOrderCurrency',
                'badge_success' => true,
            ),
            'payment' => array(
                'title' => $this->trans('Payment', array(), 'Admin.Global'),
            ),
            'osname' => array(
                'title' => $this->trans('Status', array(), 'Admin.Global'),
                'type' => 'select',
                'color' => 'color',
                'list' => $this->statuses_array,
                'filter_key' => 'os!id_order_state',
                'filter_type' => 'int',
                'order_key' => 'osname',
            ),
            'date_add' => array(
                'title' => $this->trans('Date', array(), 'Admin.Global'),
                'align' => 'text-right',
                'type' => 'datetime',
                'filter_key' => 'a!date_add',
            ),
            'id_pdf' => array(
                'title' => $this->trans('PDF', array(), 'Admin.Global'),
                'align' => 'text-center',
                'callback' => 'printPDFIcons',
                'orderby' => false,
                'search' => false,
                'remove_onclick' => true,
            ),
        ));

        if (Country::isCurrentlyUsed('country', true)) {
            $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
            SELECT DISTINCT c.id_country, cl.`name`
            FROM `' . _DB_PREFIX_ . 'orders` o
            ' . Shop::addSqlAssociation('orders', 'o') . '
            INNER JOIN `' . _DB_PREFIX_ . 'address` a ON a.id_address = o.id_address_delivery
            INNER JOIN `' . _DB_PREFIX_ . 'country` c ON a.id_country = c.id_country
            INNER JOIN `' . _DB_PREFIX_ . 'country_lang` cl ON (c.`id_country` = cl.`id_country` AND cl.`id_lang` = ' . (int) $this->context->language->id . ')
            ORDER BY cl.name ASC');

            $country_array = array();
            foreach ($result as $row) {
                $country_array[$row['id_country']] = $row['name'];
            }

            $part1 = array_slice($this->fields_list, 0, 3);
            $part2 = array_slice($this->fields_list, 3);
            $part1['cname'] = array(
                'title' => $this->trans('Delivery', array(), 'Admin.Global'),
                'type' => 'select',
                'list' => $country_array,
                'filter_key' => 'country!id_country',
                'filter_type' => 'int',
                'order_key' => 'cname',
            );
            $this->fields_list = array_merge($part1, $part2);
        }

        $this->shopLinkType = 'shop';
        $this->shopShareDatas = Shop::SHARE_ORDER;

        if (Tools::isSubmit('id_order')) {
            // Save context (in order to apply cart rule)
            $order = new Order((int) Tools::getValue('id_order'));
            $this->context->cart = new Cart($order->id_cart);
            $this->context->customer = new Customer($order->id_customer);
        }

        global $cookie;
        $carriers = Carrier::getCarriers($cookie->id_lang, true, false, false, NULL, PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE);

        $print_a4 = Configuration::get('treggo_etiquetas_a4_pdf');
        $print_zebra = Configuration::get('treggo_etiquetas_zebra_pdf');

        if ($print_a4 === 'on' && $print_zebra === 'on') {
            $this->bulk_actions = array(
                'updateOrderStatus' => array('text' => $this->trans('Change Order Status', array(), 'Admin.Orderscustomers.Feature'), 'icon' => 'icon-refresh'),
                'printTagsA4' => array('text' => 'Treggo - Imprimir etiqueta A4',  'icon' => 'icon-print'), // Custom Bulk Action
                'printTagsZebra' => array('text' => 'Tregoo - Imprimir etiqueta Zebra',  'icon' => 'icon-print'), // Custom Bulk Action
            );
        } elseif ($print_a4 === 'on' && $print_zebra === 'off') {
            $this->bulk_actions = array(
                'updateOrderStatus' => array('text' => $this->trans('Change Order Status', array(), 'Admin.Orderscustomers.Feature'), 'icon' => 'icon-refresh'),
                'printTagsA4' => array('text' => 'Treggo - Imprimir etiqueta A4',  'icon' => 'icon-print'), // Custom Bulk Action
            );
        } elseif ($print_a4 === 'off' && $print_zebra === 'on') {
            $this->bulk_actions = array(
                'updateOrderStatus' => array('text' => $this->trans('Change Order Status', array(), 'Admin.Orderscustomers.Feature'), 'icon' => 'icon-refresh'),
                'printTagsZebra' => array('text' => 'Tregoo - Imprimir etiqueta Zebra',  'icon' => 'icon-print'), // Custom Bulk Action
            );
        } else {
            $this->bulk_actions = array(
                'updateOrderStatus' => array('text' => $this->trans('Change Order Status', array(), 'Admin.Orderscustomers.Feature'), 'icon' => 'icon-refresh'),
            );
        }
    }

    public function printTags($type) {
        $orders = null;
        if (is_array($this->boxes) && !empty($this->boxes)){
            foreach ($this->boxes as $id_order){
                $order = new Order((int) $id_order); // Order Object
                $id_carrier = $order->id_carrier;
                $id_lang = $order->id_lang;
                $carrier = new Carrier($id_carrier, $id_lang); // Carrier Object
                $id_order_state = (int)$order->getCurrentState(); // Order tatus id
                $order_status = new OrderState((int)$id_order_state, (int)$order->id_lang); // Order status Object by id_order_state
                $address = new Address((int)($order->id_address_delivery)); // Address Object  
                $state = State::getNameById($address->id_state); // State Name
                $id_shop_group = $order->id_shop_group;
                $id_shop = $order->id_shop;
        
                if (!ctype_digit($address->postcode)) {
                    $postcode = Tools::substr($address->postcode, 1, -3);
                } else {
                    $postcode = $address->postcode;
                }
        
                $orders[] = array(
                    'id_shop_group' => $id_shop_group,
                    'id_shop' => $id_shop,
                    'id_order'=> $id_order,
                    'order_status' => $order_status->name,
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
                    'dni' =>  $address->dni
                );
            }

            $shippment_data = array(
                'email' => Configuration::get('PS_SHOP_EMAIL'),
                'dominio' => $this->context->shop->domain,
                'type' => $type,
                'orders' => $orders
            );
                            
            $url = 'https://api.treggo.co/1/integrations/prestashop/tags';

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

                $filename = 'treggo-etiquetas-' . date('Ymd') . '.pdf';
                header("Content-type: application/pdf");
                header("Content-Disposition: attachment; filename={$filename}");
                echo $result;
    
                // Closing CURL connection
                curl_close($curl);
            } catch (\Exception $e) {
                throw new \Exception('Error de comunicación con el servidor: ' . $e->getMessage());
            } 
        }
    }


    // Print A4 Tags Bulk Action Implementation
    public function processBulkPrintTagsA4(){ 
        $type = 'a4';
        $this->printTags($type);
    }

    // Print Zebra Tags Bulk Action Implementation
    public function processBulkPrintTagsZebra(){ 
        $type = 'zebra';
        $this->printTags($type);
    }

}