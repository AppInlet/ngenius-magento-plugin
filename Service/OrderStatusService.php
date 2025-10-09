<?php

namespace NetworkInternational\NGenius\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use NetworkInternational\NGenius\Gateway\Config\Config;
use NetworkInternational\NGenius\Gateway\Http\Client\TransactionFetch;
use NetworkInternational\NGenius\Gateway\Request\TokenRequest;
use NetworkInternational\NGenius\Model\Email\OrderSender;
use NetworkInternational\NGenius\Setup\Patch\Data\DataPatch;
use Ngenius\NgeniusCommon\Processor\ApiProcessor;
use Psr\Log\LoggerInterface;

class OrderStatusService
{
    private const         STATE_PBL_STARTED  = 'PBL_STARTED';
    private const         NGENIUS_EMBEDED    = '_embedded';
    private const         NGENIUS_STARTED    = 'STARTED';
    private const         NGENIUS_AWAIT3DS   = 'AWAIT3DS';
    private const         NGENIUS_PENDING    = 'PENDING';
    private const         NGENIUS_CANCELLED  = 'CANCELLED';
    private const         NGENIUS_FAILED     = 'FAILED';
    private const         NGENIUS_AUTHORISED = 'AUTHORISED';
    private const         NGENIUS_CAPTURED   = 'CAPTURED';
    private const         NGENIUS_PURCHASED  = 'PURCHASED';

    /**
     * @var Config $config Configuration settings for the N-Genius gateway.
     */
    private Config $config;

    /**
     * @var OrderRepositoryInterface $orderRepository Repository for managing orders.
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var LoggerInterface $logger Logger for logging messages and errors.
     */
    private LoggerInterface $logger;

    /**
     * @var OrderFactory $orderFactory Factory for creating order instances.
     */
    private OrderFactory $orderFactory;

    /**
     * @var Curl $curl HTTP client for making API requests.
     */
    private Curl $curl;

    /**
     * @var TokenRequest $tokenRequest Handles token requests for the N-Genius API.
     */
    private TokenRequest $tokenRequest;

    /**
     * @var TransactionFetch $transaction Fetches transaction details from the N-Genius API.
     */
    private TransactionFetch $transaction;

    /**
     * @var ResourceConnection $resourceConnection Provides access to the database connection.
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var OrderSender $orderSender Sends order-related emails.
     */
    private OrderSender $orderSender;

    /**
     * @var string|null $ngeniusState Current state of the N-Genius payment process.
     */
    private ?string $ngeniusState = null;

    /**
     * @var InvoiceService $invoiceService Service for managing invoices.
     */
    private InvoiceService $invoiceService;

    /**
     * @var CheckoutSession $checkoutSession Manages the checkout session data.
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var InvoiceSender $invoiceSender Sends invoice-related emails.
     */
    private InvoiceSender $invoiceSender;

    /**
     * @var BuilderInterface $transactionBuilder Builds payment transactions.
     */
    private BuilderInterface $transactionBuilder;

    /**
     * @var TransactionFactory $transactionFactory Factory for creating database transactions.
     */
    private TransactionFactory $transactionFactory;
    /**
     * @var true
     */
    /**
     * @var bool $error Indicates if an error occurred during processing.
     */
    private bool $error;

    /**
     * @var NgeniusApiService $ngeniusApiService Service for interacting with the N-Genius API.
     */
    private NgeniusApiService $ngeniusApiService;

    /**
     * @var ManagerInterface $messageManager Manages system messages for the user interface.
     */
    private ManagerInterface $messageManager;

    /**
     * @var string $errorMessage Stores error messages for logging or display.
     */
    private string $errorMessage;

    /**
     * @var InvoiceRepositoryInterface $invoiceRepository Repository for managing invoice entities.
     */
    private InvoiceRepositoryInterface $invoiceRepository;

