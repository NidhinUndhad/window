<?php

/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace Telyrx\Customization\Model\Cron;

use Telyrx\Prescriber\Model\Groups;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Order;

/**
 * Bill Class
 */
class Bill extends \ParadoxLabs\Subscriptions\Model\Cron\Bill
{
    const APPROVAL_REFILL = 'refill';

    protected $orderFactory;
    protected $groups;
    protected $invoiceService;
    protected $transactionFactory;
    protected $_resource;

    /**
     * @var \ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var \ParadoxLabs\Subscriptions\Helper\Data
     */
    protected $helper;

    /**
     * @var \ParadoxLabs\Subscriptions\Model\Service\Subscription
     */
    protected $subscriptionService;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    protected $dateProcessor;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $consoleOutputStream;

    /**
     * @var \ParadoxLabs\Subscriptions\Model\Config
     */
    protected $config;

    /**
     * @var \ParadoxLabs\Subscriptions\Model\Source\Status
     */
    protected $statusSource;

    /**
     * Bill constructor.
     *
     * @param \ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\CollectionFactory $collectionFactory
     * @param \ParadoxLabs\Subscriptions\Helper\Data $helper
     * @param \ParadoxLabs\Subscriptions\Model\Service\Subscription $subscriptionService *Proxy
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $dateProcessor
     * @param \ParadoxLabs\Subscriptions\Model\Config $config
     * @param \ParadoxLabs\Subscriptions\Model\Source\Status $statusSource
     */
    public function __construct(
        \ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\CollectionFactory $collectionFactory,
        \ParadoxLabs\Subscriptions\Helper\Data $helper,
        \ParadoxLabs\Subscriptions\Model\Service\Subscription $subscriptionService,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $dateProcessor,
        \ParadoxLabs\Subscriptions\Model\Config $config,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        Groups $groups,
        \Telyrx\Customerlogin\Helper\Data $customHelper,
        \Telyrx\Prescriber\Helper\DrxApi $drxHelper,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        \Magento\Framework\App\ResourceConnection $resource,
        \ParadoxLabs\Subscriptions\Model\Source\Status $statusSource = null
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->helper = $helper;
        $this->subscriptionService = $subscriptionService;
        $this->dateProcessor = $dateProcessor;
        $this->config = $config;
        // BC preservation -- argument added in 3.2.0
        $this->statusSource = $statusSource ?: \Magento\Framework\App\ObjectManager::getInstance()->get(
            \ParadoxLabs\Subscriptions\Model\Source\Status::class
        );
        $this->orderFactory = $orderFactory;
        $this->groups = $groups;
        $this->customHelper = $customHelper;
        $this->_drxHelper = $drxHelper;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->_resource = $resource;
    }

    /**
     * Run subscriptions billing (entry point for cron, with active check).
     *
     * @return void
     */
    public function runSubscriptionsCron()
    {
        if ($this->config->scheduledBillingIsEnabled() === true) {
            $this->runSubscriptions();
        }
    }

    /**
     * Run subscriptions billing.
     *
     * @return void
     */
    public function runSubscriptions()
    {
        if ($this->config->moduleIsActive() !== true) {
            return;
        }

        if ($this->config->groupSameDaySubscriptions() === true) {
            $this->runCombined();
        } else {
            $this->runSingle();
        }
    }

