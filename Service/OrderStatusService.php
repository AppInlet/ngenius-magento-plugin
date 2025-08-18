<?php

namespace NetworkInternational\NGenius\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
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
    private const STATE_PBL_STARTED = 'PBL_STARTED';
    private const NGENIUS_EMBEDED   = '_embedded';
    private const NGENIUS_STARTED   = 'STARTED';
    private const NGENIUS_AWAIT3DS  = 'AWAIT3DS';
    private const NGENIUS_PENDING   = 'PENDING';
    private const NGENIUS_CANCELLED = 'CANCELLED';
    private const NGENIUS_FAILED    = 'FAILED';
    const         NGENIUS_AUTHORISED =  'AUTHORISED';
    const         NGENIUS_CAPTURED   =  'CAPTURED';
    const         NGENIUS_PURCHASED  =  'PURCHASED';

    private Config $config;
    private OrderRepositoryInterface $orderRepository;
    private LoggerInterface $logger;
    private OrderFactory $orderFactory;
    private Curl $curl;
    private TokenRequest $tokenRequest;
    private TransactionFetch $transaction;
    private ResourceConnection $resourceConnection;
    private OrderSender $orderSender;
    private ?string $ngeniusState = null;
    private InvoiceService $invoiceService;
    private CheckoutSession $checkoutSession;
    private InvoiceSender $invoiceSender;
    private BuilderInterface $transactionBuilder;
    private Builder $_transactionBuilder;
    private TransactionFactory $transactionFactory;
    /**
     * @var true
     */
    private bool $error;
    private NgeniusApiService $ngeniusApiService;
    private ManagerInterface $messageManager;
    private string $errorMessage;

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
     * @param Builder $_transactionBuilder
     * @param TransactionFactory $transactionFactory
     * @param NgeniusApiService $ngeniusApiService
     * @param ManagerInterface $messageManager
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
        Builder $_transactionBuilder,
        TransactionFactory $transactionFactory,
        NgeniusApiService $ngeniusApiService,
        ManagerInterface $messageManager,
    ) {
        $this->config               = $config;
        $this->orderRepository      = $orderRepository;
        $this->logger               = $logger;
        $this->orderFactory         = $orderFactory;
        $this->curl                 = $curl;
        $this->tokenRequest         = $tokenRequest;
        $this->transaction          = $transaction;
        $this->resourceConnection   = $resourceConnection;
        $this->invoiceSender        = $invoiceSender;
        $this->checkoutSession      = $checkoutSession;
        $this->invoiceService       = $invoiceService;
        $this->orderSender          = $orderSender;
        $this->transactionBuilder   = $transactionBuilder;
        $this->_transactionBuilder  = $_transactionBuilder;
        $this->transactionFactory   = $transactionFactory;
        $this->ngeniusApiService    = $ngeniusApiService;
        $this->messageManager       = $messageManager;
    }

    public function isNgeniusPayment(Order $order): bool
    {
        return $order->getPayment() && $order->getPayment()->getMethod() === 'ngeniusonline';
    }

    public function setInitialStatus(Order $order): void
    {
        $storeId       = $order->getStoreId();
        $initialStatus = $this->config->getInitialOrderStatus($storeId);
        $order->setState($initialStatus);
        $order->setStatus($initialStatus);
        $order->addCommentToStatusHistory(__('N-Genius payment initiated.'));
        $this->orderRepository->save($order);
    }

    public function getDefaultStatus(): string
    {
        return DataPatch::getStatuses()[0]['status'];
    }

    public function getDefaultPBLState(): string
    {
        return self::STATE_PBL_STARTED;
    }

    public function processNormalOrders(array $orderItems): void
    {
        $counter = 0;
        foreach ($orderItems as $orderItem) {
            if ($counter >= 5) {
                $this->logger->info("N-GENIUS: Breaking loop at 5 orders to avoid timeout");
                break;
            }

            $orderItem->setState('cron');
            $orderItem->setStatus('cron');
            $orderItem->save();

            $orderRef    = $orderItem->getReference();
            $incrementId = $orderItem->getOrderId();
            $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

            if (!$order->getId()) {
                $this->logger->info("N-GENIUS: Magento order not found");
                break;
            }

            try {
                if (!$this->isNgeniusPayment($order)) {
                    $this->logger->info("N-GENIUS: Order#{$incrementId} will not be processed");
                    continue;
                }

                $this->logger->info("N-GENIUS: Processing order {$incrementId}");

                $order->addStatusHistoryComment(__('This is order is being processed by the cron.'))->save();

                $storeId = $order->getStoreId();
                $result   = $this->ngeniusApiService->getResponseAPI($orderRef, $storeId);
                $embedded = self::NGENIUS_EMBEDED;

                if ($result && isset($result[$embedded]['payment']) && is_array($result[$embedded]['payment'])) {
                    $action       = $result['action'] ?? '';
                    $apiProcessor = new ApiProcessor($result);
                    $paymentState = $apiProcessor->getPaymentResult()['state'] ?? '';

                    $this->logger->info('N-GENIUS: state is ' . $paymentState);

                    if ($paymentState === self::NGENIUS_STARTED
                        || $paymentState === self::NGENIUS_AWAIT3DS
                        || $paymentState === self::NGENIUS_PENDING
                        || $paymentState === self::NGENIUS_CANCELLED
                    ) {
                        $this->ngeniusState = self::NGENIUS_FAILED;
                    }

                    $this->processOrder($apiProcessor, $orderItem, $action);
                } else {
                    $this->logger->info("N-GENIUS: Payment result not found");
                    $order->addStatusHistoryComment(__('N-GENIUS Payment result not found.'))->save();
                    $this->logger->info("N-GENIUS: Result " . json_encode($result));
                }
                $counter++;
            } catch (\Exception $e) {
                $this->logger->info('N-GENIUS: exception ' . $e->getMessage());
                $order->addStatusHistoryComment(__('N-GENIUS: Exception ' . $e->getMessage()))->save();
            }
        }
    }

    public function processPBLOrders(array $pblOrderItems): void
    {
        if (empty($pblOrderItems)) {
            $this->logger->info("N-GENIUS PBL: No PBL orders to process");
            return;
        }

        $counter = 0;
        foreach ($pblOrderItems as $orderItem) {

            try {
                $orderRef    = $orderItem->getReference();
                $incrementId = $orderItem->getOrderId();
                $this->logger->info("N-GENIUS PBL: Processing order {$incrementId}");
                $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

                $order->addStatusHistoryComment(__('This is order is being processed by the cron.'))->save();
                $createdAt = strtotime($orderItem->getCreatedAt());

                if (!$order->getId()) {
                    $this->logger->info("N-GENIUS PBL: Magento order not found for Increment ID: {$incrementId}");
                    continue;
                }

                if (!$this->isNgeniusPayment($order)) {
                    $this->logger->info("N-GENIUS PBL: Skipping non-Ngenius order: {$incrementId}");
                    continue;
                }

                $orderRef = $orderItem->getReference();
                $storeId  = $order->getStoreId();
                $result = $this->ngeniusApiService->getResponseAPI($orderRef, $storeId);

                if ($result && isset($result[self::NGENIUS_EMBEDED]['payment'][0])) {
                    $apiProcessor  = new ApiProcessor($result);
                    $paymentState = $apiProcessor->getPaymentResult()['state'] ?? '';
                    $action        = $result['action'] ?? '';
                    $this->logger->info('N-GENIUS PBL: state is ' . $paymentState);
                    $isAbandoned       = $apiProcessor->isPaymentAbandoned();
                    $isOlderThan3Days  = $createdAt < strtotime('-3 days');

                    if (!$isAbandoned || $isOlderThan3Days) {
                        $this->processOrder($apiProcessor, $orderItem, $action);
                    }
                } else {
                    $this->logger->info("N-GENIUS PBL: Payment result not found");
                    $order->addStatusHistoryComment(__('N-GENIUS Payment result not found.'))->save();
                    $this->logger->info("N-GENIUS PBL: Result " . json_encode($result));
                    $orderItem->setState('cron');
                    $orderItem->setStatus('cron');
                    $orderItem->save();
                }

                $counter++;
            } catch (\Exception $e) {
                $this->logger->info('N_GENIUS PBL: Exception ' . $e->getMessage());
                if (isset($order) && $order->getId()) {
                    $order->addStatusHistoryComment(__('N-GENIUS PBL: Exception ' . $e->getMessage()));
                    $this->orderRepository->save($order);
                }
            }
        }
    }

    /**
     * Magento order capturing
     *
     * @param Order $order
     * @param ApiProcessor $apiProcessor
     * @param string $paymentId
     * @param string $action
     * @param array $dataTable
     *
     * @return array
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
            $order->setStatus(Order::STATE_PROCESSING)->save();
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

            $payment = $order->getPayment();
            $formattedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());

            $paymentData = [
                'Card Type'   => $paymentResult['paymentMethod']['name'] ?? '',
                'Card Number' => $paymentResult['paymentMethod']['pan'] ?? '',
                'Amount'      => $formattedPrice
            ];

            $trans = $this->_transactionBuilder;

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
            $order->save();

            $transaction->save()->getTransactionId();
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

            $order->cancel()->save();

            $order->setState($state);
            $order->setStatus($status);
            $order->save();

            $order->addStatusHistoryComment('The payment on order has failed.')
                  ->setIsCustomerNotified(false)->save();
        }

        return $dataTable;
    }

    /**
     * Order Authorize.
     *
     * @param Order $order
     * @param array $paymentResult
     * @param string $paymentId
     *
     * @return null
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
                                                               Transaction::TYPE_AUTH
                                                           );

            $payment->addTransactionCommentsToOrder($transactionBuilder, null);
            $payment->setParentTransactionId(null);
            $payment->save();

            $message = 'The payment has been approved and the authorized amount is ' . $formatedPrice;

            $this->updateOrderStatus($order, null, $message);
        }
    }

    /**
     * Order Status Updater
     *
     * @param Order $order
     * @param ?string $status
     * @param string $message
     *
     * @return void
     * @throws NoSuchEntityException
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
        $order->save();
    }

    /**
     * Capture/Sale path (matches controller orderSale).
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

        $grandTotal    = (float) $order->getGrandTotal();
        $formatted     = $order->getBaseCurrency()->formatTxt($grandTotal);

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
            ->setAdditionalInformation([Transaction::RAW_DETAILS => (array) $paymentData])
            ->setAdditionalInformation(['paymentResult' => json_encode($paymentResult)])
            ->setFailSafe(true)
            ->build(Transaction::TYPE_CAPTURE);

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
     * Mirrors controller processOrder (functional parity).
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

        $paymentId     = $apiProcessor->getPaymentId();
        $paymentResult = $apiProcessor->getPaymentResult();
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

    public function doUpdateInvoice(
        InvoiceInterface|Invoice $invoice,
        ?string $transactionId,
        object $order
    ): void {
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->setTransactionId($transactionId);
        $invoice->pay()->save();
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

    public function updateTable(array $data, object $orderItem): bool
    {
        $orderItem->setEntityId($data['entity_id']);
        $orderItem->setState($this->ngeniusState);
        $orderItem->setStatus($data['status']);
        $orderItem->setPaymentId($data['payment_id']);
        if (isset($data['captured_amt'])) {
            $orderItem->setCapturedAmt($data['captured_amt']);
        }
        $orderItem->save();

        return true;
    }
}
