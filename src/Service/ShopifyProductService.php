<?php

namespace Clrz\Service;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use PHPShopify\Exception\ApiException;
use PHPShopify\Exception\CurlException;

class ShopifyProductService extends GlobalService
{
    public array $nextPageParams;

    public function countProducts($params = [])
    {
        return $this->shopifyClient->ProductVariant->count($params);
    }

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function getProductById($id, $params = []): array
    {
        return $this->shopifyClient->Product($id)->get($params);
    }

    /**
     * @throws \Exception
     */
    public function getProductIdByTag($tag, $option = 'eq'): array|string
    {
        $tagReq = match ($option) {
            'like'   => '*(' . $tag . ')*',
            'like_after'   => '(' . $tag . ')*',
            'like_before'   => '*(' . $tag . ')',
            'eq'     => $tag,
            default  => '(' . $tag . ')',
        };

        $query = '
            query {
              products(first: 250, query: "tag:' . $tagReq . '") {
                edges{
                  node{
                    id
                 }
               }
             }
            }';

        $response = $this->shopifyGQL->query($query);

        if (!empty($response->getErrors())) {
            throw new \Exception(implode("\r\n", array_column($response->getErrors(), 'message')), 460);
        } else {
            $response = $response->getData();
            if (!empty($response) && !empty($response['products']['edges'])) {

                $products = [];
                foreach ($response['products']['edges'] as $item) {
                    $products[] = ['id' => str_replace(
                        'gid://shopify/Product/', '', $item['node']['id']
                    )];
                }


                return $products;
            } else {

                throw new \Exception('The product with tag "' . $tag . '" does not exist in the shop: '. $this->shopifyParameters['shop.url'], 404);
            }
        }
    }
    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function getProducts($params = []): array
    {
        $productsApi            = $this->shopifyClient->Product();
        $products               = $productsApi->get($params);
        $this->nextPageParams   = $productsApi->getNextPageParams();

        return $products;
    }

    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     * @throws \Exception
     */
    public function adjustQuantityByIdentifier($identifier, $quantity, $identifierType = 'sku')
    {
        $variant = $this->getVariantByIdentifier($identifier, $identifierType);
        if (isset($variant['error'])) {
            throw new Exception($variant['error'], $variant['errorStatus']);
        }

        if ($variant['inventory_item_id'] && $variant['location_id']) {
            $this->shopifyClient->InventoryLevel->adjust(
                [
                    'location_id' => $variant['location_id'],
                    'inventory_item_id' => $variant['inventory_item_id'],
                    'available_adjustment' => $quantity,
                ]
            );

            $this->logger->info(sprintf('The stock variant with the %s "%s" has successfully been adjusted by %s', $identifierType, $identifier, $quantity));
        }
    }

    /**
     * @return void
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function sortAllVariantsBySize(): void
    {
        $shopifyProducts   = $this->getProducts(['fields' => 'id,variants']);
        $lastPage        = false;
        $pagination      = 0;

        while (!$lastPage) {
            try {
                $pagination++;

                if (!$this->nextPageParams) {
                    $lastPage = true;
                }

                foreach ($shopifyProducts as $k => $product) {
                    if (count($product['variants']) == 1) {
                        continue;
                    }
                    $this->sortBySize($product);
                    $this->update($product['id'], $product);
                    $this->logger->notice('Updated: '. $product['id']);
                    unset($shopifyProducts[$k]);
                }

                $shopifyProducts = $this->getProducts($this->nextPageParams);

            } catch (\Exception $e) {
                $this->logger->error(sprintf('Exception [%s]: %s', $e->getCode(), $e->getMessage()));
            }
        }
    }

    private function getProductIdByVariants(array $variants, $identifier)
    {
        if (!empty($variants)) {
            foreach ($variants as $variant) {
                $fetchedVariant = $this->getVariantByIdentifier($variant[$identifier]);
                if (isset($fetchedVariant['product_id'])) {
                    return $fetchedVariant['product_id'];
                }
            }
        }

        return false;
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     * @throws \Exception
     */
    #[ArrayShape(['infos' => "array|false[]", 'status' => "string"])]
    public function create(array $data = []): array
    {
        $this->logger->info('Handling ' . $data['handle'] . ' - ' . $data['sku']);
        $this->sortBySize($data);

        $shopifyProductId = $this->getProductIdByVariants($data['variants'], 'sku');
        if ($shopifyProductId) {
            $shopifyProductApi = $this->shopifyClient->Product($shopifyProductId);
            return ['infos' => $shopifyProductApi->get(), 'status' => 'unchanged'];
        } else {
            return ['infos' => $this->shopifyClient->Product()->post($data), 'status' => 'created'];
        }
    }