    /**
     * Run due subscriptions (single mode)
     *
     * @return $this
     */
    protected function runSingle()
    {
        $subscriptions = $this->loadSingleSubscriptions();

        if (!empty($subscriptions)) {
            $this->log(
                __(
                    'CRON-single: Running %1 subscriptions.',
                    count($subscriptions)
                )
            );

            $billed = 0;
            $failed = 0;

            foreach ($subscriptions as $subscription) {
                try {
                    $success = $this->subscriptionService->generateOrder([$subscription]);
                    if ($success === true) {
                        $appRefillStatus   = self::APPROVAL_REFILL;
                        $newStatus          = Order::STATE_NEW;
                        $processingStatus   = Order::STATE_PROCESSING;
                        $attrSetArr         = array();
                        $groupId            =   '';
                        $connection = $this->_resource->getConnection();

                        $dataText  = $subscription->getKeywordFulltext();
                        $dataLoad  = explode(' ', $dataText);
                        $orderIncrementId = end($dataLoad);
                        $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
                        $orderExplode = $subscription['increment_id'];
                        $orderData = $subscription['keyword_fulltext'];
                        $getrealOrderindex = explode($orderExplode, $orderData);
                        $realOrderindex2 = explode(' ', $getrealOrderindex[1]);
                        $originalOrderId = $realOrderindex2[1];
                        $orderM = $this->orderFactory->create()->loadByIncrementId($originalOrderId);
                        $groupId      =   $orderM->getGroupId();

                        $orderId = $order->getId();
                        $sql = "UPDATE `amasty_extrafee_order` SET `base_total_amount` = '0.0000', `total_amount` = '0.0000' WHERE `amasty_extrafee_order`.`order_id` =" . $orderId;

                        $connection->query($sql);

                        $payment = $order->getPayment();
                        $billAmount = $order->getSubtotal();
                        $order->setBaseSubtotal($billAmount);
                        //$order->setState($newStatus)->setStatus($processingStatus);
                        $order->setState($processingStatus)->setStatus($appRefillStatus);
                        $order->save();

                        /* CREATE PRESCRIPTION AT DRX */
                        $postRefillRequest = '{
                          "rx_numbers": [
                            ' . $orderM['rx_id'] . '
                          ],
                          "date_of_birth": "' . $orderM['customer_dob'] . '",
                          "delivery_method": "Delivery",
                          "patient_id": ' . $orderM['patient_id'] . '
                        }';
                        $this->_drxHelper->getRefillRequest($postRefillRequest, $order_Id, $order);
                        /* CREATE PRESCRIPTION AT DRX */
                        $payment = $order->getPayment();
                        $method = $payment->getMethodInstance();
                        $methodCode = $method->getCode();
                        if ($methodCode == "authnetcim") {
                            if ($order->canInvoice()) {
                                $invoice = $this->invoiceService->prepareInvoice($order);
                                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                                $invoice->register();
                                $invoice->getOrder()->setCustomerNoteNotify(false);
                                //$invoice->getOrder()->setIsInProcess(true);
                                $order->setState($processingStatus)->setStatus($appRefillStatus);
                                $transactionSave = $this->transactionFactory->create();
                                $transactionSave->addObject($invoice)->addObject($invoice->getOrder());
                                $transactionSave->save();
                            }
                        }
                        $this->log(__('CRON-multi: Success'));
                    }
                } catch (\Throwable $e) {
                    $success = false;

                    $this->log(
                        __(
                            'CRON-single: Subscription %1 failed. Error: %2',
                            $subscription->getIncrementId(),
                            $e->getMessage()
                        )
                    );
                }

                if ($success === true) {
                    $billed++;
                } else {
                    $failed++;
                }
            }

            $this->log(
                __(
                    'CRON-single: Ran subscriptions; %1 billed, %2 failed.',
                    $billed,
                    $failed
                )
            );
        }

