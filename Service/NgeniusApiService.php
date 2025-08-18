<?php

namespace NetworkInternational\NGenius\Service;

use Exception;
use Laminas\Http\Request;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use NetworkInternational\NGenius\Gateway\Config\Config;
use NetworkInternational\NGenius\Gateway\Request\TokenRequest;
use Ngenius\NgeniusCommon\NgeniusHTTPCommon;
use Ngenius\NgeniusCommon\NgeniusHTTPTransfer;
use Psr\Log\LoggerInterface;
use NetworkInternational\NGenius\Gateway\Http\Client\TransactionFetch;

class NgeniusApiService
{
    private Config $config;
    private LoggerInterface $logger;
    private TokenRequest $tokenRequest;
    private string $ngeniusState;
    private bool $error;
    public const NGENIUS_EMBEDED = "_embedded";
    private TransactionFetch $transaction;

    /**
     * @param Config $config
     * @param LoggerInterface $logger
     * @param TokenRequest $tokenRequest
     * @param TransactionFetch $transaction
     */
    public function __construct(Config $config, LoggerInterface $logger, TokenRequest $tokenRequest, TransactionFetch $transaction)
    {
        $this->config       = $config;
        $this->logger       = $logger;
        $this->tokenRequest = $tokenRequest;
        $this->transaction = $transaction;
    }

    /**
     * @throws Exception
     */
    public function createInvoice($order, array $invoiceData): array
    {
        $storeId       = $order->getStoreId();
        $token         = $this->tokenRequest->getAccessToken($storeId);
        $currencyCode  = $order->getOrderCurrencyCode();
        $paymentAction = $this->config->getPaymentAction($storeId);
        $url           = $this->config->getPayByLinkUrl($storeId, $paymentAction, $currencyCode);

        $httpTransfer = new NgeniusHTTPTransfer($url, $this->config->getHttpVersion($storeId));
        $httpTransfer->setInvoiceHeaders($token);
        $httpTransfer->setMethod(Request::METHOD_POST);
        $httpTransfer->setData($invoiceData);

        $response = json_decode(NgeniusHTTPCommon::placeRequest($httpTransfer), true);

        if (isset($response['errors'])) {
            $this->logger->error('N-Genius API Error: ' . $response["errors"][0]["message"]);
            throw new Exception($response['message']);
        }

        return $response;
    }

    public function isValidResponse(array $response): bool
    {
        return isset($response['orderReference']) && isset($response['transactionType']);
    }

    /**
     * Fetch  order details.
     *
     * @param string $orderRef
     *
     * @throws NoSuchEntityException|CouldNotSaveException
     */
    public function getResponseAPI($orderRef, $storeId = null): array|bool
    {
        $request = [
            'token'   => $this->tokenRequest->getAccessToken($storeId),
            'request' => [
                'data'   => [],
                'method' => \Laminas\Http\Request::METHOD_GET,
                'uri'    => $this->config->getFetchRequestURL($orderRef, $storeId)
            ]
        ];
        $result  = $this->transaction->placeRequest($request);

        return $this->resultValidator($result);
    }

    /**
     * Validate API response.
     *
     * @param array $result
     */
    public function resultValidator($result): false|array
    {
        if (is_null($result) || (isset($result['errors']) && is_array($result['errors']))) {
            $this->error = true;

            return false;
        } else {
            $this->error        = false;
            $this->ngeniusState = $result[self::NGENIUS_EMBEDED]['payment'][0]['state'] ?? '';

            return $result;
        }
    }
}
