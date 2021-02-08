<?php

class AdminOrdersController extends AdminOrdersControllerCore
{
    public function __construct()
    {
        parent::__construct();

        if (Configuration::get('treggo_etiquetas_a4_pdf') === 'on') {
            $this->bulk_actions['printTagsA4'] = array('text' => 'Treggo - Imprimir etiqueta A4', 'icon' => 'icon-print');
        }

        if (Configuration::get('treggo_etiquetas_zebra_pdf') === 'on') {
            $this->bulk_actions['printTagsZebra'] = array('text' => 'Tregoo - Imprimir etiqueta Zebra', 'icon' => 'icon-print');
        }
    }
 
    public function get_endpoint()
    {
        $country_id = Configuration::get('PS_COUNTRY_DEFAULT');
        $country = new Country($country_id);
        return 'https://api.' . strtolower($country->iso_code) . '.treggo.co/1/integrations/prestashop';
    }

    public function printTags($type) {
        $module = Module::getInstanceByName('treggoshippingmodule');
        $orders = array();
        if (is_array($this->boxes) && !empty($this->boxes)) {
            foreach ($this->boxes as $id_order) {
                $order = new Order((int) $id_order);
                $shipping_methods = $order->getShipping();

                if (!is_array($shipping_methods) || count($shipping_methods) === 0) {
                    return;
                }

                $shipping = null;
                $shipping_code = null;
                $shipping_service = null;
                foreach ($shipping_methods as $shipping_method) {
                    foreach ($module->carriers as $name => $code) {
                        if ($shipping_method['id_carrier'] === Configuration::get('treggoshippingmodule_' . $code)) {
                            $shipping = $shipping_method;
                            $shipping_code = $code;
                            $shipping_service = $name;
                        }
                    }
                }

                $id_order_state = (int)$order->getCurrentState();
                $order_status = new OrderState((int) $id_order_state, (int) $order->id_lang);
                $address = new Address((int) $order->id_address_delivery);
                $state = State::getNameById($address->id_state);
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
                    'id_order' => $id_order,
                    'reference' => $order->reference,
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
                    'dni' =>  $address->dni,
                    'date' => $order->date_add,
                    'carrier_name' => $shipping['carrier_name'],
                    'code' => $shipping_code,
                    'service' => $shipping_service,
                    'shipping_price' => (float) $shipping['shipping_cost_tax_excl'],
                    'version_plugin' => $module->version,
                    'version_presta' => _PS_VERSION_,
                    'devmode' => _PS_MODE_DEV_
                );
            }

            $shipment_data = array(
                'email' => Configuration::get('PS_SHOP_EMAIL'),
                'dominio' => $this->context->shop->domain,
                'type' => $type,
                'orders' => $orders
            );

            $url = $this->get_endpoint() . '/tags';

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

                $result = curl_exec($curl);
                curl_close($curl);

                $filename = 'treggo-etiquetas-' . date('Ymd') . '.pdf';
                header("Content-type: application/pdf");
                header("Content-Disposition: attachment; filename={$filename}");
                echo $result;
            } catch (\Exception $e) {
                throw new \Exception('Error de comunicación con el servidor: ' . $e->getMessage());
            }
        }
    }

    public function processBulkPrintTagsA4()
    { 
        $this->printTags('a4');
    }

    public function processBulkPrintTagsZebra()
    { 
        $this->printTags('zebra');
    }
}
