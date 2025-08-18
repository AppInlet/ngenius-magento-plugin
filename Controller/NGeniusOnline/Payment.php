<?php

namespace NetworkInternational\NGenius\Controller\NGeniusOnline;

use Exception;
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
    protected $config;

    /**
     * @var TokenRequest
     */
    protected $tokenRequest;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var TransferFactory
     */
    protected $transferFactory;

    /**
     * @var TransactionFetch
     */
    protected $transaction;

    /**
     * @var CoreFactory
     */
    protected $coreFactory;

    /**
     * @var BuilderInterface
     */
    protected $transactionBuilder;

    /**
     * @var ResultFactory
     */
    protected $resultRedirect;

    /**
     * @var error flag
     */
    protected $error = null;

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var DataPatch::getStatuses()
     */
    protected $orderStatus;

    /**
     * @var string
     */
    protected $ngeniusState;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     *
     * @var ProductRepository
     */
    protected $productRepository;

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
     * @var Builder
     */
    protected Builder $_transactionBuilder;
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
    private OrderStatusService $orderStatusService;
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
     * @param Builder $_transactionBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderStatusService $orderStatusService
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
        Builder $_transactionBuilder,
        OrderRepositoryInterface $orderRepository,
        OrderStatusService $orderStatusService,
        NgeniusApiService $ngeniusApiService,
    ) {
        $this->request             = $request;
        $this->checkoutHelper      = $checkoutHelper;
        $this->pageFactory         = $pageFactory;
        $this->messageManager      = $messageManager;
        $this->config              = $config;
        $this->tokenRequest        = $tokenRequest;
        $this->storeManager        = $storeManager;
        $this->transferFactory     = $transferFactory;
        $this->transaction         = $transaction;
        $this->coreFactory         = $coreFactory;
        $this->transactionBuilder  = $transactionBuilder;
        $this->resultRedirect      = $resultRedirect;
        $this->invoiceService      = $invoiceService;
        $this->transactionFactory  = $transactionFactory;
        $this->invoiceSender       = $invoiceSender;
        $this->orderSender         = $orderSender;
        $this->orderFactory        = $orderFactory;
        $this->logger              = $logger;
        $this->orderStatus         = DataPatch::getStatuses();
        $this->checkoutSession     = $checkoutSession;
        $this->productCollection   = $productCollection;
        $this->serializer          = $serializer;
        $this->_transactionBuilder = $_transactionBuilder;
        $this->orderRepository     = $orderRepository;
        $this->orderStatusService  = $orderStatusService;
        $this->ngeniusApiService = $ngeniusApiService;
    }

    /**
     * Default execute function.
     *
     * @return URL
     */
    public function execute()
    {
        $resultRedirectFactory = $this->resultRedirect->create(ResultFactory::TYPE_REDIRECT);

        $storeId = $this->storeManager->getStore()->getId();

        if ($this->config->isDebugCron($storeId)) {
            $this->messageManager->addError(
                __(
                    'This is a cron debugging test, the order is still in pending.'
                )
            );

            return $resultRedirectFactory->setPath('checkout/onepage/success');
        }

        $orderRef = $this->request->getParam('ref');

        $orderItem = $this->fetchOrder('reference', $orderRef)->getFirstItem();

        if (!empty($orderItem->getPaymentId())) {
            return $resultRedirectFactory->setPath('checkout/onepage/success');
        }

        if ($orderRef) {
            $result = $this->ngeniusApiService->getResponseAPI($orderRef, $storeId);

            $embedded = self::NGENIUS_EMBEDED;
            if ($result && isset($result[$embedded]['payment']) && is_array($result[$embedded]['payment'])) {
                $action = $result['action'] ?? '';

                $apiProcessor = new ApiProcessor($result);
                $apiProcessor->processPaymentAction($action, $this->ngeniusState);
                $this->orderStatusService->processOrder($apiProcessor, $orderItem, $action);
            }
            if ($this->error) {
                $this->messageManager->addError(
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

    /**
     * Fetch order details.
     *
     * @param string $key
     * @param string $value
     *
     * @return object
     */
    public function fetchOrder($key, $value)
    {
        return $this->coreFactory->create()->getCollection()->addFieldToFilter($key, $value);
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
        $orderItems = $this->fetchOrder('state', self::NGENIUS_STARTED)
            ->addFieldToFilter('payment_id', null)
            ->addFieldToFilter('created_at', ['lteq' => date('Y-m-d H:i:s', strtotime('-1 hour'))])
            ->setOrder('nid', 'DESC');

        $pblOrderItems = $this->fetchOrder('state', $this->orderStatusService->getDefaultPBLState())
            ->addFieldToFilter('payment_id', null)
            ->addFieldToFilter('created_at', ['lteq' => date('Y-m-d H:i:s', strtotime('-1 hour'))])
            ->setOrder('nid', 'DESC');

        if ($orderItems->getItems()) {
            $this->logger->info("N-GENIUS: Found " . count($orderItems->getItems()) . " unprocessed normal order(s)");
            $this->orderStatusService->processNormalOrders($orderItems->getItems());
        }

        if ($pblOrderItems->getItems()) {
            $this->logger->info("N-GENIUS: Found " . count($pblOrderItems->getItems()) . " unprocessed PBL order(s)");
            $this->orderStatusService->processPBLOrders($pblOrderItems->getItems());
        }
    }
}
