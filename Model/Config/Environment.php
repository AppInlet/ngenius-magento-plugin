<?php

namespace NetworkInternational\NGenius\Model\Config;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Pay environment setting model
 *
 * Class Environment
 */
class Environment implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [['value' => 'uat', 'label' => __('Sandbox')], ['value' => 'live', 'label' => __('Live')]];
    }
}
