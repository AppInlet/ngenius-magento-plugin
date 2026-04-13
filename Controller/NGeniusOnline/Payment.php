<?php

namespace NetworkInternational\NGenius\Controller\NGeniusOnline;

use Exception;
use Magento\Framework\DataObject;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Helper\Data;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Validation\ValidationException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use NetworkInternational\NGenius\Gateway\Config\Config;
use NetworkInternational\NGenius\Gateway\Http\Client\TransactionFetch;
use NetworkInternational\NGenius\Gateway\Http\TransferFactory;
use NetworkInternational\NGenius\Gateway\Request\TokenRequest;
use NetworkInternational\NGenius\Model\CoreFactory;
use NetworkInternational\NGenius\Service\NgeniusApiService;
use NetworkInternational\NGenius\Service\OrderStatusService;
use NetworkInternational\NGenius\Setup\Patch\Data\DataPatch;
use Ngenius\NgeniusCommon\Processor\ApiProcessor;
use Psr\Log\LoggerInterface;

/**
 * Class Payment
 *
 * Payment Controller responsible for payment post processing
 */
class Payment implements HttpGetActionInterface
{
    /**
     * N-Genius states
     */
    public const NGENIUS_STARTED    = 'STARTED';
    public const NGENIUS_PENDING    = 'PENDING';
    public const NGENIUS_AWAIT3DS   = 'AWAIT3DS';
    public const NGENIUS_CANCELLED  = 'CANCELLED';
    public const NGENIUS_AUTHORISED = 'AUTHORISED';
    public const NGENIUS_PURCHASED  = 'PURCHASED';
    public const NGENIUS_CAPTURED   = 'CAPTURED';
    public const NGENIUS_FAILED     = 'FAILED';
    public const NGENIUS_VOIDED     = 'VOIDED';

    public const NGENIUS_EMBEDED = "_embedded";
    /**
     * @var Config
     */
    protected Config $config;

    /**
     * @var TokenRequest
     */
    protected TokenRequest $tokenRequest;

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;

    /**
     * @var TransferFactory
     */
    protected TransferFactory $transferFactory;

    /**
     * @var TransactionFetch
     */
    protected TransactionFetch $transaction;

    /**
     * @var CoreFactory
     */
    protected CoreFactory $coreFactory;

    /**
     * @var BuilderInterface
     */
    protected BuilderInterface $transactionBuilder;

    /**
     * @var ResultFactory
     */
    protected ResultFactory $resultRedirect;

    /**
     * @var error flag
     */
    protected ?string $error = null;

    /**
     * @var InvoiceService
     */
    protected InvoiceService $invoiceService;

    /**
     * @var TransactionFactory
     */
    protected TransactionFactory $transactionFactory;

    /**
     * @var InvoiceSender
     */
    protected InvoiceSender $invoiceSender;

    /**
     * @var DataPatch::getStatuses()
     */
    protected array $orderStatus;

    /**
     * @var string
     */
    protected ?string $ngeniusState;

    /**
     * @var OrderSender
     */
    protected OrderSender $orderSender;

    /**
     * @var OrderFactory
     */
    protected OrderFactory $orderFactory;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var Session
     */
    protected Session $checkoutSession;

    /**
     *
     * @var ProductRepository
     */
    protected ProductRepository $productRepository;

    /**
     * @var RequestInterface
     */
    protected RequestInterface $request;

    /**
     * @var PageFactory
     */
    protected PageFactory $pageFactory;
    /**
     * @var SerializerInterface
     */
    protected SerializerInterface $serializer;
    /**
     * @var ManagerInterface
     */
    protected ManagerInterface $messageManager;
    /**
     * @var Data
     */
    protected Data $checkoutHelper;
    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;
    /**
     * @var Product
     */
    private Product $productCollection;
    /**
     * @var string
     */
    private string $errorMessage = 'There is an error with the payment';

