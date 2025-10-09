<?php

namespace NetworkInternational\NGenius\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\Asset\Repository;
use Psr\Log\LoggerInterface;

class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Repository
     */
    private Repository $assetRepo;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ConfigProvider constructor.
     *
     * @param Repository $assetRepo
     * @param LoggerInterface $logger
     */
    public function __construct(
        Repository $assetRepo,
        LoggerInterface $logger
    ) {
        $this->assetRepo = $assetRepo;
        $this->logger    = $logger;
    }

    /**
     * Retrieve configuration array.
     *
     * @return array
     */
    public function getConfig(): array
    {
        $logoUrl = $this->assetRepo->getUrl('NetworkInternational_NGenius::images/ngenius_logo.png');

        return [
            'payment' => [
                'ngeniusonline' => [
                    'logoSrc' => $logoUrl
                ]
            ]
        ];
    }
}
