<?php
/**
 * Модуль расчета доставки в пункты выдачи заказов с разбивкой по регионам.
 *
 * This library is free software; you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation; either version 2.1 of the License, or
 * (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public
 * License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this library; if not, write to the
 * Free Software Foundation, Inc.,
 * 59 Temple Place, Suite 330,
 * Boston, MA 02111-1307 USA
 *
 * @license http://www.gnu.org/licenses/lgpl.html LGPL-2.1
 * @author Serge Rodovnichenko <sergerod@gmail.com>
 * @copyright (C) 2014 Serge Rodovnichenko <sergerod@gmail.com>
 * @version 1.2
 *
 * @property-read string $currency Валюта плагина
 * @property-read array $rate_zone Массив со страной и регионом, для которых работает плагин
 * @property-read string $rate_zone['country'] ISO3 Страна
 * @property-read string $rate_zone['region'] код региона
 * @property-read array $rate Массив с пунктами выдачи, ценами, лимитами
 * @property-read string $rate[]['location'] Название ПВЗ
 * @property-read string $rate[]['cost'] Стоимость доставки. По идее тут float, но чтобы в шаблон передавалось число с точкой в качестве разделителя, то string
 * @property-read string $rate[]['maxweight'] Максимальный допустимый вес заказа. Про float см. выше
 * @property-read string $rate[]['free'] Пороговое значение стоимости заказа, выше которого доставка бесплатна. Про float см. выше
 * 
 * @link http://webasyst.ru/developers/docs/plugins/shipping-plugins/
 */
class regionalpickupShipping extends waShipping
{
    /**
     * 
     * 
     * @return string
     */
    public function allowedCurrency()
    {
        return $this->currency;
    }

    /**
     * 
     * 
     * @return array
     */
    public function allowedAddress()
    {
        return array(array_filter($this->rate_zone));
    }

    /**
     * 
     *
     * @param   array  $params
     * @return  string
     */
    public function requestedAddressFields()
    {
        if (!$this->prompt_address) {
            return FALSE;
        }

        return array(
            'country' => array('cost' => true, 'required' => true),
            'region' => array('cost' => true)
        );
    }

    /**
     * Единица измерения веса, используемая плагином
     *
     * @return string
     */
    public function allowedWeightUnit()
    {
        return 'kg';
    }

    /**
     * Проверяет есть-ли у варианта ограничение по максимальному весу
     * и, если есть, разрешен-ли указанный вес для этого варианта
     *
     * @param array $rate массив с настройками варианта
     * @param float $weight вес заказа
     * @return boolean
     */
    protected function isAllowedWeight($rate, $weight)
    {
        return (!$rate['maxweight'] || $weight <= $rate['maxweight']);
    }

    /**
     * Расчет стоимости доставки указанного варианта с учетом возможного
     * бесплатного порога. Если бесплатный порог не указан, пуст или равен 0
     * то возвращаем стоимость доставки. Иначе 0
     *
     * @param array $rate Настройки варианта
     * @param float $orderCost стоиомсть заказа
     * @return int|float стоимость доставки
     */
    protected function calcCost($rate, $orderCost)
    {
        
        return (!$rate['free'] || $orderCost < $rate['free']) ? 0 : $rate['cost'];
    }

    /**
     * Расчет стоимости и возможности доставки заказа
     *
     * @return string|array Сообщение о недоступности ПВЗ или список ПВЗ с ценами
     */
    protected function calculate()
    {
        $address = $this->getAddress();

        if(
            !isset($address['country'])
            || $address['country'] !== $this->rate_zone['country']
            || !isset($address['region'])
            || $address['region'] !== $this->rate_zone['region']
        )
        {
            return _wp('No suitable pick-up points');
        }

        $weight = $this->getTotalWeight();
        $cost = $this->getTotalPrice();

        $deliveries = array();

        /**
         * @todo Необходимость методов isAllowedWeight \ calcCost под вопросом: 
         * вызываются они в одном месте, да и состоят из 1 строки.
         * Нужны ли "пустышки" для все значений? см. формат массива возвращаемого calculate()
         * @link http://webasyst.ru/developers/docs/plugins/shipping-plugins/
         */
        for ($i = 1; $i < count($this->rate); $i++) {
            if ($this->isAllowedWeight($this->rate[$i], $weight)) {
                $deliveries[$i] = array(
                    'name' => $this->rate[$i]['location'],
                    // 'description' => '',
                    'est_delivery' => '',
                    'currency' => $this->currency,
                    // 'rate_min' => $cost,
                    // 'rate_max' => $cost,
                    'rate' => $this->calcCost($this->rate[$i], $cost),
                    
                );
            }
        }

        return empty($deliveries) ? _wp('No suitable pick-up points') : $deliveries;
    }

    /**
     * 
     * @param   string|null  $name
     * @return  array
     */
    public function getSettings($name = null)
    {
        $settings = parent::getSettings($name);

        if(isset($settings['rate']) && !empty($settings['rate'])) {
            foreach ((array)$settings['rate'] as $index => $item) {
                $settings['rate'][$index] = array_merge(
                    array('free' => 0, 'maxweight' => 0, 'cost' => 0), 
                    (array)$item
                );
            }
        }

        return $settings;
    }

    /**
     * 
     *
     * @param   array  $params
     * @return  string
     */
    public function getSettingsHTML($params = array())
    {
        $settings = $this->getSettings();

        if (isset($params['value']) && count($params['value'])) {
            $settings = array_merge($settings, $params['value']);
        }

        if ($params['namespace']) {
            $namespace = is_array($params['namespace']) ? '[' . implode('][', $params['namespace']) . ']' : $params['namespace'];
        } else {
            $namespace = '';
        }

        $view = wa()->getView();
        // @link http://smarty.net/docs/en/variable.escape.html
        $view->escape_html = true;
        $view->assign(array('namespace' => $namespace, 'settings' => $settings));
        $html = $view->fetch($this->path . '/templates/settings.html');

        return $html . parent::getSettingsHTML($params);
    }

    /**
     * Несмотря на название это, видимо, валидатор сохраняемых значений
     * конфигурации. Во всяком случае то, что он возвращает сохраняется
     * в БД.
     *
     * Непонятно, можно-ли как-то отсюда ошибку выбрасывать. Разбирать
     * цепочку вызовов лень, поэтому просто превратим в 0 все ошибочные
     * значения
     *
     * @param   array  $settings
     * @return  array
     */
    public function saveSettings($settings = array())
    {
		if (isset($settings['rate']) && !empty($settings['rate'])) {
			foreach ((array)$settings['rate'] as $index => $item) {
				if (!isset($item['location']) || empty($item['location'])) {
					unset($settings['rate'][$index]);
				} else {
					foreach (array('cost', 'maxweight', 'free') as $key) {
						$settings['rate'][$index][$key] = (float)(isset($item[$key]) ? str_replace(',', '.', $item[$key]) : 0);
					}
				}
			}
		}

        return parent::saveSettings($settings);
    }
}