    /**
     * @var OrderStatusService
     */
    private OrderStatusService $orderStatusService;
    /**
     * @var NgeniusApiService
     */
    private NgeniusApiService $ngeniusApiService;

    /**
     * Payment constructor.
     *
     * @param ManagerInterface $messageManager
     * @param PageFactory $pageFactory
     * @param RequestInterface $request
     * @param Data $checkoutHelper
     * @param Config $config
     * @param TokenRequest $tokenRequest
     * @param StoreManagerInterface $storeManager
     * @param TransferFactory $transferFactory
     * @param TransactionFetch $transaction
     * @param CoreFactory $coreFactory
     * @param BuilderInterface $transactionBuilder
     * @param ResultFactory $resultRedirect
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param InvoiceSender $invoiceSender
     * @param OrderSender $orderSender
     * @param OrderFactory $orderFactory
     * @param LoggerInterface $logger
     * @param Session $checkoutSession
     * @param Product $productCollection
     * @param SerializerInterface $serializer
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderStatusService $orderStatusService
     * @param NgeniusApiService $ngeniusApiService
     */
    public function __construct(
        ManagerInterface $messageManager,
        PageFactory $pageFactory,
        RequestInterface $request,
        Data $checkoutHelper,
        Config $config,
        TokenRequest $tokenRequest,
        StoreManagerInterface $storeManager,
        TransferFactory $transferFactory,
        TransactionFetch $transaction,
        CoreFactory $coreFactory,
        BuilderInterface $transactionBuilder,
        ResultFactory $resultRedirect,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        InvoiceSender $invoiceSender,
        OrderSender $orderSender,
        OrderFactory $orderFactory,
        LoggerInterface $logger,
        Session $checkoutSession,
        Product $productCollection,
        SerializerInterface $serializer,
        OrderRepositoryInterface $orderRepository,
        OrderStatusService $orderStatusService,
        NgeniusApiService $ngeniusApiService
    ) {
        $this->request            = $request;
        $this->checkoutHelper     = $checkoutHelper;
        $this->pageFactory        = $pageFactory;
        $this->messageManager     = $messageManager;
        $this->config             = $config;
        $this->tokenRequest       = $tokenRequest;
        $this->storeManager       = $storeManager;
        $this->transferFactory    = $transferFactory;
        $this->transaction        = $transaction;
        $this->coreFactory        = $coreFactory;
        $this->transactionBuilder = $transactionBuilder;
        $this->resultRedirect     = $resultRedirect;
        $this->invoiceService     = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceSender      = $invoiceSender;
        $this->orderSender        = $orderSender;
        $this->orderFactory       = $orderFactory;
        $this->logger             = $logger;
        $this->orderStatus        = DataPatch::getStatuses();
        $this->checkoutSession    = $checkoutSession;
        $this->productCollection  = $productCollection;
        $this->serializer         = $serializer;
        $this->orderRepository    = $orderRepository;
        $this->orderStatusService = $orderStatusService;
        $this->ngeniusApiService  = $ngeniusApiService;
    }

