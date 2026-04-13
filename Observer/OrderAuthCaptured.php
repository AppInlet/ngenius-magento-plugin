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
        $orderData  = $orderItem->getData();

        if (empty($orderData)
            || !isset($orderData["action"])
            || $orderData["action"] !== "AUTH"
            || (int)($orderData["captured_amt"] ?? 0) === 0
        ) {
            return;
        }

        if ($order->canShip() && isset($orderData["state"]) && isset($orderData["status"])) {
            $order->setState($orderData["state"]);
            $order->setStatus($orderData["status"]);
            $order->save();
        }
    }
}
