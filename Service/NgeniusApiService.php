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

/**
 * Class NgeniusApiService
 *
 * Handles API interactions with the N-Genius payment gateway.
 */
class NgeniusApiService
{
    /**
     * @var Config The N-Genius configuration instance.
     */
    private Config $config;

    /**
     * @var LoggerInterface The logger instance for logging errors and information.
     */
    private LoggerInterface $logger;

    /**
     * @var TokenRequest The token request instance for fetching access tokens.
     */
    private TokenRequest $tokenRequest;

    /**
     * @var string The state of the N-Genius transaction.
     */
    private string $ngeniusState;

    /**
     * @var bool Indicates whether an error occurred during API interaction.
     */
    private bool $error;

    /**
     * @var TransactionFetch The transaction fetch instance for retrieving order details.
     */
    private TransactionFetch $transaction;
    public const NGENIUS_EMBEDED = "_embedded";

    /**
     * NgeniusApiService constructor.
     *
     * @param Config $config The N-Genius configuration instance.
     * @param LoggerInterface $logger The logger instance.
     * @param TokenRequest $tokenRequest The token request instance.
     * @param TransactionFetch $transaction The transaction fetch instance.
     */
    public function __construct(
        Config $config,
        LoggerInterface $logger,
        TokenRequest $tokenRequest,
        TransactionFetch $transaction
    ) {
        $this->config       = $config;
        $this->logger       = $logger;
        $this->tokenRequest = $tokenRequest;
        $this->transaction  = $transaction;
    }

    /**
     * Creates an invoice using the N-Genius API.
     *
     * @param \Magento\Sales\Model\Order $order The order instance.
     * @param array $invoiceData The data for creating the invoice.
     *
     * @return array The API response.
     * @throws Exception If an error occurs during the API request.
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
            throw new CouldNotSaveException(__($response["errors"][0]["message"]));
        }

        return $response;
    }

    /**
     * Validates the API response.
     *
     * @param array $response The API response to validate.
     *
     * @return bool True if the response is valid, false otherwise.
     */
    public function isValidResponse(array $response): bool
    {
        return isset($response['orderReference']) && isset($response['transactionType']);
    }

    /**
     * Fetches order details from the N-Genius API.
     *
     * @param string $orderRef The order reference.
     * @param int|null $storeId The store ID (optional).
     *
     * @return array|bool The API response or false if validation fails.
     * @throws NoSuchEntityException If the entity does not exist.
     * @throws CouldNotSaveException If the request cannot be saved.
     */
    public function getResponseAPI(string $orderRef, ?int $storeId = null): array|bool
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
     * Gets the error status of the API interaction.
     *
     * @return string|null The error status or null if not set.
     */
    public function getIsError(): ?string
    {
        return $this->error ?? null;
    }

    /**
     * Validates the API result.
     *
     * @param array $result The API result to validate.
     *
     * @return false|array The validated result or false if errors are present.
     */
    public function resultValidator(array $result): false|array
    {
        if (isset($result['errors']) && is_array($result['errors'])) {
            $this->error = true;

            return false;
        } else {
            $this->error        = false;
            $this->ngeniusState = $result[self::NGENIUS_EMBEDED]['payment'][0]['state'] ?? '';

            return $result;
        }
    }

    /**
     * Gets the current N-Genius state.
     *
     * @return string|null
     */
    public function getNgeniusState(): ?string
    {
        return $this->ngeniusState ?? null;
    }

    /**
     * Sets the N-Genius state.
     *
     * @param string $state
     * @return void
     */
    public function setNgeniusState(string $state): void
    {
        $this->ngeniusState = $state;
    }
}
