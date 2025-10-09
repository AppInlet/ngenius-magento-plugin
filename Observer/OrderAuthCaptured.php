<?php

namespace NetworkInternational\NGenius\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use NetworkInternational\NGenius\Model\ResourceModel\Core\CollectionFactory;

class OrderAuthCaptured implements ObserverInterface
{
    /**
     * @var CollectionFactory
     */
    protected CollectionFactory $collectionFactory;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        CollectionFactory $collectionFactory
    ) {
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Order capture observer to set custom order statuses accordingly
     *
     * @param Observer $observer
     *
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getInvoice()->getOrder();

        $paymentResult = $order->getPayment()->getAdditionalInformation("paymentResult") ?? null;

        if (!$paymentResult) {
            return;
        }

        $orderRef   = json_decode($paymentResult)->orderReference;
        $collection = $this->collectionFactory->create()->addFieldToFilter(
            'reference',
            $orderRef
        );
        $orderItem  = $collection->getFirstItem();

        if ($orderItem->getData()["action"] !== "AUTH"
            || (int)($orderItem->getData()["captured_amt"]) === 0
        ) {
            return;
        }

        if ($order->canShip()) {
            $order->setState($orderItem->getData()["state"]);
            $order->setStatus($orderItem->getData()["status"]);
            $order->save();
        }
    }
}