    /**
     * @param $product
     * @return void
     */
    public function sortBySize(&$product): void
    {
        $productVariantSorted = [];
        foreach ($product['variants'] as $key => $variant) {
            if (isset(Size::SIZE_LIST[$variant['option2']])) {
                $index = Size::SIZE_LIST[$variant['option2']];
                $productVariantSorted[$index] = $product['variants'][$key];
            }
        }
        if (!empty($productVariantSorted)) {
            ksort($productVariantSorted);
        } else {
            // This concerns others variants than xs, s, m , l, xl
            $productVariantSorted = $product['variants'];
        }

        $product['variants'] = array_values($productVariantSorted);
    }


    /**
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function getVariantById($id, $params = []): array
    {
        return $this->shopifyClient->ProductVariant($id)->get($params);
    }

    public function getVariantMetafield(mixed $variantId, ?string $key = null, ?string $namespace = null)
    {
        $params = [];
        if ($namespace) {
            $params['namespace'] = $namespace;
        }

        $metafields = $this->shopifyClient->ProductVariant($variantId)->Metafield->get($params);

        if ($key) {
            foreach ($metafields as $metafield) {
                if ($key == $metafield['key']) {
                    return $metafield;
                }
            }
        } else {
            return $metafields;
        }
    }

    public function update($productId, $data)
    {
        return $this->shopifyClient->Product($productId)->put($data);
    }

    /**
     * @param string $identifier
     * @param int $quantity
     * @param string $identifierType
     *
     * @return array
     * @throws \Exception
     */
    public function updateStockByIdentifier(string $identifier, int $quantity, string $identifierType = 'sku'): array
    {
        if (!in_array($identifierType, ['sku', 'barcode'])) {
            return ['error' => 'Wrong identifier'];
        }

        $data = [
            "location_id" => null,
            "inventory_item_id" => null,
            "available" => $quantity,
        ];

        $variant = $this->getVariantByIdentifier($identifier, $identifierType);
        if (isset($variant['error'])) {
            throw new Exception($variant['error'], 404);
        }
        if (empty($variant)) {
            throw new Exception(
                sprintf('Variant not found with provided Data : %s for Identifier of type %s', $identifier, $identifierType), 404
            );
        }

        $data['inventory_item_id'] = $variant['inventory_item_id'];
        $data['location_id'] = $variant['location_id'];

        $oldQuantity = $variant['inventory_quantity'];
        $productId = $variant['product_id'];
        $variantId = $variant['variant_id'];

        if (!$data['inventory_item_id']) {
            throw new Exception(sprintf('Inventory item id not found with the provided %s : %s', $identifierType, $identifier), 404);
        }

        if ($quantity != $oldQuantity) {
            $this->shopifyClient->InventoryLevel->set($data);

            return [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'old_quantity' => $oldQuantity,
            ];
        } else {
            throw new Exception('Unchanged stock level', 304);
        }
    }

