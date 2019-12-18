<?php

namespace Mundipagg\Core\Recurrence\Services;

use Mundipagg\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Mundipagg\Core\Kernel\Interfaces\PlatformOrderInterface;
use Mundipagg\Core\Kernel\Services\APIService;
use Mundipagg\Core\Kernel\Services\LocalizationService;
use Mundipagg\Core\Kernel\Services\OrderLogService;
use Mundipagg\Core\Kernel\Services\OrderService;
use Mundipagg\Core\Kernel\ValueObjects\OrderState;
use Mundipagg\Core\Kernel\ValueObjects\OrderStatus;
use Mundipagg\Core\Payment\Aggregates\Order as PaymentOrder;
use Mundipagg\Core\Payment\Services\ResponseHandlers\ErrorExceptionHandler;
use Mundipagg\Core\Recurrence\Aggregates\SubProduct;
use Mundipagg\Core\Recurrence\Aggregates\Subscription;
use Mundipagg\Core\Recurrence\Repositories\SubscriptionRepository;
use Mundipagg\Core\Recurrence\Factories\SubscriptionFactory;
use Mundipagg\Core\Recurrence\ValueObjects\PricingSchemeValueObject as PricingScheme;
use Mundipagg\Core\Recurrence\ValueObjects\SubscriptionStatus;

final class SubscriptionService
{
    private $logService;
    /**
     * @var LocalizationService
     */
    private $i18n;
    private $subscriptionItems;

    public function __construct()
    {
        $this->logService = new OrderLogService();
        $this->i18n = new LocalizationService();
    }

    public function createSubscriptionAtMundipagg(PlatformOrderInterface $platformOrder)
    {
        try {
            $orderService = new OrderService();
            $orderInfo = $orderService->getOrderInfo($platformOrder);

            $this->logService->orderInfo(
                $platformOrder->getCode(),
                'Creating order.',
                $orderInfo
            );
            //set pending
            $platformOrder->setState(OrderState::stateNew());
            $platformOrder->setStatus(OrderStatus::pending());

            //build PaymentOrder based on platformOrder
            $order = $orderService->extractPaymentOrderFromPlatformOrder($platformOrder);
            $subscription = $this->extractSubscriptionDataFromOrder($order);

            //Send through the APIService to mundipagg
            $apiService = new APIService();
            $response = $apiService->createSubscription($subscription);

            if (!$this->checkResponseStatus($response)) {
                $i18n = new LocalizationService();
                $message = $i18n->getDashboard("Can't create order.");

                throw new \Exception($message, 400);
            }

            $platformOrder->save();

            $subscriptionFactory = new SubscriptionFactory();
            $response = $subscriptionFactory->createFromPostData($response);

            $response->setPlatformOrder($platformOrder);

            $handler = $this->getResponseHandler($response);
            $handler->handle($response, $order);

            $platformOrder->save();


            return [$response];
        } catch(\Exception $e) {
            $exceptionHandler = new ErrorExceptionHandler;
            $paymentOrder = new PaymentOrder;
            $paymentOrder->setCode($platformOrder->getcode());
            $frontMessage = $exceptionHandler->handle($e, $paymentOrder);
            throw new \Exception($frontMessage, 400);
        }
    }

    private function extractSubscriptionDataFromOrder(PaymentOrder $order)
    {
        $subscription = new Subscription();

        $items = $this->getSubscriptionItems($order);

        if (empty($items[0]) || count($items) == 0) {
            throw new \Exception('Recurrence items not found', 400);
        }

        $recurrenceSettings = $items[0];

        $this->fillCreditCardData($subscription, $order);
        $this->fillSubscriptionItems($subscription, $order, $recurrenceSettings);
        $this->fillInterval($subscription);
        $this->fillBoletoData($subscription);
        $this->fillDescription($subscription);
        $this->fillShipping($subscription, $order);

        $subscription->setCode($order->getCode());
        $subscription->setCustomer($order->getCustomer());
        $subscription->setBillingType($recurrenceSettings->getBillingType());
        $subscription->setPaymentMethod($order->getPaymentMethod());

        return $subscription;
    }

    /**
     * @param PaymentOrder $order
     * @return array
     */
    private function getSubscriptionItems(PaymentOrder $order)
    {
        $recurrenceService = new RecurrenceService();
        $items = [];

        foreach ($order->getItems() as $product) {
            if ($product->getSelectedOption()) {
                $items[] =
                    $recurrenceService
                        ->getRecurrenceProductByProductId(
                            $product->getCode()
                        );
            }
        }

        return $items;
    }

    private function extractSubscriptionItemsFromOrder($order, $recurrenceSettings)
    {
        $subscriptionItems = [];

        foreach ($order->getItems() as $item) {
            $subProduct = new SubProduct();

            $subProduct->setCycles($recurrenceSettings->getCycles());
            $subProduct->setDescription($item->getDescription());
            $subProduct->setQuantity($item->getQuantity());
            $pricingScheme = PricingScheme::UNIT($item->getAmount());

            $subProduct->setPricingScheme($pricingScheme);
            $subProduct->setSelectedRepetition($item->getSelectedOption());

            $subscriptionItems[] = $subProduct;
        }

        return $subscriptionItems;
    }

