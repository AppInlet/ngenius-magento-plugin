<?php

namespace NetworkInternational\NGenius\Gateway\Validator;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Class VoidValidator
 *
 * Validates and processes voided orders.
 */
class VoidValidator extends AbstractValidator
{
    /**
     * @var OrderRepositoryInterface The order repository instance.
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var BuilderInterface The transaction builder instance.
     */
    protected BuilderInterface $transactionBuilder;

    /**
     * @var OrderFactory The order factory instance.
     */
    protected OrderFactory $orderFactory;

    /**
     * VoidValidator constructor.
     *
     * @param ResultInterfaceFactory $resultFactory The result factory instance.
     * @param BuilderInterface $transactionBuilder The transaction builder instance.
     * @param OrderFactory $orderFactory The order factory instance.
     * @param OrderRepositoryInterface $orderRepository The order repository instance.
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
     * Performs validation of the result code.
     *
     * @param array $validationSubject The validation subject.
     *
     * @return ResultInterface|null The validation result.
     */
    public function validate(array $validationSubject): ?ResultInterface
    {
        try {
            if (!empty($validationSubject)) {
                $response     = SubjectReader::readResponse($validationSubject);
                $paymentDO    = SubjectReader::readPayment($validationSubject);
                $orderAdapter = $paymentDO->getOrder();

                $order = $this->orderRepository->get($orderAdapter->getId());

                if (!isset($response['result']) || !is_array($response['result'])) {
                    return $this->createResult(
                        false,
                        [__('Invalid void transaction.')]
                    );
                }

                $order->addStatusToHistory(
                    $response['result']['order_status'],
                    __('The authorization has been reversed successfully.'),
                    false
                );
                $this->orderRepository->save($order);

                return $this->createResult(true, []);
            }
        } catch (\Exception $ex) {
            return $this->createResult(
                false,
                [__('Missing response data.')]
            );
        }

        return null;
    }
}
