<?php

namespace NetworkInternational\NGenius\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use NetworkInternational\NGenius\Model\CoreFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * Class Core
 * The core driver for the NGenius Report
 */
class Core extends Template
{
    /**
     * @var CoreFactory
     */
    protected CoreFactory $coreFactory;

    /**
     * @var ResourceConnection
     */
    protected ResourceConnection $resourceConnection;

    /**
     * @param Context $context
     * @param CoreFactory $coreFactory
     * @param ResourceConnection $resourceConnection
     * @param array $data
     */
    public function __construct(
        Context $context,
        CoreFactory $coreFactory,
        ResourceConnection $resourceConnection,
        array $data = []
    ) {
        $this->coreFactory        = $coreFactory;
        $this->resourceConnection = $resourceConnection;
        parent::__construct($context, $data);
        $this->setTemplate('NetworkInternational_NGenius::core/report.phtml');
    }

    /**
     * Get N-Genius orders data
     */
    public function getOrdersData(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $connection->getTableName('ngenius_networkinternational_sales_order');

        $select = $connection->select()->from($tableName);
        return $connection->fetchAll($select);
    }

    /**
     * Get header text
     */
    public function getHeaderText(): string
    {
        return __('N-Genius Orders');
    }
}