    /**
     * Default execute function.
     *
     * @return mixed
     */
    public function execute(): mixed
    {
        $resultRedirectFactory = $this->resultRedirect->create(ResultFactory::TYPE_REDIRECT);

        $storeId = $this->storeManager->getStore()->getId();

        if ($this->config->isDebugCron($storeId)) {
            $this->messageManager->addErrorMessage(
                __(
                    'This is a cron debugging test, the order is still in pending.'
                )
            );

            return $resultRedirectFactory->setPath('checkout/onepage/success');
        }

        $orderRef = $this->request->getParam('ref');

        $orderItems = $this->fetchOrder('reference', $orderRef);
        if (!empty($orderItems)) {
            $orderItem = reset($orderItems);

            if (!empty($orderItem['payment_id'])) {
                return $resultRedirectFactory->setPath('checkout/onepage/success');
            }

            if ($orderRef) {
                $result = $this->ngeniusApiService->getResponseAPI($orderRef, $storeId);

                $embedded = self::NGENIUS_EMBEDED;
                if ($result && isset($result[$embedded]['payment']) && is_array($result[$embedded]['payment'])) {
                    $action = $result['action'] ?? '';

                    $apiProcessor = new ApiProcessor($result);

                    $ngeniusState = $this->ngeniusApiService->getNgeniusState();
                    $apiProcessor->processPaymentAction($action, $ngeniusState);

                    $orderItemObject = new DataObject($orderItem);
                    $this->orderStatusService->setNgeniusState($ngeniusState);
                    $this->orderStatusService->processOrder($apiProcessor, $orderItemObject, $action);
                }
                if ($this->ngeniusApiService->getIsError() || $this->orderStatusService->getIsError()) {
                    $this->messageManager->addErrorMessage(
                        __(
                            'Failed! There is an issue with your payment transaction. '
                            . $this->errorMessage
                        )
                    );

                    return $resultRedirectFactory->setPath('checkout/cart');
                } else {
                    return $resultRedirectFactory->setPath('checkout/onepage/success');
                }
            } else {
                return $resultRedirectFactory->setPath('checkout');
            }
        }
        return $resultRedirectFactory->setPath('checkout');
    }

    /**
     * Fetch order details.
     *
     * @param string $key
     * @param string $value
     *
     * @return array
     */
    public function fetchOrder(string $key, string $value): array
    {
        $connection = $this->coreFactory->create()->getResource()->getConnection();
        $tableName  = $connection->getTableName('ngenius_networkinternational_sales_order');

        $select = $connection->select()
            ->from($tableName)
            ->where($key . ' = ?', $value);

        return $connection->fetchAll($select);
    }

    /**
     * Get payment id from payment response
     *
     * @param array $paymentResult
     *
     * @return false|string
     */
    public function getPaymentId(array $paymentResult): bool|string
    {
        if (isset($paymentResult['_id'])) {
            $paymentIdArr = explode(':', $paymentResult['_id']);
            return end($paymentIdArr);
        }

        return "";
    }

    /**
     * Cron Task.
     */
    public function cronTask(): void
    {
        $connection = $this->coreFactory->create()->getResource()->getConnection();
        $tableName  = $connection->getTableName('ngenius_networkinternational_sales_order');

        $startedOrdersSelect = $connection->select()
            ->from($tableName)
            ->where('state IN (?, ?)', [self::NGENIUS_STARTED, self::NGENIUS_AWAIT3DS])
            ->where('created_at <= ?', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->order('nid DESC');

        $orderRows = $connection->fetchAll($startedOrdersSelect);

        $pblOrdersSelect = $connection->select()
            ->from($tableName)
            ->where('state = ?', $this->orderStatusService->getDefaultPBLState())
            ->where('created_at <= ?', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->order('nid DESC');

        $pblOrderRows = $connection->fetchAll($pblOrdersSelect);

        $orderItems    = array_map(fn($row) => new \Magento\Framework\DataObject($row), $orderRows);
        $pblOrderItems = array_map(fn($row) => new \Magento\Framework\DataObject($row), $pblOrderRows);

        if (!empty($orderItems)) {
            $this->logger->info("N-GENIUS: Found " . count($orderItems) . " unprocessed normal order(s)");
            $this->orderStatusService->processNormalOrders($orderItems);
        } else {
            $this->logger->info("N-GENIUS: No normal orders found for cron");
        }

        if (!empty($pblOrderItems)) {
            $this->logger->info("N-GENIUS: Found " . count($pblOrderItems) . " unprocessed PBL order(s)");
            $this->orderStatusService->processPBLOrders($pblOrderItems);
        } else {
            $this->logger->info("N-GENIUS: No PBL orders found for cron");
        }
    }
}
