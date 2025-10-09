<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace NetworkInternational\NGenius\Observer;

use Magento\Quote\Model\Quote\Item as QuoteItem;

/**
 * Prepare array with information about used product qty and product stock item
 */
class ProductQty
{
    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore

    /**
     * Prepare array with information about used product qty and product stock item
     *
     * @param array $relatedItems
     *
     * @return array
     */
    public function getProductQty(array $relatedItems): array
    {
        $items = [];
        foreach ($relatedItems as $item) {
            $productId = $item->getProductId();
            if (!$productId) {
                continue;
            }
            $children = $item->getChildrenItems();
            if ($children) {
                foreach ($children as $childItem) {
                    $this->addItemToQtyArray($childItem, $items);
                }
            } else {
                $this->addItemToQtyArray($item, $items);
            }
        }

        return $items;
    }

    /**
     * Adds stock item qty to $items (creates new entry or increments existing one)
     *
     * @param QuoteItem $quoteItem
     * @param array $items
     *
     * @return void
     */
    protected function addItemToQtyArray(QuoteItem $quoteItem, array &$items): void
    {
        $productId = $quoteItem->getProductId();
        if (!$productId) {
            return;
        }
        if (isset($items[$productId])) {
            $items[$productId] += $quoteItem->getTotalQty();
        } else {
            $items[$productId] = $quoteItem->getTotalQty();
        }
    }
}