    /**
     * @param Config $config
     * @param OrderRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     * @param OrderFactory $orderFactory
     * @param Curl $curl
     * @param TokenRequest $tokenRequest
     * @param TransactionFetch $transaction
     * @param ResourceConnection $resourceConnection
     * @param InvoiceSender $invoiceSender
     * @param CheckoutSession $checkoutSession
     * @param InvoiceService $invoiceService
     * @param OrderSender $orderSender
     * @param BuilderInterface $transactionBuilder
     * @param TransactionFactory $transactionFactory
     * @param NgeniusApiService $ngeniusApiService
     * @param ManagerInterface $messageManager
     * @param InvoiceRepositoryInterface $invoiceRepository
     */
    public function __construct(
        Config $config,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger,
        OrderFactory $orderFactory,
        Curl $curl,
        TokenRequest $tokenRequest,
        TransactionFetch $transaction,
        ResourceConnection $resourceConnection,
        InvoiceSender $invoiceSender,
        CheckoutSession $checkoutSession,
        InvoiceService $invoiceService,
        OrderSender $orderSender,
        BuilderInterface $transactionBuilder,
        TransactionFactory $transactionFactory,
        NgeniusApiService $ngeniusApiService,
        ManagerInterface $messageManager,
        InvoiceRepositoryInterface $invoiceRepository,
    ) {
        $this->config             = $config;
        $this->orderRepository    = $orderRepository;
        $this->logger             = $logger;
        $this->orderFactory       = $orderFactory;
        $this->curl               = $curl;
        $this->tokenRequest       = $tokenRequest;
        $this->transaction        = $transaction;
        $this->resourceConnection = $resourceConnection;
        $this->invoiceSender      = $invoiceSender;
        $this->checkoutSession    = $checkoutSession;
        $this->invoiceService     = $invoiceService;
        $this->orderSender        = $orderSender;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionFactory = $transactionFactory;
        $this->ngeniusApiService  = $ngeniusApiService;
        $this->messageManager     = $messageManager;
        $this->invoiceRepository  = $invoiceRepository;
    }

    /**
     * Checks if the payment method for the given order is N-Genius.
     *
     * @param Order $order The order instance to check.
     *
     * @return bool True if the payment method is N-Genius, false otherwise.
     */
    public function isNgeniusPayment(Order $order): bool
    {
        return $order->getPayment() && $order->getPayment()->getMethod() === 'ngeniusonline';
    }

    /**
     * Sets the initial status and state for the given order.
     *
     * @param Order $order The order instance to update.
     *
     * @return void
     */
    public function setInitialStatus(Order $order): void
    {
        $storeId       = $order->getStoreId();
        $initialStatus = $this->config->getInitialOrderStatus($storeId);
        $order->setState($initialStatus);
        $order->setStatus($initialStatus);
        $order->addCommentToStatusHistory(__('N-Genius payment initiated.'));
        $this->orderRepository->save($order);
    }

    /**
     * Retrieves the default status for N-Genius orders.
     *
     * @return string The default order status.
     */
    public function getDefaultStatus(): string
    {
        return DataPatch::getStatuses()[0]['status'];
    }

    /**
     * Retrieves the default state for PBL (Pay By Link) orders.
     *
     * @return string The default PBL state.
     */
    public function getDefaultPBLState(): string
    {
        return self::STATE_PBL_STARTED;
    }

    /**
     * Processes a list of normal orders, updating their state and status.
     * Interacts with the N-Genius API to handle payment processing.
     * Includes logging, error handling, and ensures no more than 5 orders
     * are processed in one execution to avoid timeouts.
     *
     * @param array $orderItems The list of order items to process.
     *
     * @return void
     */
    public function processNormalOrders(array $orderItems): void
    {
        $counter = 0;
        foreach ($orderItems as $row) {
            if ($counter >= 5) {
                $this->logger->info("N-GENIUS: Breaking loop at 5 orders to avoid timeout");
                break;
            }

            $orderItem = is_array($row) ? new DataObject($row) : $row;

            $orderItem->setData('state', 'cron');
            $orderItem->setData('status', 'cron');

            $orderRef    = (string)($orderItem->getData('reference') ?? $orderItem->getReference());
            $incrementId = (string)($orderItem->getData('order_id') ?? $orderItem->getOrderId());

            if (!$incrementId) {
                $this->logger->info("N-GENIUS: Missing order increment ID");
                continue;
            }

            $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
            if (!$order->getId()) {
                $this->logger->info("N-GENIUS: Magento order not found for Increment ID: {$incrementId}");
                continue;
            }

            try {
                $order->addCommentToStatusHistory(__('This order is being processed by the cron.'));
                $this->orderRepository->save($order);

                $storeId  = (int)$order->getStoreId();
                $result   = $this->ngeniusApiService->getResponseAPI($orderRef, $storeId);
                $embedded = self::NGENIUS_EMBEDED;

                if ($result && isset($result[$embedded]['payment']) && is_array($result[$embedded]['payment'])) {
                    $action       = $result['action'] ?? '';
                    $apiProcessor = new ApiProcessor($result);
                    $paymentState = $apiProcessor->getPaymentResult()['state'] ?? '';
                    $this->logger->info('N-GENIUS: state is ' . $paymentState);

                    if (in_array(
                        $paymentState,
                        [
                            self::NGENIUS_STARTED,
                            self::NGENIUS_AWAIT3DS,
                            self::NGENIUS_PENDING,
                            self::NGENIUS_CANCELLED
                        ],
                        true
                    )) {
                        $this->ngeniusState = self::NGENIUS_FAILED;
                    }

                    $this->processOrder($apiProcessor, $orderItem, $action);
                } else {
                    $this->logger->info("N-GENIUS: Payment result not found");
                    $order->addCommentToStatusHistory(__('N-GENIUS Payment result not found.'));
                    $this->orderRepository->save($order);
                    $this->logger->info("N-GENIUS: Result " . json_encode($result));
                }
                $counter++;
            } catch (\Exception $e) {
                $this->logger->info('N-GENIUS: exception ' . $e->getMessage());
                $order->addCommentToStatusHistory(__('N-GENIUS: Exception ' . $e->getMessage()));
                $this->orderRepository->save($order);
            }
        }
    }