    private function fillCreditCardData(&$subscription, $order)
    {
        if ($this->paymentExists($order)) {
            $payments = $order->getPayments();

            $subscription->setCardToken(
                $this->extractCreditCardTokenFromPayment($payments[0])
            );
            $subscription->setInstallments(
                $this->extractInstallmentsFromPayment($payments[0])
            );
        }
    }

    private function fillBoletoData(&$subscription)
    {
        $boletoDays = MPSetup::getModuleConfiguration()->getBoletoDueDays();
        $subscription->setBoletoDays($boletoDays);
    }

    private function fillSubscriptionItems(&$subscription, $order, $recurrenceSettings)
    {
        $this->subscriptionItems = $this->extractSubscriptionItemsFromOrder(
            $order,
            $recurrenceSettings
        );
        $subscription->setItems($this->subscriptionItems);
    }

    private function fillInterval(&$subscription)
    {
        /**
         * @todo Subscription Intervals are comming from subscription items
         */
        if (empty($this->subscriptionItems[0]->getSelectedRepetition())) {
            return;
        }

        $intervalCount =
            $this->subscriptionItems[0]
                ->getSelectedRepetition()
                ->getIntervalCount();

        $intervalType =
            $this->subscriptionItems[0]
                ->getSelectedRepetition()
                ->getInterval();

        $subscription->setIntervalType($intervalType);
        $subscription->setIntervalCount($intervalCount);
    }

    private function fillDescription(&$subscription)
    {
        $subscription->setDescription($this->subscriptionItems[0]->getDescription());
    }

    private function fillShipping(&$subscription, $order)
    {
        $orderShipping = $order->getShipping();
        $subscription->setShipping($orderShipping);
    }

    private function paymentExists($order)
    {
        $payments = $order->getPayments();
        if (isset($payments) && isset($payments[0])) {
            return true;
        }

        return false;
    }

    private function extractCreditCardTokenFromPayment($payment)
    {
        if (method_exists($payment, 'getIdentifier')) {
            return $payment->getIdentifier()->getValue();
        }

        return null;
    }

    private function extractInstallmentsFromPayment($payment)
    {
        if (method_exists($payment, 'getInstallments')) {
            return $payment->getInstallments();
        }
    }

    private function checkResponseStatus($response)
    {
        if (
            !isset($response['status']) ||
            $response['status'] == 'failed'
        ) {
            return false;
        }

        return true;
    }

    public function isSubscription($platformOrder)
    {
        $orderService = new OrderService();
        $order = $orderService->extractPaymentOrderFromPlatformOrder($platformOrder);
        $subscriptionItem = $this->getSubscriptionItems($order);

        if (count($subscriptionItem) == 0) {
            return false;
        }

        return true;
    }

    /**
     * @param Subscription $response
     * @return string
     */
    private function getResponseHandler($response)
    {
        $responseClass = get_class($response);
        $responseClass = explode('\\', $responseClass);

        $responseClass =
            'Mundipagg\\Core\\Payment\\Services\\ResponseHandlers\\' .
            end($responseClass) . 'Handler';

        return new $responseClass;
    }

    /**
     * @return array|Subscription[]
     * @throws \Mundipagg\Core\Kernel\Exceptions\InvalidParamException
     */
    public function listAll()
    {
        return $this->getSubscriptionRepository()
            ->listEntities(0, false);
    }

    /**
     * @param $subscriptionId
     * @return array
     */
    public function cancel($subscriptionId)
    {
        try {

            $subscription = $this->getSubscriptionRepository()
                ->find($subscriptionId);

            if (!$subscription) {
                $message = $this->i18n->getDashboard(
                    'Subscription not found'
                );

                $this->logService->orderInfo(
                    null,
                    $message . " ID {$subscriptionId} ."
                );

                return [
                    "message" => $message,
                    "code" => 404
                ];
            }

            if ($subscription->getStatus() == SubscriptionStatus::canceled()) {
                $message = $this->i18n->getDashboard(
                    'Subscription already canceled'
                );

                return [
                    "message" => $message,
                    "code" => 200
                ];
            }

            $apiService = new APIService();
            $apiService->cancelSubscription($subscription);

            $subscription->setStatus(SubscriptionStatus::canceled());
            $this->getSubscriptionRepository()->save($subscription);

            $message = $this->i18n->getDashboard(
                'Subscription canceled with success!'
            );

            return [
                "message" => $message,
                "code" => 200
            ];
        } catch (\Exception $exception) {

            $message = $this->i18n->getDashboard(
                'Error on cancel subscription'
            );

            $this->logService->orderInfo(
                null,
                $message . ' - ' . $exception->getMessage()
            );

            return [
                "message" => $message,
                "code" => 200
            ];
        }
    }

    /**
     * @return SubscriptionRepository
     */
    public function getSubscriptionRepository()
    {
        return new SubscriptionRepository();
    }
}