<?php

namespace MCS;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use League\Csv\CharsetConverter;
use League\Csv\Reader;
use League\Csv\Writer;
use MCS\Exception\MWSException;
use MCS\Model\Destination;
use MCS\Model\Subscription;
use Spatie\ArrayToXml\ArrayToXml;
use SplTempFileObject;

class MWSClient
{
    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';
    protected $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'MWSAuthToken' => null,
        'Application_Version' => '0.0.*'
    ];
    protected $MarketplaceIds = [
        'A2EUQ1WTGCTBG2' => 'mws.amazonservices.ca',
        'ATVPDKIKX0DER' => 'mws.amazonservices.com',
        'A1AM78C64UM0Y8' => 'mws.amazonservices.com.mx',
        'A1PA6795UKMFR9' => 'mws-eu.amazonservices.com',
        'A1RKKUPIHCS9HS' => 'mws-eu.amazonservices.com',
        'A13V1IB3VIYZZH' => 'mws-eu.amazonservices.com',
        'A21TJRUUN4KGV' => 'mws.amazonservices.in',
        'APJ6JRA9NG5V4' => 'mws-eu.amazonservices.com',
        'A1F83G8C2ARO7P' => 'mws-eu.amazonservices.com',
        'A1VC38T7YXB528' => 'mws.amazonservices.jp',
        'AAHKV2X7AFYLW' => 'mws.amazonservices.com.cn',
        'A39IBJ37TRP1C6' => 'mws.amazonservices.com.au',
        'A2Q3Y263D00KWC' => 'mws.amazonservices.com'
    ];
    protected $debugNextFeed = false;
	protected $client = null;
	protected $lastRequestResponse = null;

    public function __construct(array $config)
    {
        foreach ($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }
        $required_keys = [
            'Marketplace_Id',
            'Seller_Id',
            'Access_Key_ID',
            'Secret_Access_Key'
        ];
        foreach ($required_keys as $key) {
            if (is_null($this->config[$key])) {
                throw new Exception('Required field ' . $key . ' is not set');
            }
        }
        if (!isset($this->MarketplaceIds[$this->config['Marketplace_Id']])) {
            throw new Exception('Invalid Marketplace Id');
        }
        $this->config['Application_Name'] = self::APPLICATION_NAME;
        $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id']];
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];
    }

    /**
     * Call this method to get the raw feed instead of sending it
     */
    public function debugNextFeed()
    {
        $this->debugNextFeed = true;
    }

    /**
     * A method to quickly check if the supplied credentials are valid
     * @return boolean
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function validateCredentials()
    {
        try {
            $this->ListOrderItems('validate');
        } catch (Exception $e) {
            if ($e->getMessage() == 'Invalid AmazonOrderId: validate') {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Returns the current competitive price of a product, based on ASIN.
     * @param array [$asin_array = []]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetCompetitivePricingForASIN($asin_array = [])
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetCompetitivePricingForASIN',
            $query
        );
        if (isset($response['GetCompetitivePricingForASINResult'])) {
            $response = $response['GetCompetitivePricingForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
            }
        }
        return $array;
    }

    /**
     * Returns the current competitive price of a product, based on SKU.
     * @param array [$sku_array = []]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetCompetitivePricingForSKU($sku_array = [])
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetCompetitivePricingForSKU',
            $query
        );
        if (isset($response['GetCompetitivePricingForSKUResult'])) {
            $response = $response['GetCompetitivePricingForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Price'] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Rank'] = $product['Product']['SalesRankings']['SalesRank'][1];
            }
        }
        return $array;
    }

    /**
     * Returns lowest priced offers for a single product, based on ASIN.
     * @param string $asin
     * @param string [$ItemCondition = 'New'] Should be one in: New, Used, Collectible, Refurbished, Club
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetLowestPricedOffersForASIN($asin, $ItemCondition = 'New')
    {
        $query = [
            'ASIN' => $asin,
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ItemCondition' => $ItemCondition
        ];
        return $this->request('GetLowestPricedOffersForASIN', $query);
    }

    /**
     * Returns pricing information for your own offer listings, based on SKU.
     * @param array  [$sku_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetMyPriceForSKU($sku_array = [], $ItemCondition = null)
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetMyPriceForSKU',
            $query
        );
        if (isset($response['GetMyPriceForSKUResult'])) {
            $response = $response['GetMyPriceForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                if (isset($product['Product']['Offers']['Offer'])) {
                    $array[$product['@attributes']['SellerSKU']] = $product['Product']['Offers']['Offer'];
                } else {
                    $array[$product['@attributes']['SellerSKU']] = [];
                }
            } else {
                $array[$product['@attributes']['SellerSKU']] = false;
            }
        }
        return $array;
    }

    /**
     * Returns pricing information for your own offer listings, based on ASIN.
     * @param array [$asin_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetMyPriceForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetMyPriceForASIN',
            $query
        );
        if (isset($response['GetMyPriceForASINResult'])) {
            $response = $response['GetMyPriceForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success' && isset($product['Product']['Offers']['Offer'])) {
                $array[$product['@attributes']['ASIN']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['ASIN']] = false;
            }
        }
        return $array;
    }

    /**
     * Returns pricing information for the lowest-price active offer listings for up to 20 products, based on ASIN.
     * @param array [$asin_array = []] array of ASIN values
     * @param array [$ItemCondition = null] Should be one in: New, Used, Collectible, Refurbished, Club. Default: All
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetLowestOfferListingsForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetLowestOfferListingsForASIN',
            $query
        );
        if (isset($response['GetLowestOfferListingsForASINResult'])) {
            $response = $response['GetLowestOfferListingsForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['LowestOfferListings']['LowestOfferListing'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['LowestOfferListings']['LowestOfferListing'];
            } else {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = false;
            }
        }
        return $array;
    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param DateTime $from
     * @param boolean $allMarketplaces , list orders from all marketplaces
     * @param array $states , an array containing orders states you want to filter on
     * @param string $FulfillmentChannels
     * @param DateTime|null $till
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListOrders(
        DateTime $from,
        $allMarketplaces = false,
        $states = [
            'Unshipped',
            'PartiallyShipped'
        ],
        $FulfillmentChannels = 'MFN',
        DateTime $till = null
    ) {
        $query = [
            'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
        ];
        if ($till !== null) {
            $query['CreatedBefore'] = gmdate(self::DATE_FORMAT, $till->getTimestamp());
        }
        $counter = 1;
        foreach ($states as $status) {
            $query['OrderStatus.Status.' . $counter] = $status;
            $counter = $counter + 1;
        }
        if ($allMarketplaces == true) {
            $counter = 1;
            foreach ($this->MarketplaceIds as $key => $value) {
                $query['MarketplaceId.Id.' . $counter] = $key;
                $counter = $counter + 1;
            }
        }
        if (is_array($FulfillmentChannels)) {
            $counter = 1;
            foreach ($FulfillmentChannels as $fulfillmentChannel) {
                $query['FulfillmentChannel.Channel.' . $counter] = $fulfillmentChannel;
                $counter = $counter + 1;
            }
        } else {
            $query['FulfillmentChannel.Channel.1'] = $FulfillmentChannels;
        }
        $response = $this->request('ListOrders', $query);
        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersResult']['NextToken'])) {
                $data['ListOrders'] = $response['ListOrdersResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersResult']['NextToken'];
                return $data;
            }

            $response = $response['ListOrdersResult']['Orders']['Order'];

            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }

            return $response;

        } else {
            return [];
        }
	}

	/**
     * Returns orders updated after and before the dates specified.
     * @param DateTime $lastUpdatedAfter
     * @param DateTime $LastUpdatedBefore
     * @param array $params
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function ListOrdersUpdated(DateTime $lastUpdatedAfter, DateTime $lastUpdatedBefore = null, array $params = [])
	{
		$query = [
			'LastUpdatedAfter' => gmdate(self::DATE_FORMAT, $lastUpdatedAfter->getTimestamp())
		];

		if (!empty($lastUpdatedBefore)) {
			$query['LastUpdatedBefore'] = gmdate(self::DATE_FORMAT, $lastUpdatedBefore->getTimestamp());
		}

		foreach ($params as $param => $value) {
			$query[$param] = $value;
		}

		$response = $this->request('ListOrders', $query);

		if (isset($response['ListOrdersResult']['Orders']['Order'])) {
			if (isset($response['ListOrdersResult']['NextToken'])) {
				$data['ListOrders'] = $response['ListOrdersResult']['Orders']['Order'];
				$data['NextToken'] = $response['ListOrdersResult']['NextToken'];
				return $data;
			}

			$response = $response['ListOrdersResult']['Orders']['Order'];

			if (array_keys($response) !== range(0, count($response) - 1)) {
				return [$response];
			}

			return $response;
		} else {
			return [];
		}
    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param string $nextToken
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListOrdersByNextToken($nextToken)
    {
        $query = [
            'NextToken' => $nextToken,
        ];
        $response = $this->request(
            'ListOrdersByNextToken',
            $query
        );
        if (isset($response['ListOrdersByNextTokenResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersByNextTokenResult']['NextToken'])) {
                $data['ListOrders'] = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersByNextTokenResult']['NextToken'];
                return $data;
            }
            $response = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }
            return $response;
        } else {
            return [];
        }
    }

    /**
     * Returns an order based on the AmazonOrderId values that you specify.
     * @param string $AmazonOrderId
     * @return bool if the order is found, false if not
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetOrder($AmazonOrderId)
    {
        $response = $this->request('GetOrder', [
            'AmazonOrderId.Id.1' => $AmazonOrderId
        ]);
        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;
        }
    }

    /**
     * Returns order items based on the AmazonOrderId that you specify.
     * @param string $AmazonOrderId
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListOrderItems($AmazonOrderId)
    {
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);
        $result = array_values($response['ListOrderItemsResult']['OrderItems']);
        if (isset($result[0]['QuantityOrdered'])) {
            return $result;
        } else {
            return $result[0];
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on SellerSKU.
     * @param string $SellerSKU
     * @return bool if found, false if not found
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetProductCategoriesForSKU($SellerSKU)
    {
        $result = $this->request('GetProductCategoriesForSKU', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'SellerSKU' => $SellerSKU
        ]);
        if (isset($result['GetProductCategoriesForSKUResult']['Self'])) {
            return $result['GetProductCategoriesForSKUResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on ASIN.
     * @param string $ASIN
     * @return bool if found, false if not found
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetProductCategoriesForASIN($ASIN)
    {
        $result = $this->request('GetProductCategoriesForASIN', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ASIN' => $ASIN
        ]);
        if (isset($result['GetProductCategoriesForASINResult']['Self'])) {
            return $result['GetProductCategoriesForASINResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns a list of products and their attributes, based on a list of ASIN, GCID, SellerSKU, UPC, EAN, ISBN, and JAN values.
     * @param array $asin_array A list of id's
     * @param string [$type = 'ASIN']  the identifier name
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetMatchingProductForId(array $asin_array, $type = 'ASIN')
    {
        $asin_array = array_unique($asin_array);
        if (count($asin_array) > 5) {
            throw new Exception('Maximum number of id\'s = 5');
        }
        $counter = 1;
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'IdType' => $type
        ];
        foreach ($asin_array as $asin) {
            $array['IdList.Id.' . $counter] = $asin;
            $counter++;
        }
        $response = $this->request(
            'GetMatchingProductForId',
            $array,
            null,
            true
        );
        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
			'en-US',
			'pt-BR'
        ];
        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];
        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }
        $replace['ns2:'] = '';
        $response = $this->xmlToArray(strtr($response, $replace));
        if (isset($response['GetMatchingProductForIdResult']['@attributes'])) {
            $response['GetMatchingProductForIdResult'] = [
                0 => $response['GetMatchingProductForIdResult']
            ];
        }
        $found = [];
        $not_found = [];
        if (isset($response['GetMatchingProductForIdResult']) && is_array($response['GetMatchingProductForIdResult'])) {
            foreach ($response['GetMatchingProductForIdResult'] as $result) {
                //print_r($product);exit;
                $asin = $result['@attributes']['Id'];
                if ($result['@attributes']['status'] != 'Success') {
                    $not_found[] = $asin;
                } else {
                    if (isset($result['Products']['Product']['AttributeSets'])) {
                        $products[0] = $result['Products']['Product'];
                    } else {
                        $products = $result['Products']['Product'];
                    }
                    foreach ($products as $product) {
                        $found[$asin][] = $product;
                    }
                }
            }
        }
        return [
            'found' => $found,
            'not_found' => $not_found
        ];
    }

    /**
     * Returns a list of products and their attributes, ordered by relevancy, based on a search query that you specify.
     * @param string $query the open text query
     * @param string [$query_context_id = null] the identifier for the context within which the given search will be performed. see: http://docs.developer.amazonservices.com/en_US/products/Products_QueryContextIDs.html
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListMatchingProducts($query, $query_context_id = null)
    {
        if (trim($query) == "") {
            throw new Exception('Missing query');
        }
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'Query' => urlencode($query),
            'QueryContextId' => $query_context_id
        ];
        $response = $this->request(
            'ListMatchingProducts',
            $array,
            null,
            true
        );
        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
            'en-US'
        ];
        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];
        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }
        $replace['ns2:'] = '';
        $response = $this->xmlToArray(strtr($response, $replace));
        if (isset($response['ListMatchingProductsResult'])) {
            return $response['ListMatchingProductsResult'];
        } else {
            return ['ListMatchingProductsResult' => []];
        }
    }

    /**
     * Returns a list of reports that were created in the previous 90 days.
     * @param array [$ReportTypeList = []]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportList($ReportTypeList = [])
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }
        return $this->request('GetReportList', $array);
    }

    /**
     * Returns your active recommendations for a specific category or for all categories for a specific marketplace.
     * @param string [$RecommendationCategory = null] One of: Inventory, Selection, Pricing, Fulfillment, ListingQuality, GlobalSelling, Advertising
     * @return array/false if no result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListRecommendations($RecommendationCategory = null)
    {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($RecommendationCategory)) {
            $query['RecommendationCategory'] = $RecommendationCategory;
        }
        $result = $this->request('ListRecommendations', $query);
        if (isset($result['ListRecommendationsResult'])) {
            return $result['ListRecommendationsResult'];
        } else {
            return false;
        }
    }

    /**
     * Returns a list of marketplaces that the seller submitting the request can sell in, and a list of participations that include seller-specific information in that marketplace
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListMarketplaceParticipations()
    {
        $result = $this->request('ListMarketplaceParticipations');
        if (isset($result['ListMarketplaceParticipationsResult'])) {
            return $result['ListMarketplaceParticipationsResult'];
        } else {
            return $result;
        }
    }

    /**
     * Delete product's based on SKU
     * @param array $array array containing sku's
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function deleteProductBySKU(array $array)
    {
        $feed = [
            'MessageType' => 'Product',
            'Message' => []
        ];
        foreach ($array as $sku) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Delete',
                'Product' => [
                    'SKU' => $sku
                ]
            ];
        }
        return $this->SubmitFeed('_POST_PRODUCT_DATA_', $feed);
    }

    /**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateStock(array $array)
    {
		$messages = [];

		foreach ($array as $sku => $quantity) {
			$messages[] = ["sku" => $sku, "quantity" => $quantity];
		}

        return $this->postInventoryAvailability($messages);
	}

	/**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function postInventoryAvailability(array $array)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
		];

        foreach ($array as $item) {
			$messageID = isset($item["messageID"]) ? $item["messageID"] : rand();

            $message = [
                'MessageID' => $messageID,
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $item['sku']
                ]
			];

			if (!empty($item['available'])) {
				$message['Inventory']['Available'] = $item['available'];
			}

			if (isset($item['quantity'])) {
				$message['Inventory']['Quantity'] = (int) $item['quantity'];
			}

			if (!empty($item['latency'])) {
				$message['Inventory']['FulfillmentLatency'] = $item['latency'];
			}

			if (!empty($item['fulfillmentCenterID'])) {
				$message['Inventory']['FulfillmentCenterID'] = $item['fulfillmentCenterID'];
			}

			if (!empty($item['lookup'])) {
				$message['Inventory']['Lookup'] = $item['lookup'];
			}

			if (!empty($item['switchFulfillmentTo'])) {
				$message['Inventory']['SwitchFulfillmentTo'] = $item['switchFulfillmentTo'];
			}

            $feed['Message'][] = $message;
		}

        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
    }

    /**
     * Update a product's price
     * @param array $standardprice an array containing sku as key and price as value
     * @param array|null $saleprice
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updatePrice(array $standardprice, array $saleprice = null)
    {
        $feed = [
            'MessageType' => 'Price',
            'Message' => []
        ];
        foreach ($standardprice as $sku => $price) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'Price' => [
                    'SKU' => $sku,
                    'StandardPrice' => [
                        '_value' => strval($price),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ]
            ];
            if (isset($saleprice[$sku]) && is_array($saleprice[$sku])) {
                $feed['Message'][count($feed['Message']) - 1]['Price']['Sale'] = [
                    'StartDate' => $saleprice[$sku]['StartDate']->format(self::DATE_FORMAT),
                    'EndDate' => $saleprice[$sku]['EndDate']->format(self::DATE_FORMAT),
                    'SalePrice' => [
                        '_value' => strval($saleprice[$sku]['SalePrice']),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ];
            }
        }
        return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
    }

    /**
     * Returns the feed processing report and the Content-MD5 header.
     * @param string $FeedSubmissionId
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetFeedSubmissionResult($FeedSubmissionId)
    {
        $result = $this->request('GetFeedSubmissionResult', [
            'FeedSubmissionId' => $FeedSubmissionId
        ]);
        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];
        } else {
            return $result;
        }
    }

	/**
	 * Returns a list of feed submissions submitted in the previous 90 days that match the query parameters
	 * @param array $feedSubmissionIdList
	 * @return array
	 * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function GetFeedSubmissionList($feedSubmissionIdList)
	{
		if (count($feedSubmissionIdList) == 0) {
			return [];
		}

		$id = 0;
		foreach ($feedSubmissionIdList as $feedId) {
			$id++;
			$query["FeedSubmissionIdList.Id." . $id] = $feedId;
		}

		$result = $this->request('GetFeedSubmissionList', $query);
		$result = $result["GetFeedSubmissionListResult"]["FeedSubmissionInfo"];

		if ($id == 1) {
			$result = [$result];
		}

		return $result;
	}

    /**
     * Uploads a feed for processing by Amazon MWS.
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function SubmitFeed($FeedType, $feedContent, $debug = false, $options = [])
    {
        if (is_array($feedContent)) {
            $feedContent = $this->arrayToXml(
                array_merge([
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['Seller_Id']
                    ]
                ], $feedContent)
            );
        }

        if ($debug === true) {
            return $feedContent;
        } else {
            if ($this->debugNextFeed == true) {
                $this->debugNextFeed = false;
                return $feedContent;
            }
        }
        $purgeAndReplace = isset($options['PurgeAndReplace']) ? $options['PurgeAndReplace'] : false;

        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => ($purgeAndReplace ? 'true' : 'false'),
            'Merchant' => $this->config['Seller_Id'],
            'MarketplaceId.Id.1' => false,
            'SellerId' => false,
		];

		$setMarketplaceId = isset($options["setMarketplaceId"]) ? $options["setMarketplaceId"] : true;

		if ($setMarketplaceId) {
			$query['MarketplaceIdList.Id.1'] = $this->config['Marketplace_Id'];
		}

        $response = $this->request(
            'SubmitFeed',
            $query,
            $feedContent
        );
        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }

    /**
     * Convert an array to xml
     * @param $array array to convert
     * @param string $customRoot [$customRoot = 'AmazonEnvelope']
     * @return \Spatie\ArrayToXml\type
     */
    protected function arrayToXml(array $array, $customRoot = 'AmazonEnvelope')
    {
        return ArrayToXml::convert($array, $customRoot);
    }

    /**
     * Convert an xml string to an array
     * @param string $xmlstring
     * @return array
     */
    protected function xmlToArray($xmlstring)
    {
        return json_decode(json_encode(simplexml_load_string($xmlstring)), true);
    }

    /**
     * Creates a report request and submits the request to Amazon MWS.
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param DateTime [$StartDate = null]
     * @param DateTime [$EndDate = null]
     * @return string ReportRequestId
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function RequestReport($report, $StartDate = null, $EndDate = null)
    {
        $query = [
            'MarketplaceIdList.Id.1' => $this->config['Marketplace_Id'],
            'ReportType' => $report
        ];
        if (!is_null($StartDate)) {
            if (!is_a($StartDate, 'DateTime')) {
                throw new Exception('StartDate should be a DateTime object');
            } else {
                $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
            }
        }
        if (!is_null($EndDate)) {
            if (!is_a($EndDate, 'DateTime')) {
                throw new Exception('EndDate should be a DateTime object');
            } else {
                $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
            }
        }
        $result = $this->request(
            'RequestReport',
            $query
        );
        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'];
        } else {
            throw new Exception('Error trying to request report');
        }
    }

    /**
     * Get a report's content
     * @param string $ReportId
     * @return array|bool on succes
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReport($ReportId, $requestStatus = true)
    {
		$status = false;

		if ($requestStatus) {
			$status = $this->GetReportRequestStatus($ReportId);
			if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_NO_DATA_') {
				return [];
			}
		}

		if (!$requestStatus || ($status !== false && $status['ReportProcessingStatus'] === '_DONE_')) {
			$result = $this->request('GetReport', [
				'ReportId' => $requestStatus ? $status['GeneratedReportId'] : $ReportId
			]);
			if (is_string($result)) {
				$content = [];

				$reader = Reader::createFromString($result);
				$reader->setDelimiter("\t");
				$reader->setHeaderOffset(0);
				$headers = $reader->getHeader();
				$statement = new \League\Csv\Statement;
				foreach ($statement->process($reader) as $row) {
					$content[] = array_combine($headers, $row);
				}

				$result = $content;
			}
			return $result;
		} else {
			return false;
		}
    }

    /**
     * Get a report's processing status
     * @param string $ReportId
     * @return bool if the report is found
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportRequestStatus($ReportId)
    {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId
        ]);
        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        }
        return false;
    }

    /**
     * Get a list's inventory for Amazon's fulfillment
     *
     * @param array $sku_array
     *
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListInventorySupply($sku_array = [])
    {

        if (count($sku_array) > 50) {
            throw new Exception('Maximum amount of SKU\'s for this call is 50');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($sku_array as $key) {
            $query['SellerSkus.member.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'ListInventorySupply',
            $query
        );

        $result = [];
        if (isset($response['ListInventorySupplyResult']['InventorySupplyList']['member'])) {
            foreach ($response['ListInventorySupplyResult']['InventorySupplyList']['member'] as $index => $ListInventorySupplyResult) {
                $result[$index] = $ListInventorySupplyResult;
            }
        }

        return $result;
    }

    /**
     * Sets the shipping status of an order
     * @param array $data required data
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function setDeliveryState(array $data)
    {
        if (!isset($data["shippingDate"])) {
            $data["shippingDate"] = date("c");
        }

        if (!isset($data["carrierCode"]) && !isset($data["carrierName"])) {
            throw new Exception('Missing required carrier data');
        }

        $feed = [
            'MessageType' => 'OrderFulfillment',
            'Message' => [
                'MessageID' => rand(),
                "OrderFulfillment" => [
                    "AmazonOrderID" => $data["orderId"],
                    "FulfillmentDate" => $data["shippingDate"]
                ]
            ]
        ];
        $fulfillmentData = [];


        if (isset($data["carrierCode"])) {
            $fulfillmentData["CarrierCode"] = $data["carrierCode"];
        } elseif (isset($data["carrierName"])) {
            $fulfillmentData["CarrierName"] = $data["carrierName"];
        }

        if (isset($data["shippingMethod"])) {
            $fulfillmentData["ShippingMethod"] = $data["shippingMethod"];
        }


        if (isset($data["trackingCode"])) {
            $fulfillmentData["ShipperTrackingNumber"] = $data["trackingCode"];
        }

        if (sizeof($fulfillmentData) > 0) {
            $feed["Message"]["OrderFulfillment"]["FulfillmentData"] = $fulfillmentData;
        }
        $feed = $this->SubmitFeed('_POST_ORDER_FULFILLMENT_DATA_', $feed);

        return $feed;
    }

    /**
     * Post to create or update a product (_POST_FLAT_FILE_LISTINGS_DATA_)
     * @param object|array Product or array of Custom objects
     * @param string $template
     * @param null $version
     * @param null $signature
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function postProduct($MWSProduct, $template = 'Custom', $version = null, $signature = null)
    {
        if (!is_array($MWSProduct)) {
            $MWSProduct = [$MWSProduct];
        }

        $encoding = in_array($this->config['Marketplace_Id'], ['AAHKV2X7AFYLW', 'A1VC38T7YXB528']) ?
            'UTF-8' : 'iso-8859-16';

        $encoder = (new CharsetConverter())->inputEncoding('UTF-8')->outputEncoding($encoding);
        $csv = Writer::createFromFileObject(new SplTempFileObject(2097152));
        $csv->setDelimiter("\t");
        $csv->addFormatter($encoder);

        $csv->insertOne(['TemplateType=' . $template, 'Version=' . $version, 'TemplateSignature=' . $signature]);

        $header = array_keys($MWSProduct[0]->toArray());

        $csv->insertOne($header);
        $csv->insertOne($header);
        foreach ($MWSProduct as $product) {
            $csv->insertOne(
                array_values($product->toArray())
            );
        }

        return $this->SubmitFeed('_POST_FLAT_FILE_LISTINGS_DATA_', $csv);

    }

    /**
     * Returns financial events for a given order by it's id
     *
     * @param string $AmazonOrderId
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByOrderId($AmazonOrderId)
    {
        $query = ['AmazonOrderId' => $AmazonOrderId];
        $response = $this->request('ListFinancialEvents', $query);
        return $this->processListFinancialEventsResponse($response);
    }

    /**
     * Returns financial events for a given financial event group id
     *
     * @param $groupId
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByEventGroupId($groupId)
    {
        $query = ['FinancialEventGroupId' => $groupId];
        $response = $this->request('ListFinancialEvents', $query);
        return $this->processListFinancialEventsResponse($response);
    }

    /**
     * Returns financial events for a given financial events date range
     *
     * @param DateTime $from
     * @param DateTime|null $till
     *
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByDateRange(DateTime $from, DateTime $till = null)
    {
        $query = [
            'PostedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
        ];
        if (!is_null($till)) {
            $query['PostedBefore'] = gmdate(self::DATE_FORMAT, $till->getTimestamp());
        }
        $response = $this->request('ListFinancialEvents', $query);
        return $this->processListFinancialEventsResponse($response);
    }

    /**
     * Processes list financial events response
     *
     * @param array $response
     * @param string $fieldName
     *
     * @return array
     */
    protected function processListFinancialEventsResponse($response, $fieldName = 'ListFinancialEventsResult')
    {
        if (!isset($response[$fieldName]['FinancialEvents'])) {
            return [];
        }
        $data = $response[$fieldName]['FinancialEvents'];
        // We remove empty lists
        $data = array_filter($data, function ($item) {
            return count($item) > 0;
        });
        if (isset($response[$fieldName]['NextToken'])) {
            // Remove ==, I've seen cases when Amazon servers fails otherwise
            $data['ListFinancialEvents'] = $data;
            $data['NextToken'] = rtrim($response[$fieldName]['NextToken'], '=');
            return $data;
        }
        return ['ListFinancialEvents' => $data];
    }

    /**
     * Returns the next page of financial events using the NextToken parameter
     *
     * @param string $nextToken
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByNextToken($nextToken)
    {
        $query = [
            'NextToken' => $nextToken,
        ];
        $response = $this->request(
            'ListFinancialEventsByNextToken',
            $query
        );
        return $this->processListFinancialEventsResponse($response, 'ListFinancialEventsByNextTokenResult');
    }

    /**
     * @param array $ReportTypeList
     * @param $limit
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportRequestList($ReportTypeList = null, $limit = null)
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }
        $array['MaxCount'] = $limit;
        return $this->request('GetReportRequestList', $array);
    }

    /**
     * @param array $ReportTypeList
     * @param $nextToken
     * @param $limit
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportListByNextToken($ReportTypeList = [], $nextToken = null, $limit = null)
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }
        if ($nextToken != null) {
            $array['NextToken'] = $nextToken;
        }
        $array['MaxCount'] = $limit;
        return $this->request('GetReportListByNextToken', $array);
	}

	/**
     * Specifies a new destination where you want to receive notifications.
     * @param MCS\Model\Destination $destination
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function RegisterDestination(Destination $destination)
	{
		$query = [];
		$query["MarketplaceId"] = $this->config['Marketplace_Id'];
		$query["Destination.DeliveryChannel"] = $destination->getDeliveryChannel();

		foreach ($destination->getAttributeList() as $id => $attribute) {
			$query["Destination.AttributeList.member.{$id}.Key"] = $attribute["Key"];
			$query["Destination.AttributeList.member.{$id}.Value"] = $attribute["Value"];
		}

		return $this->request('RegisterDestination', $query);
	}

	/**
     * Creates a new subscription for the specified notification type and destination.
     * @param MCS\Model\Subscription $subscription
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function CreateSubscription(Subscription $subscription)
	{
		$query = [];
		$query["MarketplaceId"] = $this->config['Marketplace_Id'];
		$query["Subscription.NotificationType"] = $subscription->getNotificationType();
		$query["Subscription.IsEnabled"] = $subscription->isEnabled();
		$query["Subscription.Destination.DeliveryChannel"] = $subscription->getDestination()->getDeliveryChannel();

		foreach ($subscription->getDestination()->getAttributeList() as $id => $attribute) {
			$query["Subscription.Destination.AttributeList.member.{$id}.Key"] = $attribute["Key"];
			$query["Subscription.Destination.AttributeList.member.{$id}.Value"] = $attribute["Value"];
		}

		return $this->request('CreateSubscription', $query);
	}

	/**
     * Deletes the subscription for the specified notification type and destination.
     * @param MCS\Model\Subscription $subscription
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function DeleteSubscription(Subscription $subscription)
	{
		$query = [];
		$query["MarketplaceId"] = $this->config['Marketplace_Id'];
		$query["NotificationType"] = $subscription->getNotificationType();
		$query["Destination.DeliveryChannel"] = $subscription->getDestination()->getDeliveryChannel();

		foreach ($subscription->getDestination()->getAttributeList() as $id => $attribute) {
			$query["Destination.AttributeList.member.{$id}.Key"] = $attribute["Key"];
			$query["Destination.AttributeList.member.{$id}.Value"] = $attribute["Value"];
		}

		return $this->request('DeleteSubscription', $query);
	}

	/**
     * Lists all current destinations that you have registered.
     * @param $marketPlaceId optional
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function ListRegisteredDestinations($marketplaceId = "") {
		$query = [
			"MarketplaceId" => empty($marketplaceId) ? $this->config['Marketplace_Id'] : $marketplaceId
		];

		$response = $this->request('ListRegisteredDestinations', $query);
		return $response['ListRegisteredDestinationsResult'];
	}

	/**
     * Returns a list of all your current subscriptions.
     * @param $marketPlaceId optional
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function ListSubscriptions($marketplaceId = "") {
		$query = [
			"MarketplaceId" => empty($marketplaceId) ? $this->config['Marketplace_Id'] : $marketplaceId
		];

		$response = $this->request('ListSubscriptions', $query);

		if (isset($response['ListSubscriptionsResult']["SubscriptionList"]["member"]["Destination"])) {
			return [$response['ListSubscriptionsResult']["SubscriptionList"]["member"]];
		} else {
			return $response['ListSubscriptionsResult']["SubscriptionList"]["member"];
		}
	}

	/**
     * Sends a test notification to an existing destination.
     * @param MCS\Model\Destination $destination
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function SendTestNotificationToDestination(Destination $destination) {
		$query = [];
		$query["MarketplaceId"] = $this->config['Marketplace_Id'];
		$query["Destination.DeliveryChannel"] = $destination->getDeliveryChannel();

		foreach ($destination->getAttributeList() as $id => $attribute) {
			$query["Destination.AttributeList.member.{$id}.Key"] = $attribute["Key"];
			$query["Destination.AttributeList.member.{$id}.Value"] = $attribute["Value"];
		}

		return $this->request('SendTestNotificationToDestination', $query);
	}

	/**
     * Returns the information required to generate an invoice for the shipment of a Fulfillment by Amazon order.
     * @param string $amazonShipmentId
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function GetFBAOutboundShipmentDetail($amazonShipmentId) {
		$query = [];
		$query["MarketplaceId"] = $this->config['Marketplace_Id'];
		$query["AmazonShipmentId"] = $amazonShipmentId;

		$response = $this->request('GetFBAOutboundShipmentDetail', $query);

		return $response["GetFBAOutboundShipmentDetailResult"]["ShipmentDetail"];
	}

	/**
     * Submits shipment invoice data for a given shipment.
     * @param string $amazonShipmentId
     * @param string $invoiceContent
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function submitFBAOutboundShipmentInvoice($amazonShipmentId, $invoiceContent)
    {
        if (is_array($invoiceContent)) {
            $invoiceContent = $this->arrayToXml($invoiceContent);
        }

        $query = [
			'MarketplaceId' => $this->config["Marketplace_Id"],
			"AmazonShipmentId" => $amazonShipmentId,
			"SellerId" => $this->config['Seller_Id'],
			"ContentMD5Value" => base64_encode(md5($invoiceContent, true))
		];

        return $this->request(
            'SubmitFBAOutboundShipmentInvoice',
            $query,
            $invoiceContent
        );
	}

	/**
     * Gets the invoice processing status for the shipments that you specify.
     * @param array $amazonShipmentId
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
	public function getFBAOutboundShipmentInvoiceStatus(array $amazonShipmentIds)
	{
		if (count($amazonShipmentIds) == 0) {
			return;
		}

		$query = [];
		$query['MarketplaceId'] = $this->config["Marketplace_Id"];
		$query['SellerId'] = $this->config['Seller_Id'];

		foreach ($amazonShipmentIds as $id => $amazonShipmentId) {
			$query["AmazonShipmentId.Id." . ($id + 1)] = $amazonShipmentId;
		}

		$response = $this->request('GetFBAOutboundShipmentInvoiceStatus', $query);

		return $response["GetFBAOutboundShipmentInvoiceStatusResult"];
	}

    /**
     * Request MWS
     *
     * @param $endPoint
     * @param array $query
     * @param null $body
     * @param bool $raw
     * @return string|array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws Exception
     */
    protected function request($endPoint, array $query = [], $body = null, $raw = false)
    {
		$this->lastRequestResponse = null;
        $endPoint = MWSEndPoint::get($endPoint);
        $merge = [
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            //'MarketplaceId.Id.1' => $this->config['Marketplace_Id'],
            'SellerId' => $this->config['Seller_Id'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date'],
        ];
        $query = array_merge($merge, $query);
        if (!isset($query['MarketplaceId.Id.1'])) {
            $query['MarketplaceId.Id.1'] = $this->config['Marketplace_Id'];
        }
        if (!is_null($this->config['MWSAuthToken']) and $this->config['MWSAuthToken'] != "") {
            $query['MWSAuthToken'] = $this->config['MWSAuthToken'];
        }
        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }
        if (isset($query['MarketplaceIdList.Id.1'])) {
            unset($query['MarketplaceId.Id.1']);
        }
        try {
            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];
            if (in_array($endPoint['action'], ['SubmitFeed', 'SubmitFBAOutboundShipmentInvoice'])) {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                if (in_array($this->config['Marketplace_Id'], ['AAHKV2X7AFYLW', 'A1VC38T7YXB528', 'A2Q3Y263D00KWC'])) {
                    $headers['Content-Type'] = 'text/xml; charset=UTF-8';
                } else {
                    $headers['Content-Type'] = 'text/xml; charset=iso-8859-16';
                }

				$headers['Host'] = $this->config['Region_Host'];

				if ($endPoint['action'] === 'SubmitFeed') {
					unset(
						$query['MarketplaceId.Id.1'],
						$query['SellerId']
					);
				}
            }
            $requestOptions = [
                'headers' => $headers,
                'body' => $body,
                'curl' => [
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2
                ]
            ];
            ksort($query);
            $query['Signature'] = base64_encode(
                hash_hmac(
                    'sha256',
                    $endPoint['method']
                    . "\n"
                    . $this->config['Region_Host']
                    . "\n"
                    . $endPoint['path']
                    . "\n"
                    . http_build_query($query, null, '&', PHP_QUERY_RFC3986),
                    $this->config['Secret_Access_Key'],
                    true
                )
            );
            $requestOptions['query'] = $query;

            if ($this->client === null) {
                $this->client = new Client();
            }
            $response = $this->client->request(
                $endPoint['method'],
                $this->config['Region_Url'] . $endPoint['path'],
                $requestOptions
			);

			$this->lastRequestResponse = $response;

            $body = (string)$response->getBody();
            if ($raw) {
                return $body;
            } else {
                if (strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml') !== false) {
                    return $this->xmlToArray($body);
                } else {
                    return $body;
                }
            }
        } catch (BadResponseException $e) {
            if ($e->hasResponse()) {
				$message = $e->getResponse();

				$this->lastRequestResponse = $message;

                $message = $message->getBody();
                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $message = (string) $error->Error->Message;
                    $code = (string) $error->Error->Code;
                }
            } else {
				$message = 'An error occured';
				$code = "";
			}

			throw new MWSException($message, $code);
        }
	}

	public function getLastRequestResponse() {
		return $this->lastRequestResponse;
	}

    public function setClient(Client $client)
    {
        $this->client = $client;
    }
}