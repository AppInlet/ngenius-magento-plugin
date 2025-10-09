<?php

namespace NetworkInternational\NGenius\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use NetworkInternational\NGenius\Model\CoreFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderRepositoryInterface;

class PaymentVoid implements ObserverInterface
{
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private ResourceConnection $resourceConnection;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var \NetworkInternational\NGenius\Model\CoreFactory
     */
    private CoreFactory $coreFactory;
    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @param \NetworkInternational\NGenius\Model\CoreFactory $coreFactory
     * @param ResourceConnection $resourceConnection
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        LoggerInterface $logger,
        CoreFactory $coreFactory,
        ResourceConnection $resourceConnection,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->logger             = $logger;
        $this->coreFactory        = $coreFactory;
        $this->resourceConnection = $resourceConnection;
        $this->orderRepository    = $orderRepository;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $data = $observer->getData();

        $payment = $data['payment'];
        $order   = $payment->getOrder();

        $ptid       = $payment->getParentTransactionId();
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $connection->getTableName('ngenius_networkinternational_sales_order');

        $select = $connection->select()
            ->from($tableName)
            ->where('payment_id = ?', $ptid)
            ->limit(1);

        $orderItem = $connection->fetchRow($select);

        if (!$orderItem) {
            return;
        }

        $reversed = $orderItem['state'] ?? '';

        if ($reversed !== 'REVERSED') {
            return;
        }

        $order->setState(Order::STATE_CLOSED);
        $order->setStatus('ngenius_auth_reversed');
        $this->orderRepository->save($order);
    }
}