        return $this;
    }

    /**
     * Run due subscriptions (combined mode -- group multiple from same day)
     *
     * @return $this
     */
    protected function runCombined()
    {
        $subscriptions = $this->loadCombinedSubscriptions();

        if (!empty($subscriptions)) {
            $this->log(
                __(
                    'CRON-multi: Checking %1 subscriptions.',
                    count($subscriptions)
                )
            );

            $groups = [];

            $billed = 0;
            $failed = 0;

            /**
             * Form all pending subscriptions for the day into groups.
             */
            foreach ($subscriptions as $subscription) {


                $key = $this->subscriptionService->hashFulfillmentInfo($subscription);

                if (!isset($groups[$key])) {
                    $groups[$key] = [];
                }

                $groups[$key][] = $subscription;
            }

            /**
             * Bill each group iff at least one is due.
             */
            foreach ($groups as $key => $group) {
                $this->runCombinedGroup($group, $billed, $failed);
            }

            $this->log(
                __(
                    'CRON-multi: Ran subscriptions; %1 billed, %2 failed.',
                    $billed,
                    $failed
                )
            );
        }

        return $this;
    }

    /**
     * Check the given combined subscription group for billing eligibility, and run it if valid.
     *
     * @param array $group
     * @param int $billed
     * @param int $failed
     * @return void
     */
    protected function runCombinedGroup($group, &$billed, &$failed)
    {
        $due = false;

        /** @var \ParadoxLabs\Subscriptions\Api\Data\SubscriptionInterface $subscription */
        foreach ($group as $subscription) {
            if (strtotime((string)$subscription->getNextRun()) <= time()) {
                $due = true;
                break;
            }
        }

        if ($due === true) {
            try {

                $success = $this->subscriptionService->generateOrder($group);
                if ($success === true) {
                    $appRefillStatus   = self::APPROVAL_REFILL;
                    $newStatus          = Order::STATE_NEW;
                    $processingStatus   = Order::STATE_PROCESSING;
                    $attrSetArr         = array();
                    $groupId            =   '';
                    $connection = $this->_resource->getConnection();

                    $dataText  = $subscription->getKeywordFulltext();
                    $dataLoad  = explode(' ', $dataText);
                    $orderIncrementId = end($dataLoad);
                    $order_Id = end($dataLoad);
                    $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
                    $orderExplode = $subscription['increment_id'];
                    $orderData = $subscription['keyword_fulltext'];
                    $getrealOrderindex = explode($orderExplode, $orderData);
                    $realOrderindex2 = explode(' ', $getrealOrderindex[1]);
                    $originalOrderId = $realOrderindex2[1];
                    $orderM = $this->orderFactory->create()->loadByIncrementId($originalOrderId);
                    $groupId      =   $orderM->getGroupId();

                    $orderId = $order->getId();
                    $sql = "UPDATE `amasty_extrafee_order` SET `base_total_amount` = '0.0000', `total_amount` = '0.0000' WHERE `amasty_extrafee_order`.`order_id` =" . $orderId;

                    $connection->query($sql);

                    $payment = $order->getPayment();
                    $billAmount = $order->getSubtotal();
                    $order->setBaseSubtotal($billAmount);
                    //$order->setState($newStatus)->setStatus($processingStatus);
                    $order->setState($processingStatus)->setStatus($appRefillStatus);
                    $order->save();


                    $postRefillRequest = '{
                      "rx_numbers": [
                        ' . $orderM['rx_id'] . '
                      ],
                      "date_of_birth": "' . $orderM['customer_dob'] . '",
                      "delivery_method": "Delivery",
                      "patient_id": ' . $orderM['patient_id'] . '
                    }';
                    $this->_drxHelper->getRefillRequest($postRefillRequest, $order_Id, $order);

                    $payment = $order->getPayment();
                    $method = $payment->getMethodInstance();
                    $methodCode = $method->getCode();
                    if ($methodCode == "authnetcim") {
                        if ($order->canInvoice()) {
                            $invoice = $this->invoiceService->prepareInvoice($order);
                            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                            $invoice->register();
                            $invoice->getOrder()->setCustomerNoteNotify(false);
                            //$invoice->getOrder()->setIsInProcess(true);
                            $order->setState($processingStatus)->setStatus($appRefillStatus);
                            $transactionSave = $this->transactionFactory->create();
                            $transactionSave->addObject($invoice)->addObject($invoice->getOrder());
                            $transactionSave->save();
                        }
                    }

                    $this->log(__('CRON-multi: Success'));
                }
            } catch (\Throwable $e) {
                $success = false;

                $ids = [];
                foreach ($group as $subscription) {
                    $ids[] = $subscription->getIncrementId();
                }

                $this->log(
                    __(
                        'CRON-multi: Group [%1] failed. Error: %2',
                        implode(',', $ids),
                        $e->getMessage()
                    )
                );
            }

            if ($success === true) {
                $billed += count($group);
            } else {
                $failed += count($group);
            }
        }
    }

    /**
     * Set console output stream. Used when run from command line.
     *
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return $this
     */
    public function setConsoleOutput(\Symfony\Component\Console\Output\OutputInterface $output)
    {
        $this->consoleOutputStream = $output;

        return $this;
    }

    /**
     * Write to log, and to screen if CLI.
     *
     * @param mixed $message
     * @return $this
     */
    protected function log($message)
    {
        $this->helper->log('subscriptions', $message);

        if ($this->consoleOutputStream !== null) {
            $this->consoleOutputStream->writeln((string)$message);
        }

        return $this;
    }

    /**
     * Get eligible subscriptions for individual billing.
     *
     * @return \ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\Collection
     */
    protected function loadSingleSubscriptions()
    {
        $now = $this->dateProcessor->date(null, null, false);

        /** @var \ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\Collection $subscriptions */
        $subscriptions = $this->collectionFactory->create();
        $subscriptions->addFieldToFilter(
            'next_run',
            [
                'lteq' => $now->format(\Magento\Framework\Stdlib\DateTime::DATETIME_PHP_FORMAT),
            ]
        );
        $subscriptions->addFieldToFilter(
            'status',
            [
                'in' => $this->statusSource->getBillableStatuses(),
            ]
        );

        return $subscriptions;
    }

    /**
     * Get potentially eligible subscriptions for combined billing (grouping by fulfillment info).
     *
     * @return \ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\Collection
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function loadCombinedSubscriptions()
    {
        $tomorrow = $this->dateProcessor->convertConfigTimeToUtc(
            'tomorrow',
            \Magento\Framework\Stdlib\DateTime::DATETIME_PHP_FORMAT
        );

        /** @var \ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\Collection $subscriptions */
        $subscriptions = $this->collectionFactory->create();
        $subscriptions->addFieldToFilter(
            'next_run',
            [
                'lt' => $tomorrow,
            ]
        );
        $subscriptions->addFieldToFilter(
            'status',
            [
                'in' => $this->statusSource->getBillableStatuses(),
            ]
        );

        return $subscriptions;
    }
}