    /**
     * Processes a list of Pay By Link (PBL) orders, updating their state and status.
     * Interacts with the N-Genius API to handle payment processing for PBL orders.
     * Includes logging, error handling, and skips non-N-Genius orders.
     *
     * @param array $pblOrderItems The list of PBL order items to process.
     *
     * @return void
     */
    public function processPBLOrders(array $pblOrderItems): void
    {
        if (empty($pblOrderItems)) {
            $this->logger->info("N-GENIUS PBL: No PBL orders to process");
            return;
        }

        $counter = 0;
        foreach ($pblOrderItems as $row) {
            $orderItem = is_array($row) ? new DataObject($row) : $row;

            try {
                $orderRef    = (string)($orderItem->getData('reference') ?? $orderItem->getReference());
                $incrementId = (string)($orderItem->getData('order_id') ?? $orderItem->getOrderId());
                $this->logger->info("N-GENIUS PBL: Processing order {$incrementId}");
                $order     = $this->orderFactory->create()->loadByIncrementId($incrementId);
                $createdAt = strtotime((string)($orderItem->getData('created_at') ?? $orderItem->getCreatedAt()));

                if (!$order->getId()) {
                    $this->logger->info("N-GENIUS PBL: Magento order not found for Increment ID: {$incrementId}");
                    continue;
                }

                if (!$this->isNgeniusPayment($order)) {
                    $this->logger->info("N-GENIUS PBL: Skipping non-Ngenius order: {$incrementId}");
                    continue;
                }

                $storeId = (int)$order->getStoreId();
                $result  = $this->ngeniusApiService->getResponseAPI($orderRef, $storeId);

                if ($result && isset($result[self::NGENIUS_EMBEDED]['payment'][0])) {
                    $apiProcessor = new ApiProcessor($result);
                    $paymentState = $apiProcessor->getPaymentResult()['state'] ?? '';
                    $action       = $result['action'] ?? '';
                    $this->logger->info('N-GENIUS: state is ' . $paymentState);
                    $isAbandoned      = $apiProcessor->isPaymentAbandoned();
                    $isOlderThan3Days = $createdAt < strtotime('-3 days');

                    if (!$isAbandoned || $isOlderThan3Days) {
                        $this->processOrder($apiProcessor, $orderItem, $action);
                    }
                } else {
                    $this->logger->info("N-GENIUS PBL: Payment result not found");
                    $order->addCommentToStatusHistory(__('N-GENIUS Payment result not found.'));
                    $this->orderRepository->save($order);

                    $orderItem->setData('state', 'cron');
                    $orderItem->setData('status', 'cron');
                }

                $counter++;
            } catch (\Exception $e) {
                $this->logger->info('N_GENIUS PBL: Exception ' . $e->getMessage());
                if (isset($order) && $order->getId()) {
                    $order->addCommentToStatusHistory(__('N-GENIUS PBL: Exception ' . $e->getMessage()));
                    $this->orderRepository->save($order);
                }
            }
        }
    }

