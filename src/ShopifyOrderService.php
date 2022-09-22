<?php

namespace Clrz\ShopifyServices;

class ShopifyOrderService extends ShopifyService
{
    public $nextPageParams;

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function getOrderById($id, $params = []): array
    {
        return $this->shopifyClient->Order($id)->get($params);
    }

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function getOrders($params = []): array
    {
        $ordersApi            = $this->shopifyClient->Order;
        $orders               = $ordersApi->get($params);
        $this->nextPageParams = $ordersApi->getNextPageParams();

        return $orders;
    }

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     * @throws \PHPShopify\Exception\SdkException
     */
    public function countOrders($params = []): int
    {
        return $this->shopifyClient->Order->count($params);
    }

    /**
     * @param $name
     *
     * @return array|string|string[]
     * @throws \Exception
     */
    public function getOrderIdByName($name)
    {
        $query = '
            query {
              orders(first: 1, query: "name:' . $name . '") {
                edges{
                  node{
                    id
                 }
               }
             }
            }';

        $response = $this->shopifyGQL->query($query);

        if (!empty($response->getErrors())) {
            return ['error' => 'Error: ' . implode("\r\n", array_column($response->getErrors(), 'message')), 'errorStatus' => 460];
        } else {
            $response = $response->getData();

            if (!empty($response) && !empty($response['orders']['edges'])) {
                return str_replace(
                    'gid://shopify/Order/', '', $response['orders']['edges'][0]['node']['id']
                );
            } else {
                throw new Exception('The order with name' . $name . ' does not exist in the shop.', 404);
            }
        }
    }

}
