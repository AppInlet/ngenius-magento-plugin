<?php

namespace NetworkInternational\NGenius\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Payment\Block\Form;
use Magento\Payment\Block\Info;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Ngenius implements MethodInterface
{
    public const PAYMENT_ACTION_AUTH  = 'authorize';
    public const PAYMENT_ACTION_SALE  = 'sale';
    public const PAYMENT_ACTION_ORDER = 'order';
    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    private OrderRepositoryInterface $orderRepository;
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
    /**
     * @var InfoInterface
     */
    private InfoInterface $infoInstance;
    /**
     * @var string
     */
    private string $formBlockType = Form::class;

    /**
     * @var string
     */
    private string $infoBlockType = Info::class;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $eventManager;
    /**
     * @var Config
     */
    private Config $config;
    /**
     * @var DirectoryHelper $directoryHelper Helper for directory-related operations.
     */
    private DirectoryHelper $directoryHelper;

    /**
     * Construct
     *
     * @param ManagerInterface $eventManager
     * @param ScopeConfigInterface $scopeConfig
     * @param CoreFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param EncryptorInterface $encryptor
     * @param OrderRepositoryInterface $orderRepository
     * @param DirectoryHelper $directoryHelper
     */
    public function __construct(
        ManagerInterface $eventManager,
        ScopeConfigInterface $scopeConfig,
        CoreFactory $configFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        EncryptorInterface $encryptor,
        OrderRepositoryInterface $orderRepository,
        DirectoryHelper $directoryHelper,
    ) {
        $this->eventManager    = $eventManager;
        $this->storeManager    = $storeManager;
        $this->urlBuilder      = $urlBuilder;
        $this->encryptor       = $encryptor;
        $this->orderRepository = $orderRepository;
        $this->scopeConfig     = $scopeConfig;
        $this->directoryHelper = $directoryHelper;

        $parameters = ['params' => [Config::METHOD_CODE]];

        $this->config = $configFactory->create($parameters);
    }

    /**
     * @inheritDoc
     */
    public function getFormBlockType(): string
    {
        return $this->formBlockType;
    }

    /**
     * @inheritDoc
     */
    public function getTitle()
    {
        return $this->getConfigData('title');
    }

    /**
     * @inheritDoc
     */
    public function getConfigData($field, $storeId = null)
    {
        if ('order_place_redirect_url' === $field) {
            return $this->getOrderPlaceRedirectUrl();
        }
        if (null === $storeId) {
            $storeId = $this->getStore();
        }
        $path = 'payment/' . $this->getCode() . '/' . $field;

        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Get Order Place Redirect Url
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return $this->getCheckoutRedirectUrl();
    }

    /**
     * Checkout redirect URL getter for onepage checkout (hardcode)
     *
     * @return string
     */
    public function getCheckoutRedirectUrl(): string
    {
        return $this->urlBuilder->getUrl('ngeniusonline/redirect');
    }

    /**
     * @inheritDoc
     */
    public function getStore(): int
    {
        return $this->config->getStoreId();
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return Config::METHOD_CODE;
    }

    /**
     * @inheritDoc
     */
    public function setStore($store)
    {
        if (null === $store) {
            $store = $this->storeManager->getStore()->getId();
        }
        $this->config->setStoreId(is_object($store) ? $store->getId() : $store);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function canOrder(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canAuthorize(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canCapture(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canCapturePartial(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canCaptureOnce(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canRefund(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canRefundPartialPerInvoice(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function canVoid(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canUseInternal(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canUseCheckout(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canEdit(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canFetchTransactionInfo(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isGateway(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isOffline(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isInitializeNeeded(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canUseForCountry($country): bool
    {
        /*
       for specific country, the flag will set up as 1
       */
        if ((int)$this->getConfigData('allowspecific') === 1) {
            $availableCountries = explode(',', $this->getConfigData('specificcountry') ?? '');
            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function canUseForCurrency($currencyCode): bool
    {
        return $this->config->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * @inheritDoc
     */
    public function getInfoBlockType(): string
    {
        return $this->infoBlockType;
    }

    /**
     * @inheritDoc
     */
    public function getInfoInstance(): ?InfoInterface
    {
        return $this->infoInstance;
    }

    /**
     * @inheritDoc
     */
    public function setInfoInstance(InfoInterface $info): self
    {
        $this->infoInstance = $info;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function validate(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function order(InfoInterface $payment, $amount): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function authorize(InfoInterface $payment, $amount): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function capture(InfoInterface $payment, $amount): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function refund(InfoInterface $payment, $amount): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function cancel(InfoInterface $payment): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function void(InfoInterface $payment): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canReviewPayment(): bool
    {
        return true;
    }

    /**
     * Accepts the payment.
     *
     * This method is used to accept a payment during the payment review process.
     * It ensures that the payment is marked as accepted and can proceed further.
     *
     * @param InfoInterface $payment The payment instance being accepted.
     *
     * @return bool Returns true to indicate the payment was successfully accepted.
     */
    public function acceptPayment(InfoInterface $payment): bool
    {
        return true;
    }

    /**
     * Denies the payment during the review process.
     *
     * This method is used to deny a payment that is under review. It ensures
     * that the payment is marked as denied and cannot proceed further.
     *
     * @param InfoInterface $payment The payment instance being denied.
     *
     * @return false Always returns false to indicate the payment was denied.
     * @throws LocalizedException If the payment review action is unavailable.
     */
    public function denyPayment(InfoInterface $payment)
    {
        if (!$this->canReviewPayment()) {
            throw new LocalizedException(__('The payment review action is unavailable.'));
        }

        return false;
    }

    /**
     * @inheritdoc
     *
     * @param DataObject $data
     *
     * @return $this
     * @throws LocalizedException
     */
    public function assignData(DataObject $data)
    {
        $this->eventManager->dispatch(
            'payment_method_assign_data_' . $this->getCode(),
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        $this->eventManager->dispatch(
            'payment_method_assign_data',
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(?CartInterface $quote = null): bool
    {
        return $this->config->isMethodAvailable();
    }

    /**
     * @inheritDoc
     */
    public function isActive($storeId = null): bool
    {
        return (bool)(int)$this->getConfigData('active', $storeId);
    }

    /**
     * @inheritDoc
     */
    public function initialize($paymentAction, $stateObject): bool
    {
        return true;
    }

    /**
     * Mapper from N-Genius-specific payment actions to Magento payment actions
     *
     * @return string|null
     */
    public function getConfigPaymentAction()
    {
        return $this->config->getPaymentAction();
    }
}
