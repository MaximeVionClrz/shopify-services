<?php

namespace Clrz\ShopifyServices\Service;

use Exception;
use PHPShopify\Exception\ApiException;
use PHPShopify\Exception\CurlException;

class ShopifyCustomerService extends GlobalService
{
    public $nextPageParams;

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function getCustomers(array $params = []): array
    {
        $customerApi          = $this->shopifyClient->Customer;
        $customers            = $customerApi->get($params);
        $this->nextPageParams = $customerApi->getNextPageParams();

        return $customers;
    }

    /**
     * @param $paramValue
     * @param string $entryLabel
     *
     * @return bool
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function doesExist($paramValue, string $entryLabel = 'id', $returnType = 'boolean'): bool
    {
        if (!in_array($entryLabel, ['id','email'])) {
            throw new ClrzServiceException('Only `id` or `email` are avalaible for "entryLabel" param');
        }

        if ('id' == $entryLabel) {
            $customer = $this->shopifyClient->Customer($paramValue)->get(['fields' => 'id']);
        } else {
            $customer = $this->shopifyClient->Customer->get(['email' => $paramValue]);
        }

        if ('boolean' == $returnType) {
            return count($customer) >= 1;
        } else {
            return $customer;
        }
    }

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function mergeCustomers($originCustomer, $fakeCustomer, $fields = 'tags,accepts_marketing'): array
    {
        $updateData = [];
        if (str_contains($fields, 'tags')) {
            $updateData['tags'] = $originCustomer['tags'];
            $updateData['tags'] .= ','.$fakeCustomer['tags'];
        }
        if (str_contains($fields, 'accepts_marketing') && $fakeCustomer['accepts_marketing']) {
            $updateData['accepts_marketing'] = true;
        }

        $customer = $this->updateCustomer($originCustomer['id'], $updateData);
        $this->deleteEntities($fakeCustomer, 'Customer');

        return $customer;
    }

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function updateCustomer($id, $params = []): array
    {
        return $this->shopifyClient->Customer($id)->put($params);
    }
}