    /**
     * Handles the capture payment process.
     *
     * @param Order $order The order instance.
     * @param ApiProcessor $apiProcessor The API processor instance.
     * @param string $paymentId The payment ID.
     * @param string $action The action to be performed.
     * @param array $dataTable The data table for storing results.
     *
     * @return array The updated data table.
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getCapturePayment(
        Order $order,
        ApiProcessor $apiProcessor,
        string $paymentId,
        string $action,
        array $dataTable
    ): array {
        $paymentResult = $apiProcessor->getPaymentResult();
        if ($apiProcessor->isPaymentConfirmed()) {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            $this->orderRepository->save($order);
            $this->orderSender->send($order, true);

            if ($action === "AUTH") {
                $this->orderAuthorize($order, $paymentResult, $paymentId);
            } elseif ($action === "SALE" || $action === 'PURCHASE') {
                $dataTable['captured_amt'] = $this->orderSale($order, $paymentResult, $paymentId);
            }
            $dataTable['status'] = $order->getStatus();
        } elseif ($this->ngeniusState === self::NGENIUS_STARTED) {
            $dataTable['status'] = Order::STATE_PENDING_PAYMENT;
        } else {
            // Authorisation has failed - cancel order
            // Reverse reserved stock
            $this->error        = true;
            $this->errorMessage = 'Result Code: ' . ($paymentResult['authResponse']['resultCode'] ?? 'FAILED')
                . ' Reason: ' . ($paymentResult['authResponse']['resultMessage'] ?? 'Unknown');
            $this->checkoutSession->restoreQuote();

            $payment        = $order->getPayment();
            $formattedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());

            $paymentData = [
                'Card Type'   => $paymentResult['paymentMethod']['name'] ?? '',
                'Card Number' => $paymentResult['paymentMethod']['pan'] ?? '',
                'Amount'      => $formattedPrice
            ];

            $trans = $this->transactionBuilder;

            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentId)
                ->setAdditionalInformation(
                    [Transaction::RAW_DETAILS => $paymentData]
                )
                ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                ->build(TransactionInterface::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $this->errorMessage
            );

            $payment->setParentTransactionId(null);
            $payment->save();
            $this->orderRepository->save($order);

            $transactionSave = $this->transactionFactory->create()->addObject($transaction);
            $transactionSave->save();
            $transactionId = $transaction->getTransactionId();
            $this->updateInvoice($order, false);

            $payment->setAdditionalInformation(['raw_details_info' => json_encode($paymentResult)]);

            $storeId = $order->getStoreId();

            if ($this->config->getCustomFailedOrderStatus($storeId) != null) {
                $status = $this->config->getCustomFailedOrderStatus($storeId);
            } else {
                $status = Order::STATE_CLOSED;
            }

            if ($this->config->getCustomFailedOrderState($storeId) != null) {
                $state = $this->config->getCustomFailedOrderState($storeId);
            } else {
                $state = Order::STATE_CLOSED;
            }

            $dataTable['status'] = $status;

            $order->cancel();
            $this->orderRepository->save($order);

            $order->setState($state);
            $order->setStatus($status);
            $this->orderRepository->save($order);

            $order->addCommentToStatusHistory('The payment on order has failed.')
                ->setIsCustomerNotified(false);
            $this->orderRepository->save($order);
        }

        return $dataTable;
    }

    /**
     * Authorizes the order payment.
     *
     * @param Order $order The order instance.
     * @param array $paymentResult The payment result data.
     * @param string $paymentId The payment ID.
     *
     * @return void
     * @throws Exception
     * @throws NoSuchEntityException
     */
    public function orderAuthorize(Order $order, array $paymentResult, string $paymentId): void
    {
        if ($this->ngeniusState == self::NGENIUS_AUTHORISED) {
            $payment = $order->getPayment();
            $payment->setLastTransId($paymentId);
            $payment->setTransactionId($paymentId);
            $payment->setAdditionalInformation(['paymentResult' => json_encode($paymentResult)]);
            $payment->setIsTransactionClosed(false);
            $formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());

            $paymentData = [
                'Card Type'   => $paymentResult['paymentMethod']['name'] ?? '',
                'Card Number' => $paymentResult['paymentMethod']['pan'] ?? '',
                'Amount'      => $formatedPrice
            ];

            $transactionBuilder = $this->transactionBuilder->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentId)
                ->setAdditionalInformation(
                    [Transaction::RAW_DETAILS => $paymentData]
                )->setAdditionalInformation(
                    ['paymentResult' => json_encode($paymentResult)]
                )
                ->setFailSafe(true)
                ->build(
                    TransactionInterface::TYPE_AUTH
                );

