<?php

namespace NetworkInternational\NGenius\Gateway\Validator;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Validate and processes order capture
 *
 * Class CaptureValidator
 */
class CaptureValidator extends AbstractValidator
{
    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;
    /**
     * @var BuilderInterface
     */
    protected BuilderInterface $transactionBuilder;

    /**
     * @var OrderFactory
     */
    protected OrderFactory $orderFactory;

    /**
     * CaptureValidator constructor.
     *
     * @param ResultInterfaceFactory $resultFactory
     * @param BuilderInterface $transactionBuilder
     * @param OrderFactory $orderFactory
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        BuilderInterface $transactionBuilder,
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->transactionBuilder = $transactionBuilder;
        $this->orderFactory       = $orderFactory;
        $this->orderRepository    = $orderRepository;
        parent::__construct($resultFactory);
    }

    /**
     * Performs validation of result code
     *
     * @param array $validationSubject The validation subject containing response and payment data.
     *
     * @return ResultInterface The validation result.
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $response     = SubjectReader::readResponse($validationSubject);
        $paymentDO    = SubjectReader::readPayment($validationSubject);
        $payment      = $paymentDO->getPayment();
        $orderAdapter = $paymentDO->getOrder();

        $order = $this->orderRepository->get($orderAdapter->getId());

        if (!isset($response['result']) && !is_array($response['result'])) {
            return $this->createResult(
                false,
                [__('Invalid capture transaction.')]
            );
        } else {
            $paymentData = [
                'Captured Amount' =>
                    $order->getBaseCurrency()->formatTxt($response['result']['captured_amt'])
            ];
            $payment->setTransactionId($response['result']['payment_id']);
            $transaction = $this->transactionBuilder->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($response['result']['payment_id'])
                ->setAdditionalInformation(
                    [Transaction::RAW_DETAILS => (array)$paymentData]
                )
                ->setFailSafe(true)
                ->build(
                    TransactionInterface::TYPE_CAPTURE
                );
            $payment->addTransactionCommentsToOrder($transaction, null);
            $this->orderRepository->save($order);
            $order->addStatusToHistory(
                $response['result']['order_status'],
                'The capture has been processed successfully.',
                false
            );
            $this->orderRepository->save($order);

            return $this->createResult(true, []);
        }
    }
}