    /**
     * @param string $value
     * @param string $identifier
     *
     * @return array|string[]
     */
    public function getVariantByIdentifier(string $value, string $identifier = 'sku'): array
    {
        if (!in_array($identifier, ['sku', 'barcode'])) {
            return ['error' => 'Wrong identifier', 'errorStatus' => 460];
        }

        $query = '
            query {
              productVariants(first: 1, query: "' . $identifier . ':' . $value . '") {
                edges{
                  node{
                    id
                    sku
                    barcode
                    selectedOptions {
                      name,
                      value
                    }
                    inventoryQuantity
                     product{
                       id
                       tags
                       handle
                     }
                    inventoryItem{
                      id
                      inventoryLevels(first: 10) {
                        edges {
                          node {
                            location {
                              id
                            }
                          }
                        }
                     }
                   }
                 }
               }
             }
            }';

        $response = $this->shopifyGQL->query($query);

        if (!empty($response->getErrors())) {
            return ['error' => 'Error: ' . implode("\r\n", array_column($response->getErrors(), 'message')), 'errorStatus' => 460];
        } else {
            $response = $response->getData();

            if (!empty($response) && !empty($response['productVariants']['edges'])) {

                if ($response['productVariants']['edges'][0]['node'][$identifier] != $value) {
                    return ['error' => 'Error incompatible ' . $identifier . ': ' . $identifier . ' searched: ' . $value . ' - ' . $identifier . ' returned : ' . $response['productVariants']['edges'][0]['node'][$identifier]];
                }

                $variantId = str_replace(
                    'gid://shopify/ProductVariant/', '', $response['productVariants']['edges'][0]['node']['id']
                );
                $inventoryItemId = str_replace(
                    'gid://shopify/InventoryItem/', '',
                    $response['productVariants']['edges'][0]['node']['inventoryItem']['id']
                );
                $locationId = str_replace(
                    'gid://shopify/Location/', '',
                    $response['productVariants']['edges'][0]['node']['inventoryItem']['inventoryLevels']['edges'][0]['node']['location']['id']
                );
                $productId = str_replace(
                    'gid://shopify/Product/', '', $response['productVariants']['edges'][0]['node']['product']['id']
                );

                $currentInventoryQuantity = $response['productVariants']['edges'][0]['node']['inventoryQuantity'];
                $barcode = $response['productVariants']['edges'][0]['node']['barcode'];
                $sku = $response['productVariants']['edges'][0]['node']['sku'];
                $tags = $response['productVariants']['edges'][0]['node']['product']['tags'];

                if (!empty($inventoryItemId) && !empty($locationId)) {
                    return [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'location_id' => $locationId,
                        'barcode' => $barcode,
                        'sku' => $sku,
                        'product_tags' => $tags,
                        'inventory_item_id' => $inventoryItemId,
                        'inventory_quantity' => $currentInventoryQuantity,
                        'options' => $response['productVariants']['edges'][0]['node']['selectedOptions'],
                    ];
                } else {
                    return ['error' => 'Missing data : inventory_item_id or location_id', 'errorStatus' => 404];
                }
            } else {
                return ['error' => 'The ' . $identifier . ' ' . $value . ' provided does not exist in the shop.', 'errorStatus' => 404];
            }
        }
    }

    /**
     * @param $product
     * @param $payload
     *
     * @return void
     * @throws \Exception
     */
    public function updateInventoryItem($product, $payload): void
    {
        foreach ($product['variants'] as $variant) {
            try {
                $this->shopifyClient->InventoryItem($variant['inventory_item_id'])->put($payload);
            } catch (Exception $ex) {
                throw new Exception(sprintf('country_code_of_origin or harmonized_system_code not found or incorrect %s', json_encode($payload)), $ex->getCode());
            }
        }
    }

    /**
     * @param string $entityId
     * @param array  $metafields // [[]]
     * @param string $entityType
     *
     * @return void
     * @throws \Exception
     */
    public function addMetafields(string $entityId, array $metafields, string $entityType = 'Product', $retryOnce = false): void
    {
        if (!in_array($entityType, ['Product', 'ProductVariant'])) {
            throw new Exception('This method only accepts "Product" and "ProductVariant" as entityType');
        }

        foreach ($metafields as $metafield) {
            if (!$retryOnce) {
                $this->shopifyClient->{$entityType}($entityId)->Metafield->post($metafield);
            } else {
                try {
                    $this->shopifyClient->{$entityType}($entityId)->Metafield->post($metafield);
                } catch (Exception|CurlException|ApiException $e) {
                    sleep(5);
                    $this->shopifyClient->{$entityType}($entityId)->Metafield->post($metafield);
                }
            }
        }
    }
}