            $payment->addTransactionCommentsToOrder($transactionBuilder, null);
            $payment->setParentTransactionId(null);
            $payment->save();

            $message = 'The payment has been approved and the authorized amount is ' . $formatedPrice;

            $this->updateOrderStatus($order, null, $message);
        }
    }

    /**
     * Updates the order status.
     *
     * @param Order $order The order instance.
     * @param string|null $status The new status for the order.
     * @param string $message The message to be added to the order history.
     *
     * @return void
     * @throws NoSuchEntityException If the entity does not exist.
     */
    private function updateOrderStatus(Order $order, ?string $status, string $message): void
    {
        //Check For Custom Order Status on Payment Complete
        $storeId = $order->getStoreId();

        if ($this->config->getCustomSuccessOrderStatus($storeId) != null) {
            $status = $this->config->getCustomSuccessOrderStatus($storeId);
        }

        if ($this->config->getCustomSuccessOrderState($storeId) != null) {
            $order->setState($this->config->getCustomSuccessOrderState($storeId));
        }

        $order->addStatusToHistory($status, $message, true);
        $this->orderRepository->save($order);
    }

    /**
     * Capture/Sale path (matches controller orderSale).
     *
     * @param object $order The order instance.
     * @param array $paymentResult The payment result data.
     * @param string $paymentId The payment ID.
     *
     * @return float|null The captured amount or null if the state is not captured or purchased.
     */
    public function orderSale(object $order, array $paymentResult, string $paymentId): ?float
    {
        if ($this->ngeniusState !== self::NGENIUS_CAPTURED && $this->ngeniusState !== self::NGENIUS_PURCHASED) {
            return null;
        }

        $payment = $order->getPayment();
        $payment->setLastTransId($paymentId);
        $payment->setTransactionId($paymentId);
        $payment->setAdditionalInformation(['paymentResult' => json_encode($paymentResult)]);
        $payment->setIsTransactionClosed(false);

        $grandTotal = (float)$order->getGrandTotal();
        $formatted  = $order->getBaseCurrency()->formatTxt($grandTotal);

        $paymentData = [
            'Card Type'   => $paymentResult['paymentMethod']['name'] ?? '',
            'Card Number' => $paymentResult['paymentMethod']['pan'] ?? '',
            'Amount'      => $formatted,
        ];

        $transactionId = $paymentResult['reference'] ?? $paymentId;

        $transactionBuilder = $this->transactionBuilder
            ->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($transactionId)
            ->setAdditionalInformation([Transaction::RAW_DETAILS => (array)$paymentData])
            ->setAdditionalInformation(['paymentResult' => json_encode($paymentResult)])
            ->setFailSafe(true)
            ->build(TransactionInterface::TYPE_CAPTURE);

        $payment->addTransactionCommentsToOrder($transactionBuilder, null);
        $payment->setParentTransactionId(null);
        $payment->save();

        $message = 'The payment has been approved and the captured amount is ' . $formatted;

        $targetStatus = $order->canShip() ? Order::STATE_PROCESSING : Order::STATE_COMPLETE;
        $this->updateOrderStatus($order, $targetStatus, $message);

        $this->updateInvoice($order, true, $transactionId);

        return $grandTotal;
    }

    /**
     * Processes the order based on the provided data.
     *
     * @param ApiProcessor $apiProcessor The API processor instance.
     * @param object $orderItem The order item being processed.
     * @param string $action The action to be performed on the order.
     *
     * @return void
     */
    public function processOrder(
        ApiProcessor $apiProcessor,
        object $orderItem,
        string $action,
    ): void {
        $dataTable   = [];
        $incrementId = $orderItem->getOrderId();
        if (!$incrementId) {
            return;
        }

        $paymentId          = $apiProcessor->getPaymentId();
        $paymentResult      = $apiProcessor->getPaymentResult();
        $this->ngeniusState = strtoupper($paymentResult['state'] ?? '');

        $order   = $this->orderFactory->create()->loadByIncrementId($incrementId);
        $storeId = $order->getStoreId();

        if (!$order->getId()) {
            $orderItem->setPaymentId($paymentId);
            $orderItem->setState($this->ngeniusState);
            $orderItem->setStatus($this->ngeniusState);
            $orderItem->save();
            return;
        }

        if ($order->getStatus() === $this->config->getCustomSuccessOrderStatus($storeId)) {
            return;
        }

        $dataTable = $this->getCapturePayment(
            $order,
            $apiProcessor,
            $paymentId,
            $action,
            $dataTable
        );

        $dataTable['entity_id']  = $order->getId();
        $dataTable['payment_id'] = $paymentId;

        $this->updateTable($dataTable, $orderItem);
    }

    /**
     * Updates the invoice for the order.
     *
     * @param InvoiceInterface|Invoice $invoice The invoice instance.
     * @param string|null $transactionId The transaction ID for the invoice.
     * @param object $order The order instance.
     *
     * @return void
     */
    public function doUpdateInvoice(
        InvoiceInterface|Invoice $invoice,
        ?string $transactionId,
        object $order
    ): void {
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->setTransactionId($transactionId);
        $invoice->pay();
        $this->invoiceRepository->save($invoice);
        $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject(
            $invoice->getOrder()
        );
        $transactionSave->save();

        if ($this->config->getInvoiceSend()) {
            try {
                $this->invoiceSender->send($invoice);
                $order->addStatusHistoryComment(
                    __('Notified the customer about invoice #%1.', $invoice->getIncrementId())
                )->setIsCustomerNotified(true)->save();
            } catch (\Exception $e) {
                $this->messageManager->addError(__('We can\'t send the invoice email right now.'));
            }
        }
    }

    /**
     * Updates the invoice for the given order.
     *
     * @param object $order The order instance.
     * @param bool $flag Indicates whether to update or cancel the invoice.
     * @param string|null $transactionId The transaction ID for the invoice.
     *
     * @return void
     */
    public function updateInvoice(object $order, bool $flag, ?string $transactionId = null): void
    {
        if ($order->hasInvoices()) {
            if ($flag === false) {
                foreach ($order->getInvoiceCollection() as $invoice) {
                    $invoice->cancel()->save();
                }
            } else {
                foreach ($order->getInvoiceCollection() as $invoice) {
                    $this->doUpdateInvoice($invoice, $transactionId, $order);
                }
            }
        } elseif ($flag) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->register();
            $payment = $order->getPayment();
            $payment->setCreatedInvoice($invoice);
            $order->setPayment($payment);
            $this->doUpdateInvoice($invoice, $transactionId, $order);
        }
    }

    /**
     * Updates the database table with the provided data.
     *
     * @param array $data The data to be updated in the table.
     * @param object $orderItem The order item associated with the data.
     *
     * @return bool Returns true if the update was successful, false otherwise.
     */
    public function updateTable(array $data, object $orderItem): bool
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName  = $connection->getTableName('ngenius_networkinternational_sales_order');

            $nid = null;
            if (method_exists($orderItem, 'getNid')) {
                $nid = $orderItem->getNid();
            }
            if (!$nid && method_exists($orderItem, 'getData')) {
                $nid = $orderItem->getData('nid');
            }
            if (!$nid && property_exists($orderItem, 'nid')) {
                $nid = $orderItem->nid;
            }
            if (!$nid) {
                $this->logger->error("N-GENIUS: updateTable missing nid");
                return false;
            }

            $updateData = [
                'entity_id'  => $data['entity_id'] ?? null,
                'state'      => $this->ngeniusState ?? null,
                'status'     => $data['status'] ?? null,
                'payment_id' => $data['payment_id'] ?? null,
            ];
            if (isset($data['captured_amt'])) {
                $updateData['captured_amt'] = $data['captured_amt'];
            }

            $updateData = array_filter(
                $updateData,
                static fn($v) => $v !== null
            );

            $where  = $connection->quoteInto('nid = ?', $nid);
            $result = $connection->update($tableName, $updateData, $where);

            $this->logger->info("N-GENIUS: Updated table nid={$nid}, affected rows: {$result}");
            return true;
        } catch (\Exception $e) {
            $this->logger->error("N-GENIUS: Failed to update table: " . $e->getMessage());
            return false;
        }
    }
}
