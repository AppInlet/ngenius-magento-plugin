<?php

namespace NetworkInternational\NGenius\Model\Config;

use NetworkInternational\NGenius\Setup\Patch\Data\DataPatch;

/**
 * NGenius order statuses data provider
 */
class OrderStatusProvider
{
    /**
     * Get order statuses as options
     *
     * @return array
     */
    public function getOptions(): array
    {
        $statuses = DataPatch::getStatuses();

        return [
            [
                'value' => $statuses[0]['status'],
                'label' => __($statuses[0]['label']),
            ],
        ];
    }
}
