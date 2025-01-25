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

namespace Telyrx\Customization\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Telyrx\Prescriber\Model\Groups;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Order;

/**
 * Bill Class
 */
class Bill extends \ParadoxLabs\Subscriptions\Controller\Adminhtml\Index\Bill
{
    const APPROVAL_REFILL = 'refill';

    /**
     * @var \ParadoxLabs\Subscriptions\Model\Service\Subscription
     */
    protected $subscriptionService;

    protected $orderFactory;
    protected $groups;
    protected $invoiceService;
    protected $transactionFactory;
    protected $_resource;

    /**
     * Save constructor.
     *
     * @param Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     * @param \Magento\Framework\Registry $registry
     * @param \ParadoxLabs\Subscriptions\Api\SubscriptionRepositoryInterface $subscriptionRepository
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \Magento\Framework\View\Result\LayoutFactory $resultLayoutFactory
     * @param \ParadoxLabs\Subscriptions\Helper\Data $helper
     * @param \ParadoxLabs\Subscriptions\Model\Service\Subscription $subscriptionService
     */
    public function __construct(
        Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Framework\Registry $registry,
        \ParadoxLabs\Subscriptions\Api\SubscriptionRepositoryInterface $subscriptionRepository,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Framework\View\Result\LayoutFactory $resultLayoutFactory,
        \ParadoxLabs\Subscriptions\Helper\Data $helper,
        \ParadoxLabs\Subscriptions\Model\Service\Subscription $subscriptionService,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        Groups $groups,
        \Telyrx\Customerlogin\Helper\Data $customHelper,
        \Telyrx\Prescriber\Helper\DrxApi $drxHelper,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        \Magento\Framework\App\ResourceConnection $resource
    ) {
        parent::__construct(
            $context,
            $resultPageFactory,
            $registry,
            $subscriptionRepository,
            $customerRepository,
            $resultLayoutFactory,
            $helper,
            $subscriptionService
        );

        $this->subscriptionService = $subscriptionService;
        $this->orderFactory = $orderFactory;
        $this->groups = $groups;
        $this->customHelper = $customHelper;
        $this->_drxHelper = $drxHelper;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->_resource = $resource;
    }

    /**
     * Subscription save action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $initialized    = $this->initModels();
        $resultRedirect = $this->resultRedirectFactory->create();

        /**
         * If we were not able to load the model, short-circuit.
         */
        if ($initialized !== true) {
            $resultRedirect->setRefererOrBaseUrl();
            return $resultRedirect;
        }

        /** @var \ParadoxLabs\Subscriptions\Model\Subscription $subscription */
        $subscription = $this->registry->registry('current_subscription');
        /*echo "<pre>";
        print_r($subscription->debug());
        die();*/

        /**
         * Run the billing.
         */
        try {
            $success = $this->subscriptionService->generateOrder([$subscription]);

            if ($success === true) {

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
                $appRefillStatus   = self::APPROVAL_REFILL;
                $payment = $order->getPayment();
                $billAmount = $order->getSubtotal();
                $order->setBaseSubtotal($billAmount);
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



                $this->messageManager->addSuccessMessage(
                    __('Subscription billed successfully.')
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Subscription failed to bill. Please see history for more info.')
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        $resultRedirect->setPath('*/*/view', ['entity_id' => $subscription->getId(), '_current' => true]);

        return $resultRedirect;
    }
}
