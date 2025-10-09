<?php

namespace NetworkInternational\NGenius\Model\Config;

use Magento\Framework\Data\OptionSourceInterface;

class HttpVersion implements OptionSourceInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => "CURL_HTTP_VERSION_NONE",
                'label' => __('None'),
            ],
            [
                'value' => "CURL_HTTP_VERSION_1_0",
                'label' => __('1.0'),
            ],
            [
                'value' => "CURL_HTTP_VERSION_1_1",
                'label' => __('1.1'),
            ],
            [
                'value' => "CURL_HTTP_VERSION_2_0",
                'label' => __('2.0'),
            ],
            [
                'value' => "CURL_HTTP_VERSION_2TLS",
                'label' => __('2 (TLS)'),
            ],
            [
                'value' => "CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE",
                'label' => __('2 (prior knowledge)'),
            ]
        ];
    }
}
