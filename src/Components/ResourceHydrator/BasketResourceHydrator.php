<?php

declare(strict_types=1);

namespace HeidelPayment6\Components\ResourceHydrator;

use heidelpayPHP\Constants\BasketItemTypes;
use heidelpayPHP\Resources\AbstractHeidelpayResource;
use heidelpayPHP\Resources\Basket;
use heidelpayPHP\Resources\EmbeddedResources\BasketItem;
use InvalidArgumentException;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BasketResourceHydrator implements ResourceHydratorInterface
{
    /**
     * {@inheritdoc}
     */
    public function hydrateObject(
        SalesChannelContext $channelContext,
        $transaction = null
    ): AbstractHeidelpayResource {
        if (!($transaction instanceof AsyncPaymentTransactionStruct) && !($transaction instanceof OrderTransactionEntity)) {
            throw new InvalidArgumentException('Transaction struct can not be null');
        }

        $currencyPrecision = $transaction->getOrder()->getCurrency() !== null ? $transaction->getOrder()->getCurrency()->getDecimalPrecision() : 4;
        $currencyPrecision = min($currencyPrecision, 4);

        $amountTotalVat = round(
            $transaction->getOrder()->getAmountTotal() - $transaction->getOrder()->getAmountNet(),
            $currencyPrecision
        );
        $amountTotalDiscount = round(
            $this->calculateAmountTotalDiscount($transaction->getOrder()->getLineItems()),
            $currencyPrecision
        );

        if ($transaction instanceof AsyncPaymentTransactionStruct) {
            $transactionId = $transaction->getOrderTransaction()->getId();
        } else {
            $transactionId = $transaction->getId();
        }

        $heidelBasket = new Basket(
            $transactionId,
            round($transaction->getOrder()->getAmountTotal(), $currencyPrecision),
            $channelContext->getCurrency()->getIsoCode()
        );

        $heidelBasket->setAmountTotalVat($amountTotalVat);
        $heidelBasket->setAmountTotalDiscount($amountTotalDiscount);

        if (null === $transaction->getOrder()->getLineItems()) {
            return $heidelBasket;
        }

        foreach ($transaction->getOrder()->getLineItems() as $lineItem) {
            if ($lineItem->getPrice() === null) {
                $heidelBasket->addBasketItem(new BasketItem(
                    $lineItem->getLabel(),
                    round($this->getAmountByItemType($lineItem->getType(), $lineItem->getTotalPrice()), $currencyPrecision),
                    round($this->getAmountByItemType($lineItem->getType(), $lineItem->getUnitPrice()), $currencyPrecision),
                    $lineItem->getQuantity())
                );

                continue;
            }

            $amountTax = 0;
            $taxRate   = 0.0;
            foreach ($lineItem->getPrice()->getCalculatedTaxes() as $tax) {
                $amountTax += round($this->getAmountByItemType($lineItem->getType(), $tax->getTax()), $currencyPrecision);
                $taxRate += $tax->getTaxRate();
            }

            $unitPrice   = round($this->getAmountByItemType($lineItem->getType(), $lineItem->getUnitPrice()), $currencyPrecision);
            $amountGross = round($this->getAmountByItemType($lineItem->getType(), $lineItem->getTotalPrice()), $currencyPrecision);
            $amountNet   = round($amountGross - $amountTax, $currencyPrecision);

            $basketItem = new BasketItem(
                $lineItem->getLabel(),
                $amountNet,
                $unitPrice,
                $lineItem->getQuantity()
            );

            $basketItem->setVat($taxRate);
            $basketItem->setType($this->getMappedLineItemType($lineItem->getType()));
            $basketItem->setAmountVat($amountTax);
            $basketItem->setAmountGross($amountGross);
            $basketItem->setImageUrl($lineItem->getCover() ? $lineItem->getCover()->getUrl() : null);

            $heidelBasket->addBasketItem($basketItem);
        }

        $this->hydrateShippingCosts(
            $transaction,
            $heidelBasket,
            $currencyPrecision,
            $channelContext->getShippingMethod()->getName()
        );

        return $heidelBasket;
    }

    protected function getAmountByItemType(string $type, float $price): float
    {
        if ($type === PromotionProcessor::LINE_ITEM_TYPE) {
            return $price * -1;
        }

        return $price;
    }

    protected function getMappedLineItemType(string $type): string
    {
        if ($type === PromotionProcessor::LINE_ITEM_TYPE) {
            return BasketItemTypes::VOUCHER;
        }

        return BasketItemTypes::GOODS;
    }

    /**
     * @param AsyncPaymentTransactionStruct|OrderTransactionEntity $transaction
     */
    private function hydrateShippingCosts($transaction, Basket $basket, int $currencyPrecision, string $shippingMethodName): void
    {
        $shippingCosts = $transaction->getOrder()->getShippingCosts();

        if ($transaction->getOrder()->getTaxStatus() === CartPrice::TAX_STATE_FREE) {
            $dispatchBasketItem = new BasketItem();
            $dispatchBasketItem->setType(BasketItemTypes::SHIPMENT);
            $dispatchBasketItem->setTitle($shippingMethodName);
            $dispatchBasketItem->setAmountGross(round($shippingCosts->getTotalPrice(), $currencyPrecision));
            $dispatchBasketItem->setAmountPerUnit(round($shippingCosts->getUnitPrice(), $currencyPrecision));
            $dispatchBasketItem->setAmountNet(round($shippingCosts->getTotalPrice(), $currencyPrecision));
            $dispatchBasketItem->setQuantity($shippingCosts->getQuantity());

            $basket->addBasketItem($dispatchBasketItem);

            return;
        }

        foreach ($shippingCosts->getCalculatedTaxes() as $tax) {
            $price = $tax->getPrice();

            if ($transaction->getOrder()->getTaxStatus() === CartPrice::TAX_STATE_NET) {
                $price += $tax->getTax();
            }

            $dispatchBasketItem = new BasketItem();
            $dispatchBasketItem->setType(BasketItemTypes::SHIPMENT);
            $dispatchBasketItem->setTitle($shippingMethodName);
            $dispatchBasketItem->setAmountGross(round($price, $currencyPrecision));
            $dispatchBasketItem->setAmountPerUnit(round($price, $currencyPrecision));
            $dispatchBasketItem->setAmountNet(round($price - $tax->getTax(), $currencyPrecision));
            $dispatchBasketItem->setAmountVat(round($tax->getTax(), $currencyPrecision));
            $dispatchBasketItem->setQuantity($shippingCosts->getQuantity());
            $dispatchBasketItem->setVat($tax->getTaxRate());

            $basket->addBasketItem($dispatchBasketItem);
        }
    }

    private function calculateAmountTotalDiscount(OrderLineItemCollection $lineItems): float
    {
        $lineItems = array_map(
            static function ($lineItem) {
                return $lineItem->getType() === PromotionProcessor::LINE_ITEM_TYPE ? $lineItem->getTotalPrice() * -1 : 0.0;
            },
            $lineItems->getElements()
        );

        return array_sum($lineItems);
    }
}
