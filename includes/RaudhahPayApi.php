<?php

class RaudhahPayApi extends RaudhahPayConnect
{
    public function __construct($webServiceUrl, $accessToken, $collectionId)
    {
        $this->webServiceUrl = $webServiceUrl;
        $this->accessToken = $accessToken;
        $this->collectionId = $collectionId;
    }

    public function createBill(array $params)
    {
        $route = 'collections/' . $this->collectionId . '/bills';
        $include = 'product-collections.product';
        
        list($responseCode, $body) = $this->post(
            $route,
            $params,
            $include
        );

        return [$responseCode, $this->formatBody($body)];
    }

    public function validateSignature($requestData, $signature)
    {
        return parent::validateIpnResponse($requestData, $signature);
    }

    private function formatBody($body)
    {
        return json_decode($body, true);
    }
}