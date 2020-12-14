<?php

/**
 * @author Rockstar e-Commerce Solutions
 * @copyright  2020 Rockstar e-Commerce Solutions
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

/**
 * Implements getDeliveryService().
 */
class TreggoShippingModuleGetOrderShippingCostController
{
    private $cache = [];

    public function __construct($module, $file, $path)
    {
        $this->file = $file;
        $this->module = $module;
        $this->context = Context::getContext();
        $this->_path = $path;
    }

    /**
     * Get rates for shipping.
     */
    public function getDeliveryService()
    {
        $price = false;

        $url = 'https://api.treggo.co/1/integrations/prestashop/rate';

        if (!ctype_digit($this->postcode)) {
            $postcode = Tools::substr($this->postcode, 1, -3);
        } else {
            $postcode = $this->postcode;
        }

        $result = null;

        $data = array(
            'email' => Configuration::get('PS_SHOP_EMAIL'),
            'dominio' => $this->context->shop->domain,
            'cp' => $postcode,
            'locality' => $this->city
        );

        $md5 = md5(json_encode($data, true));

        if (!isset($this->cache[$md5])) {
        
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

                $result = curl_exec($curl);
                $this->cache[$md5] = $result;
                $result = json_decode($result, true);
                curl_close($curl);
            } catch (\Exception $e) {
                throw new \Exception('Error de comunicación con el servidor: ' . $e->getMessage());
            }
        } else {
            try {
                $result = json_decode($this->cache[$md5], true);
            } catch (\Exception $e) {
                unset($this->cache[$md5]);
                return $this->getDeliveryService();
            }
        }

        if ($result !== null && is_array($result)) {
            $carrier_code = null;

            $carriers = array_values($this->module->carriers);

            for ($i = 0; $i < count($carriers); $i++) { 
                if (Configuration::get(get_class($this->module)::PREFIX . $carriers[$i]) == $this->module->id_carrier) {
                    $carrier_code = $carriers[$i];
                    break;
                }
            }

            if ($carrier_code !== null) {
                foreach ($result as $shipping_method) {
                    if ($shipping_method['code'] === $carrier_code && isset($shipping_method['total_price']) && $shipping_method['total_price'] !== null) {
                        $price = (int) $shipping_method['total_price'];
                    }
                }
            }
        }

        return $price;
    }

    public function loadLocation($cart)
    {
        $address = new Address($cart->id_address_delivery);
        $this->city = $address->city;
        $this->postcode = $address->postcode;
    }

    public function run($cart, $shipping_fees)
    {
        $this->loadLocation($cart);
        $shipping_cost = $this->getDeliveryService();

        if ($shipping_cost === false) {
            return false;
        }

        $price_multiplier = Configuration::get('treggo_multiplicador');
        $shipping_price = $shipping_cost * $price_multiplier;

        return $shipping_price + $shipping_fees;
    }
}
