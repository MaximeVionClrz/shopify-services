<?php

namespace Clrz\ShopifyServices;

use Monolog\Logger;
use PHPShopify\Exception\ApiException;
use PHPShopify\Exception\CurlException;
use PHPShopify\ShopifySDK;
use Psr\Log\LoggerInterface;
use Softonic\GraphQL\ClientBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface as ParameterBagInterfaceAlias;

class GlobalService
{
    const GRAPHQL_URL = 'https://%s:%s@%s/admin/api/%s/graphql.json';

    /**
     * @var \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface
     */
    protected $parameters;
    /**
     * @var array
     */
    protected $shopifyParameters;

    /**
     * @var \Softonic\GraphQL\Client
     */
    protected $shopifyGQL;
    /**
     * @var \PHPShopify\ShopifySDK
     */
    protected $shopifyClient;

    protected $logger;

    /**
     * AbstractModel constructor.
     *
     * @param \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface $parameters
     * @param \Psr\Log\LoggerInterface                                                  $serviceslogger
     */
    public function __construct(ParameterBagInterfaceAlias $parameters, LoggerInterface $serviceslogger)
    {
        $this->logger            = $serviceslogger;
        $this->parameters        = $parameters;
        $this->shopifyParameters = $this->parameters->get('shopify') ?? [];

        $this->shopifyGQL = ClientBuilder::build(
            sprintf(
                self::GRAPHQL_URL, $this->shopifyParameters['api.key'], $this->shopifyParameters['access.token'],
                $this->shopifyParameters['shop.url'],
                $this->shopifyParameters['api.version']
            )
        );

        if (!$this->shopifyClient) {
            $config = null != $this->shopifyParameters['access.token']
                ? [
                    'ShopUrl'     => $this->shopifyParameters['shop.url'],
                    'AccessToken' => $this->shopifyParameters['access.token']
                ] : [
                    'ShopUrl'    => $this->shopifyParameters['shop.url'],
                    'ApiKey'     => $this->shopifyParameters['api.key'],
                    'Password'   => $this->shopifyParameters['api.password'],
                    'ApiVersion' => $this->shopifyParameters['api.version'],
                ];

            $this->shopifyClient = new ShopifySDK($config);
        }
    }

    /**
     * @param array  $entities
     * @param string $entityType
     *
     * @return void
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     */
    public function deleteEntities(array $entities, string $entityType): void
    {
        if (isset($entities['id'])) {
            $entities = [$entities];
        }
        foreach ($entities as $entity) {
            $this->shopifyClient->{ucfirst($entityType)}($entity['id'])->delete();
        }
    }

    /**
     * @param string       $entityId
     * @param array|string $tagsToAdd
     * @param              $entityType
     *
     * @return mixed
     */
    public function addTags(string $entityId, $tagsToAdd, $entityType)
    {
        $entityType   = ucfirst($entityType);
        $entity       = $this->shopifyClient->{$entityType}($entityId)->get(['fields' => 'tags']);
        $existingTags = $entity['tags'];

        if (is_array($tagsToAdd)) {
            $tagsToAdd = implode(', ', $tagsToAdd);
        }

        if (!$existingTags) {
            $finalTags = $tagsToAdd;
        } else {
            $finalTags = $existingTags . ','.$tagsToAdd;
        }

        return $this->shopifyClient->{$entityType}($entityId)->put(['tags' => $finalTags]);
    }

    /**
     * @param string       $entityId
     * @param array|string $tagsToRemove
     * @param              $entityType
     *
     * @return mixed
     */
    public function removeTags(string $entityId, $tagsToRemove, $entityType)
    {
        if (!is_array($tagsToRemove)) {
            $tagsToRemove = explode(',', $tagsToRemove);
        }

        $entityName = ucfirst($entityType);
        $entity = $this->shopifyClient->{$entityType}($entityId)->get();

        $existingTags = explode(', ', $entity['tags']);
        foreach ($existingTags as $pKey => $presentTag) {
            foreach ($tagsToRemove as $tagToRemove) {
                if ($tagToRemove == trim($presentTag)) {
                    unset($existingTags[$pKey]);
                }
            }
        }

        $allTags = implode(',', $existingTags);

        return $this->shopifyClient->{$entityName}($entityId)->put(['tags' => $allTags]);
    }

    /**
     * @param string $entityId
     * @param array  $searchReplace
     * @param string $entityType
     *
     * @return mixed
     */
    public function replaceTags(string $entityId, array $searchReplace, string $entityType)
    {
        $entityType = ucfirst($entityType);
        $entity = $this->shopifyClient->{$entityType}($entityId)->get();

        $existingTags = $entity['tags'];
        foreach ($searchReplace as $search => $replace) {
            $existingTags = str_replace($search, $replace, $existingTags);
        }

        return $this->shopifyClient->{$entityType}($entityId)->put(['tags' => $existingTags]);
    }

    /**
     * @param string $entityId
     * @param string $entityName
     * @param        $params
     *
     * @return array
     */
    public function getEntityMetafields(string $entityId, string $entityName, $params = []): array
    {
        try
        {
            return $this->shopifyClient->{$entityName}($entityId)->Metafield->get($params);
        }
        catch(\Exception $e)
        {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param $callbackUrl
     * @param $topic
     *
     * @return array
     * @throws \PHPShopify\Exception\ApiException
     * @throws \PHPShopify\Exception\CurlException
     * @throws \Exception
     */
    public function createWebhook($callbackUrl, $topic): array
    {
        if (!$this->shopifyClient) {
            throw new \Exception('Shopify Client need to be instantiate !');
        }

        $webhook = [];
        foreach ($this->shopifyClient->Webhook->get() as $hook) {
            if ($hook['topic'] == $topic) {
                $webhook = $hook;
                break;
            }
        }

        if (!$webhook) {
            $webhook = $this->shopifyClient->Webhook->post(
                [
                    'address' => str_replace('http://', 'https://', $callbackUrl),
                    'topic'   => $topic
                ]);
        }

        return $webhook;
    }

    /**
     * @param $webhookId
     *
     * @throws \Exception
     */
    public function removeWebhook($webhookId): void
    {
        if (!$this->shopifyClient) {
            throw new \Exception('Shopify Client need to be instantiate !');
        }

        if (!$webhookId) {
            throw new \Exception('WebhookId is mandatory');
        }

        try
        {
            $this->shopifyClient->Webhook($webhookId)->delete();
        }
        catch(\Exception $e)
        {
            error_log($e);
        }
    }
}
