<?php
/**
 * Copyright 2020 Vipps
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software,
 * and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED
 * TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 */

namespace Vipps\Payment\Model;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\ResourceConnection;
use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Helper\Formatter;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Processor;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Psr\Log\LoggerInterface;
use Vipps\Payment\Api\Data\QuoteInterface;
use Vipps\Payment\Api\Data\QuoteStatusInterface;
use Vipps\Payment\Gateway\Command\PaymentDetailsProvider;
use Vipps\Payment\Gateway\Exception\VippsException;
use Vipps\Payment\Gateway\Transaction\Transaction;
use Vipps\Payment\Gateway\Exception\WrongAmountException;
use Vipps\Payment\Model\Adminhtml\Source\PaymentAction;
use Vipps\Payment\Model\Exception\AcquireLockException;

/**
 * Class TransactionProcessor
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TransactionProcessor
{
    use Formatter;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var QuoteLocator
     */
    private $quoteLocator;

    /**
     * @var OrderLocator
     */
    private $orderLocator;

    /**
     * @var Processor
     */
    private $processor;

    /**
     * @var QuoteUpdater
     */
    private $quoteUpdater;

    /**
     * @var LockManager
     */
    private $lockManager;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var QuoteManagement
     */
    private $quoteManagement;

    /**
     * @var OrderManagementInterface
     */
    private $orderManagement;

    /**
     * @var PaymentDetailsProvider
     */
    private $paymentDetailsProvider;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * TransactionProcessor constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param CartRepositoryInterface $cartRepository
     * @param CartManagementInterface $cartManagement
     * @param QuoteLocator $quoteLocator
     * @param Processor $processor
     * @param QuoteUpdater $quoteUpdater
     * @param LockManager $lockManager
     * @param ConfigInterface $config
     * @param QuoteManagement $quoteManagement
     * @param OrderManagementInterface $orderManagement
     * @param PaymentDetailsProvider $paymentDetailsProvider
     * @param LoggerInterface $logger
     * @param ResourceConnection $resourceConnection
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        CartRepositoryInterface $cartRepository,
        CartManagementInterface $cartManagement,
        QuoteLocator $quoteLocator,
        OrderLocator $orderLocator,
        Processor $processor,
        QuoteUpdater $quoteUpdater,
        LockManager $lockManager,
        ConfigInterface $config,
        QuoteManagement $quoteManagement,
        OrderManagementInterface $orderManagement,
        PaymentDetailsProvider $paymentDetailsProvider,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->orderRepository = $orderRepository;
        $this->cartRepository = $cartRepository;
        $this->cartManagement = $cartManagement;
        $this->quoteLocator = $quoteLocator;
        $this->orderLocator = $orderLocator;
        $this->processor = $processor;
        $this->quoteUpdater = $quoteUpdater;
        $this->lockManager = $lockManager;
        $this->config = $config;
        $this->quoteManagement = $quoteManagement;
        $this->orderManagement = $orderManagement;
        $this->paymentDetailsProvider = $paymentDetailsProvider;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param QuoteInterface $vippsQuote
     *
     * @throws AlreadyExistsException
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws VippsException
     * @throws WrongAmountException
     * @throws AcquireLockException
     * @throws \Exception
     */
    public function process(QuoteInterface $vippsQuote)
    {
        try {
            $lockName = $this->acquireLock($vippsQuote->getReservedOrderId());

            $transaction = $this->paymentDetailsProvider->get(
                $vippsQuote->getReservedOrderId()
            );

            if ($transaction->transactionWasCancelled() || $transaction->transactionWasVoided()) {
                $this->processCancelledTransaction($vippsQuote);
            } elseif ($transaction->isTransactionReserved()) {
                $this->processReservedTransaction($vippsQuote, $transaction);
            } elseif ($transaction->isTransactionExpired()) {
                $this->processExpiredTransaction($vippsQuote);
            }

            return $transaction;
        } finally {
            if (isset($lockName)) {
                $this->releaseLock($lockName);
            }
        }
    }

    /**
     * @param QuoteInterface $vippsQuote
     *
     * @throws CouldNotSaveException
     * @throws \Exception
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function processCancelledTransaction(QuoteInterface $vippsQuote)
    {
        $order = $this->orderLocator->get($vippsQuote->getReservedOrderId());
        if ($order) {
            $this->cancelOrder($order);
        }
        $vippsQuote->setStatus(QuoteStatusInterface::STATUS_CANCELED);
        $this->quoteManagement->save($vippsQuote);
    }

    /**
     * @param QuoteInterface $vippsQuote
     * @param Transaction $transaction
     *
     * @return OrderInterface|null
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws VippsException
     * @throws WrongAmountException
     */
    private function processReservedTransaction(QuoteInterface $vippsQuote, Transaction $transaction)
    {
        $order = $this->orderLocator->get($vippsQuote->getReservedOrderId());
        if (!$order) {
            $order = $this->placeOrder($vippsQuote, $transaction);
        }

        $paymentAction = $this->config->getValue('vipps_payment_action');
        $this->processAction($paymentAction, $order, $transaction);

        $this->notify($order);

        $vippsQuote->setStatus(QuoteInterface::STATUS_RESERVED);
        $this->quoteManagement->save($vippsQuote);

        return $order;
    }

    /**
     * @param QuoteInterface $vippsQuote
     *
     * @throws CouldNotSaveException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function processExpiredTransaction(QuoteInterface $vippsQuote)
    {
        $order = $this->orderLocator->get($vippsQuote->getReservedOrderId());
        if ($order) {
            $this->cancelOrder($order);
        }

        $vippsQuote->setStatus(QuoteStatusInterface::STATUS_EXPIRED);
        $this->quoteManagement->save($vippsQuote);
    }

    /**
     * @param string|null $action
     * @param OrderInterface $order
     * @param Transaction $transaction
     *
     * @throws LocalizedException
     */
    private function processAction($action, OrderInterface $order, Transaction $transaction)
    {
        switch ($action) {
            case PaymentAction::ACTION_AUTHORIZE_CAPTURE:
                $this->capture($order);
                break;
            default:
                $this->authorize($order, $transaction);
        }
    }

    /**
     * @param $reservedOrderId
     *
     * @return string
     * @throws AlreadyExistsException
     * @throws InputException
     * @throws AcquireLockException
     * @throws \Exception
     */
    private function acquireLock($reservedOrderId)
    {
        $lockName = 'vipps_place_order_' . $reservedOrderId;
        $retries = 0;
        $canLock = $this->lockManager->lock($lockName, 10);

        while (!$canLock && ($retries < 10)) {
            usleep(200000);
            //wait for 0.2 seconds
            $retries++;
            $canLock = $this->lockManager->lock($lockName, 10);
        }

        if (!$canLock) {
            throw new AcquireLockException(
                __('Can not acquire lock for order "%1"', $reservedOrderId)
            );
        }

        return $lockName;
    }

    /**
     * @param CartInterface|Quote $quote
     * @param Transaction $transaction
     *
     * @return OrderInterface|null
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws VippsException
     * @throws WrongAmountException
     * @throws \Exception
     */
    private function placeOrder(QuoteInterface $vippsQuote, Transaction $transaction)
    {
        $quote = $this->cartRepository->get($vippsQuote->getQuoteId());
        if (!$quote) {
            throw new \Exception( //@codingStandardsIgnoreLine
                __('Could not place order. Could not find quote.')
            );
        }

        if ($vippsQuote->getReservedOrderId()
            && $quote->getReservedOrderId() !== $vippsQuote->getReservedOrderId()
        ) {
            $quote->setReservedOrderId($vippsQuote->getReservedOrderId());
            $this->cartRepository->save($quote);
        }

        if (!$quote->getReservedOrderId() || $quote->getReservedOrderId() !== $transaction->getOrderId()) {
            throw new \Exception( //@codingStandardsIgnoreLine
                __('Quote reserved order id does not match Vipps transaction order id.')
            );
        }

        if ($transaction->isExpressCheckout()) {
            $this->quoteUpdater->execute($quote, $transaction);
        }

        $quote = $this->cartRepository->get($quote->getId());

        // fix quote to be able to work from different areas (frontend/adminhtml/etc...)
        $this->fixQuote($quote);

        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->collectTotals();

        $this->validateAmount($quote, $transaction);

        // set quote active, collect totals and place order
        $quote->setIsActive(true);
        $orderId = $this->cartManagement->placeOrder($quote->getId());

        $order = $this->orderRepository->get($orderId);

        $quote->setReservedOrderId(null);
        $quote->setIsActive(false);
        $this->cartRepository->save($quote);

        return $order;
    }

    /**
     * @param CartInterface|Quote $quote
     */
    private function fixQuote($quote)
    {
        $websiteId = $quote->getStore()->getWebsiteId();
        foreach ($quote->getAllItems() as $item) {
            /** @var Quote\Item $item */
            $item->getProduct()->setWebsiteId($websiteId);
        }
    }

    /**
     * Check if reserved Order amount in vipps is the same as in Magento.
     *
     * @param CartInterface $quote
     * @param Transaction $transaction
     *
     * @return void
     * @throws WrongAmountException
     */
    private function validateAmount(CartInterface $quote, Transaction $transaction)
    {
        $quoteAmount = (int)round($this->formatPrice($quote->getGrandTotal()) * 100);
        $vippsAmount = (int)$transaction->getTransactionSummary()->getRemainingAmountToCapture();

        if ($quoteAmount != $vippsAmount) {
            throw new WrongAmountException(
                __("Quote Grand Total {$quoteAmount} does not match Transaction Amount {$vippsAmount}")
            );
        }
    }

    /**
     * Capture
     *
     * @param OrderInterface $order
     *
     * @throws LocalizedException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function capture(OrderInterface $order)
    {
        if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PAYMENT_REVIEW])) {
            return;
        }

        // preconditions
        $totalDue = $order->getTotalDue();
        $baseTotalDue = $order->getBaseTotalDue();

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $payment->setAmountAuthorized($totalDue);
        $payment->setBaseAmountAuthorized($baseTotalDue);

        // do capture
        $this->processor->capture($payment, null);
        $this->orderRepository->save($order);
    }

    /**
     * Authorize action
     *
     * @param OrderInterface $order
     * @param Transaction $transaction
     */
    private function authorize(OrderInterface $order, Transaction $transaction)
    {
        if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PAYMENT_REVIEW])) {
            return;
        }

        // preconditions
        $totalDue = $order->getTotalDue();
        $baseTotalDue = $order->getBaseTotalDue();

        $payment = $order->getPayment();
        if ($payment instanceof Payment) {
            $transactionId = $transaction->getTransactionId();
            $payment->setIsTransactionClosed(false);
            $payment->setTransactionId($transactionId);
            $payment->setTransactionAdditionalInfo(
                PaymentTransaction::RAW_DETAILS,
                $transaction->getTransactionSummary()->getData()
            );
        }

        // do authorize
        $this->processor->authorize($payment, false, $baseTotalDue);
        // base amount will be set inside
        $payment->setAmountAuthorized($totalDue);
        $this->orderRepository->save($order);
    }

    /**
     * @param $lockName
     *
     * @return bool
     * @throws InputException
     */
    private function releaseLock($lockName)
    {
        return $this->lockManager->unlock($lockName);
    }

    /**
     * @param OrderInterface $order
     */
    private function notify(OrderInterface $order)
    {
        if (!$order->getEmailSent()) {
            $this->orderManagement->notify($order->getEntityId());
        }
    }

    /**
     * @param $order
     *
     * @throws \Exception
     */
    private function cancelOrder($order): void
    {
        if ($order->getState() === Order::STATE_NEW) {
            $this->orderManagement->cancel($order->getEntityId());
        } elseif ($order->getState() === Order::STATE_PAYMENT_REVIEW) {
            $connection = $this->resourceConnection->getConnection();
            try {
                $connection->beginTransaction();

                $order->setState(Order::STATE_NEW);
                $this->orderRepository->save($order);
                $this->orderManagement->cancel($order->getEntityId());

                $connection->commit();
            } catch (\Exception $e) {
                $connection->rollBack();
                throw $e;
            }
        }
    }
}
