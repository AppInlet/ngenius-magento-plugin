<?php

namespace NetworkInternational\NGenius\Model;

use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Config
{
    public const METHOD_CODE = 'ngeniusonline';
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
    /**
     * @var Data
     */
    private Data $directoryHelper;
    /**
     * Currency codes supported by Ngenius methods
     * @var string[]
     */
    private array $supportedCurrencyCodes = ['AED'];

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param Data $directoryHelper
     */
    public function __construct(LoggerInterface $logger, ScopeConfigInterface $scopeConfig, Data $directoryHelper)
    {
        $this->logger          = $logger;
        $this->scopeConfig     = $scopeConfig;
        $this->directoryHelper = $directoryHelper;
    }

    /**
     * @var int
     */
    private int $storeId;

    /**
     * Set the store ID.
     *
     * @param int $storeId
     * @return self
     */
    public function setStoreId(int $storeId): self
    {
        $this->storeId = $storeId;

        return $this;
    }

    /**
     * @var string
     */
    private string $methodCode;

    /**
     * Set the payment method.
     *
     * @param string|MethodInterface $method
     * @return self
     */
    public function setMethod(string|MethodInterface $method): self
    {
        if ($method instanceof MethodInterface) {
            $this->methodCode = $method->getCode();
        } elseif (is_string($method)) {
            $this->methodCode = $method;
        }

        return $this;
    }

    /**
     * Store ID Getter
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * Check whether specified currency code is supported
     *
     * @param string $code
     *
     * @return bool
     */
    public function isCurrencyCodeSupported(string $code): bool
    {
        $supported = false;
        $pre       = __METHOD__ . ' : ';

        $this->logger->debug($pre . "bof and code: {$code}");

        if (in_array($code, $this->supportedCurrencyCodes)) {
            $supported = true;
        }

        $this->logger->debug($pre . "eof and supported : {$supported}");

        return $supported;
    }

    /**
     * Check whether method available for checkout or not
     *
     * @param string|null $methodCode
     *
     * @return bool
     */
    public function isMethodAvailable(?string $methodCode = null): bool
    {
        $methodCode = $methodCode ?: $this->methodCode;

        return $this->isMethodActive($methodCode);
    }

    /**
     * Check whether method active in configuration and supported for merchant country or not
     *
     * @param string $method Method code
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function isMethodActive(string $method): bool
    {
        $isEnabled = $this->scopeConfig->isSetFlag(
            "payment/{$method}/active",
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );

        return $this->isMethodSupportedForCountry($method) && $isEnabled;
    }

    /**
     * Is Method Supported For Country
     *
     * Check whether method supported for specified country or not
     * Use $methodCode and merchant country by default
     *
     * @param string|null $method
     * @param string|null $countryCode
     *
     * @return bool
     */
    public function isMethodSupportedForCountry(?string $method = null, ?string $countryCode = null): bool
    {
        if ($method === null) {
            $method = $this->getMethodCode();
        }

        if ($countryCode === null) {
            $countryCode = $this->getMerchantCountry();
        }

        return in_array($method, $this->getCountryMethods($countryCode));
    }

    /**
     * Payment method instance code getter
     *
     * @return string
     */
    public function getMethodCode(): string
    {
        return $this->methodCode;
    }

    /**
     * Return merchant country code, use default country if it not specified in General settings
     *
     * @return string|null
     */
    public function getMerchantCountry(): ?string
    {
        return $this->directoryHelper->getDefaultCountry($this->storeId);
    }

    /**
     * Return list of allowed methods for specified country iso code
     *
     * @param string|null $countryCode 2-letters iso code
     *
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getCountryMethods(?string $countryCode = null): array
    {
        $countryMethods = [
            'other' => [
                self::METHOD_CODE,
            ],

        ];
        if ($countryCode === null) {
            return $countryMethods;
        }

        return $countryMethods[$countryCode] ?? $countryMethods['other'];
    }

    /**
     * Get payment action configuration value
     *
     * @return string|null
     */
    public function getPaymentAction(): ?string
    {
        return $this->scopeConfig->getValue(
            'payment/' . self::METHOD_CODE . '/payment_action',
            ScopeInterface::SCOPE_STORE,
            $this->storeId
        );
    }
}
