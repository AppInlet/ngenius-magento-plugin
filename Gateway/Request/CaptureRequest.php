<?php

namespace NetworkInternational\NGenius\Gateway\Request;

use Laminas\Http\Request;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Helper\Formatter;
use Magento\Store\Model\StoreManagerInterface;
use NetworkInternational\NGenius\Gateway\Config\Config;
use NetworkInternational\NGenius\Helper\Version;
use NetworkInternational\NGenius\Model\CoreFactory;
use Ngenius\NgeniusCommon\Formatter\ValueFormatter;
use Magento\Framework\App\ResourceConnection;

/**
 * Request builder for payment captures
 *
 * Class CaptureRequest
 */
class CaptureRequest implements BuilderInterface
{
    use Formatter;

    /**
     * @var Config
     */
    protected Config $config;

    /**
     * @var TokenRequest
     */
    protected TokenRequest $tokenRequest;
    /**
     * @var ResourceConnection
     */
    protected ResourceConnection $resourceConnection;
    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;

    /**
     * @var CoreFactory
     */
    protected CoreFactory $coreFactory;

    /**
     * CaptureRequest constructor.
     *
     * @param Config $config Configuration instance.
     * @param TokenRequest $tokenRequest Token request handler.
     * @param StoreManagerInterface $storeManager Store manager instance.
     * @param CoreFactory $coreFactory Core factory instance.
     * @param ResourceConnection $resourceConnection Database resource connection.
     */
    public function __construct(
        Config $config,
        TokenRequest $tokenRequest,
        StoreManagerInterface $storeManager,
        CoreFactory $coreFactory,
        ResourceConnection $resourceConnection
    ) {
        $this->config             = $config;
        $this->tokenRequest       = $tokenRequest;
        $this->storeManager       = $storeManager;
        $this->coreFactory        = $coreFactory;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     *
     * @return array
     * @throws CouldNotSaveException|LocalizedException
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment   = $paymentDO->getPayment();
        $order     = $paymentDO->getOrder();
        $storeId   = $order->getStoreId();

        $transactionId = $payment->getTransactionId();

        if (!$transactionId) {
            throw new LocalizedException(__('No authorization transaction to proceed capture.'));
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName('ngenius_networkinternational_sales_order');

        $select = $connection->select()
            ->from($tableName)
            ->where('order_id = ?', $order->getOrderIncrementId())
            ->limit(1);

        $orderData = $connection->fetchRow($select);

        if (!$orderData) {
            throw new LocalizedException(__('Order item not found.'));
        }

        $formatPrice  = $this->formatPrice(SubjectReader::readAmount($buildSubject));
        $currencyCode = $orderData['currency'];
        $amount       = ValueFormatter::floatToIntRepresentation($currencyCode, $formatPrice);

        if ($this->config->isComplete($storeId)) {
            return [
                'token'   => $this->tokenRequest->getAccessToken($storeId),
                'request' => [
                    'data'   => [
                        'amount'              => [
                            'currencyCode' => $currencyCode,
                            'value'        => $amount
                        ],
                        'merchantDefinedData' => [
                            'pluginName'    => 'magento-2',
                            'pluginVersion' => Version::MODULE_VERSION
                        ]
                    ],
                    'method' => \Laminas\Http\Request::METHOD_POST,
                    'uri'    => $this->config->getOrderCaptureURL(
                        $orderData['reference'],
                        $orderData['payment_id'],
                        $storeId
                    )
                ]
            ];
        } else {
            throw new LocalizedException(__('Invalid configuration.'));
        }
    }
}
