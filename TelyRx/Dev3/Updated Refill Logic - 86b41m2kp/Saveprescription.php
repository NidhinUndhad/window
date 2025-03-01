<?php

namespace Telyrx\Prescriber\Controller\Adminhtml\Order;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\Template\TransportBuilderByStore;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\ScopeInterface;
use Telyrx\DrsNoteProduct\Helper\Data;
use Telyrx\DrsNoteProduct\Model\Mail\Template\TransportBuilder as DrNoteTransportBuilder;
use Telyrx\Prescriber\Helper\Group;
use Zend_Pdf;
use Zend_Pdf_Page;
use Zend_Pdf_Style;
use Zend_Pdf_Color_Rgb;
use Zend_Pdf_Font;
use Magento\Framework\Filesystem;
use Magento\Backend\Model\Auth\Session;
use Magento\Directory\Model\RegionFactory;

class Saveprescription extends \Magento\Backend\App\Action
{
    protected $resultPageFactory = false;
    protected $logger;
    protected $_resource;
    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;
    protected $invoiceService;
    /**
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var Group
     */
    protected $groupHelper;

    /**
     * @var Data
     */
    protected $drNoteHelper;

    /**
     * @var DrNoteTransportBuilder
     */
    protected $_transportBuilder;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Session
     */
    protected $authSession;

    protected $regionFactory;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory $orderItems,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Telyrx\Prescriber\Helper\DrxApi $drxHelper,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Psr\Log\LoggerInterface $logger,
        TransportBuilder $transportBuilder,
        TransportBuilderByStore $transportBuilderByStore = null,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\ResourceConnection $resource,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        QuoteFactory $quoteFactory,
        Group $groupHelper,
        Data $drNoteHelper,
        DrNoteTransportBuilder $_transportBuilder,
        Filesystem $filesystem,
        Session $authSession,
        RegionFactory $regionFactory
    ) {
        parent::__construct($context);
        $this->_orderItems = $orderItems;
        $this->request = $request;
        $this->_resultJsonFactory = $resultJsonFactory;
        $this->_orderFactory = $orderFactory;
        $this->_drxHelper = $drxHelper;
        $this->productFactory = $productFactory;
        $this->orderSender = $orderSender;
        $this->logger = $logger;
        $this->transportBuilder = $transportBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->_resource = $resource;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->quoteFactory = $quoteFactory;
        $this->groupHelper = $groupHelper;
        $this->drNoteHelper = $drNoteHelper;
        $this->_transportBuilder = $_transportBuilder;
        $this->filesystem = $filesystem;
        $this->authSession = $authSession;
        $this->regionFactory = $regionFactory;
    }

    public function execute()
    {
        $result = $this->_resultJsonFactory->create();
        $ordereData = $this->request->getPostValue();
        if (!isset($ordereData) || !isset($ordereData['order_id'])) {
            $this->messageManager->addError("Unable to presubscribe this order due to the technical fault. Please contact technical team at hello@telyrx.com with an order information. We'll resolve this ASAP.");
            return $result->setData([
                'success' => false,
            ]);
        }
        $orderMain = $this->_orderFactory->create()->load($ordereData['order_id']);
        $orderNumber = $orderMain['increment_id'];
        $order_Id = $orderMain['increment_id'];
        $connection = $this->_resource->getConnection();
        $sqlSub = "SELECT * FROM `paradoxlabs_subscription` WHERE `keyword_fulltext` LIKE '%$orderNumber%'";
        $resultSubscription = $connection->fetchAll($sqlSub);

        if ($this->drNoteHelper->isDrNoteProductInOrder($orderMain)) {
            if (isset($ordereData['dr_note']) && $ordereData['dr_note'] == 1) {
                if ($this->drNoteHelper->hasMultipleDrNoteProductsInOrder($orderMain)) {
                    if (!empty($orderMain->getDrNoteAcknowledgeName())) {
                        $drNoteAcknowledge = explode(',', $orderMain->getDrNoteAcknowledgeName());
                        if (count($drNoteAcknowledge) > 1) {
                            if (!empty($orderMain->getAcknowledgeName()) || $this->drNoteHelper->isOnlyDrNoteProductInOrder($orderMain)) {
                                return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                            } elseif (empty($orderMain->getAcknowledgeName()) && isset($ordereData['sig_code'])) {
                                return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                            }
                        } else {
                            $drNoteAcknowledge = $orderMain->getDrNoteAcknowledgeName();
                            $drNoteAcknowledge .= ',' . $ordereData['esigncopy'];
                            $orderMain->setDrNoteAcknowledgeName($drNoteAcknowledge);
                            $prescriberId = $this->groupHelper->getUserId();
                            $orderMain->setDoctorsNotePrescriberId($prescriberId);
                            $orderMain->save();
                            if (!empty($orderMain->getAcknowledgeName()) || $this->drNoteHelper->isOnlyDrNoteProductInOrder($orderMain)) {
                                return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                            } elseif (empty($orderMain->getAcknowledgeName()) && isset($ordereData['sig_code'])) {
                                return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                            }
                            $this->messageManager->addSuccessMessage(__('The prescription(s) in this order were successfully submitted for processingg'));

                            return $result->setData(['success' => true]);
                        }
                    } else {
                        if ($this->drNoteHelper->isOnlyDrNoteProductInOrder($orderMain)) {
                            $orderMain->setDrNoteAcknowledgeName($ordereData['esigncopy']);
                            $prescriberId = $this->groupHelper->getUserId();
                            $orderMain->setDoctorsNotePrescriberId($prescriberId);
                            $orderMain->save();
                            $this->messageManager->addSuccessMessage(__('The prescription(s) in this order were successfully submitted for processing'));

                            return $result->setData(['success' => true]);
                        } else {
                            if (!empty($orderMain->getAcknowledgeName()) || $this->drNoteHelper->isOnlyDrNoteProductInOrder($orderMain)) {
                                return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                            } elseif (empty($orderMain->getAcknowledgeName()) && isset($ordereData['sig_code'])) {
                                return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                            }
                            $orderMain->setDrNoteAcknowledgeName($ordereData['esigncopy']);
                            $prescriberId = $this->groupHelper->getUserId();
                            $orderMain->setDoctorsNotePrescriberId($prescriberId);
                            $orderMain->save();
                            $this->messageManager->addSuccessMessage(__('The prescription(s) in this order were successfully submitted for processing'));

                            return $result->setData(['success' => true]);
                        }
                    }
                } else {
                    if (!empty($orderMain->getAcknowledgeName()) || $this->drNoteHelper->isOnlyDrNoteProductInOrder($orderMain)) {
                        return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                    } elseif (empty($orderMain->getAcknowledgeName()) && isset($ordereData['sig_code'])) {
                        return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                    }
                    $orderMain->setDrNoteAcknowledgeName($ordereData['esigncopy']);
                    $prescriberId = $this->groupHelper->getUserId();
                    $orderMain->setDoctorsNotePrescriberId($prescriberId);
                    $orderMain->save();
                    $this->messageManager->addSuccessMessage(__('The prescription(s) in this order were successfully submitted for processing'));

                    return $result->setData(['success' => true]);
                }
            } else {
                if (!empty($orderMain->getDrNoteAcknowledgeName())) {
                    if ($this->drNoteHelper->hasMultipleDrNoteProductsInOrder($orderMain)) {
                        $drNoteAcknowledge = explode(',', $orderMain->getDrNoteAcknowledgeName());
                        if (count($drNoteAcknowledge) > 1) {
                            return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                        } else {
                            if (isset($ordereData['sig_code']) && !empty($ordereData['sig_code'])) {
                                foreach ($ordereData['sig_code'] as $dataKey => $dataValue) {
                                    foreach ($orderMain->getAllItems() as $orderItem) {
                                        if ($dataKey != $orderItem->getId()) {
                                            continue;
                                        }

                                        $newSigCode = '';
                                        $newRefillCount = '';

                                        if (!empty($ordereData['sig_code'][$dataKey])) {
                                            $newSigCode = $ordereData['sig_code'][$dataKey];
                                        }

                                        if (isset($ordereData['refills'][$dataKey])) {
                                            $newRefillCount = (int) $ordereData['refills'][$dataKey];
                                        }
                                        $orderItem->setSigCode($newSigCode);
                                        $orderItem->setNumberOfRefills($newRefillCount);
                                        $orderItem->save();
                                    }
                                }
                                $userId = $this->authSession->getUser()->getUserId();
                                $orderMain->setPrescriberAdminEntityId($userId);
                                $orderMain->setAcknowledgeName($ordereData['esigncopy']);
                                $orderMain->save();
                                $this->messageManager->addSuccessMessage(__('The prescription(s) in this order were successfully submitted for processing'));

                                return $result->setData(['success' => true]);
                            }
                        }
                    } else {
                        return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
                    }
                } else {
                    if (isset($ordereData['sig_code']) && !empty($ordereData['sig_code'])) {
                        foreach ($ordereData['sig_code'] as $dataKey => $dataValue) {
                            foreach ($orderMain->getAllItems() as $orderItem) {
                                if ($dataKey != $orderItem->getId()) {
                                    continue;
                                }

                                $newSigCode = '';
                                $newRefillCount = '';

                                if (!empty($ordereData['sig_code'][$dataKey])) {
                                    $newSigCode = $ordereData['sig_code'][$dataKey];
                                }

                                if (isset($ordereData['refills'][$dataKey])) {
                                    $newRefillCount = (int) $ordereData['refills'][$dataKey];
                                }
                                $orderItem->setSigCode($newSigCode);
                                $orderItem->setNumberOfRefills($newRefillCount);
                                $orderItem->save();
                            }
                        }
                        $userId = $this->authSession->getUser()->getUserId();
                        $orderMain->setPrescriberAdminEntityId($userId);
                        $orderMain->setAcknowledgeName($ordereData['esigncopy']);
                        $orderMain->save();
                        $this->messageManager->addSuccessMessage(__('The prescription(s) in this order were successfully submitted for processing'));

                        return $result->setData(['success' => true]);
                    }
                }
            }
        } else {
            return $this->generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection);
        }
    }
    private function generatePrescription($ordereData, $result, $orderMain, $resultSubscription, $orderNumber, $connection)
    {
        try {
            if ($orderMain->getStatus() == 'prescribed') {
                throw new \Exception("Unable to prescribe because order is already prescribed.");
            }
            if (isset($ordereData['sig_code']) && !empty($ordereData['sig_code'])) {
                $order = $this->_orderFactory->create()->load($ordereData['order_id']);
                $ship1 = $order->getShippingDescription();
                $shippingMethod = preg_replace('/[^a-zA-Z0-9\.]/', '', $ship1);
                $magentoCustomerId = $order->getCustomerId();
                $patiendIdResult = $this->_drxHelper->getPatiendId($magentoCustomerId, $order);

                if (!$patiendIdResult['success']) {
                    throw new \Exception($patiendIdResult['message']);
                }

                $drxPatiendId = $patiendIdResult['patient_id'];
                $flag = false;

                foreach ($ordereData['sig_code'] as $dataKey => $dataValue) {
                    $sigCode = $dataValue;
                    $refillCount = '';
                    $pillCount = '';
                    $daysSupply = 0;
                    $newSigCode = '';
                    $newRefillCount = '';
                    $orderedQty = 1;

                    if (isset($ordereData['refills'][$dataKey])) {
                        $refillCount = $ordereData['refills'][$dataKey];
                        preg_match_all('!\d+!', $refillCount, $matches);
                        if (!empty($matches) && isset($matches[0][0])) {
                            $daysSupply = (int) $matches[0][0];
                        }
                    }
                    if (isset($ordereData['refills'][$dataKey])) {
                        $newRefillCount = (int) $ordereData['refills'][$dataKey];
                    }

                    if (isset($ordereData['sig_code'][$dataKey]) && $ordereData['sig_code'][$dataKey] != '') {
                        $newSigCode = $ordereData['sig_code'][$dataKey];
                    }

                    if (isset($ordereData['ordered_qty'][$dataKey]) && $ordereData['ordered_qty'][$dataKey] != '') {
                        $orderedQty = (int) $ordereData['ordered_qty'][$dataKey];
                    }

                    if (isset($ordereData['pill_count'][$dataKey]) && $ordereData['pill_count'][$dataKey] != '') {
                        $pillCountString = $ordereData['pill_count'][$dataKey];
                        preg_match('/\d+/', $pillCountString, $matches);
                        $pillCount = $orderedQty * intval($matches[0]);
                    }

                    $orderItem = $this->_orderItems->create()->addFieldToFilter('item_id', $dataKey)->getFirstItem();

                    $proId = '';
                    if ($orderItem->getProductType() == "configurable") {
                        $productOptions = $orderItem->getProductOptions();

                        if (isset($productOptions['info_buyRequest']['selected_configurable_option']) && !empty($productOptions['info_buyRequest']['selected_configurable_option'])) {
                            $selectedOption = $productOptions['info_buyRequest']['selected_configurable_option'];
                            $proId = $selectedOption;
                        } else {
                            if (isset($productOptions['simple_sku']) && !empty($productOptions['simple_sku'])) {
                                $simpleSku = $productOptions['simple_sku'] ?? null;
                                if ($simpleSku) {
                                    $proId = $this->productFactory->create()->getIdBySku($simpleSku);
                                }
                            } else {
                                throw new \Magento\Framework\Exception\LocalizedException(
                                    __('Missing SKU for simple product in item options.')
                                );
                            }
                        }
                    } else {
                        $proId = $orderItem->getProductId();
                    }

                    $product = $this->productFactory->create()->load($proId);

                    if ($product->getData('telyrx_product_type') == 1) {
                        continue;
                    }

                    $skuNdc = $this->_drxHelper->skuNdc($product, $orderItem);
                    $drxDoctorId = $this->_drxHelper->drxDoctorId($orderItem, $orderMain);
                    $prescriptionData = $this->_drxHelper->prescription($drxPatiendId, $newSigCode, $pillCount, $drxDoctorId, $skuNdc, $newRefillCount, $shippingMethod, $magentoCustomerId, $orderMain);

                    //$this->logger->debug("Send Prescription: Order: " . $order->getOrderId() . print_r($prescriptionData, true));
                    $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/prescription.log');
                    $logger = new \Zend_Log();
                    $logger->addWriter($writer);
                    $logger->info("Order id: " . $ordereData['order_id']);
                    $logger->info("Order increment id: " . $order->getIncrementId());
                    $preResult = $this->_drxHelper->createPrescription($prescriptionData, $orderMain);

                    if ($preResult['success']) {
                        $flag = true;
                        $orderItem->setSigCode($newSigCode);
                        $orderItem->setNumberOfRefills($newRefillCount);
                        $orderItem->setIsPrescribed(1);
                        $orderItem->setDrxPrescriptionResponse($preResult['data']);
                        $orderItem->save();
                        if (array_key_exists('data', $preResult)) {
                            $dataResult = json_decode($preResult['data'], true);
                            if (array_key_exists('data', $dataResult)) {
                                if (array_key_exists(0, $dataResult['data'])) {
                                    if (array_key_exists('rx_id', $dataResult['data'][0])) {
                                        $refillStatus = $this->_drxHelper->getRefillApiStatus();
                                        if ($refillStatus == 0) {
                                            $rxId = '';
                                        } else {
                                            $rxId = $dataResult['data'][0]['rx_id'];
                                        }
                                        $orderMain->setRxId($rxId);
                                        $orderMain->setPatientId($drxPatiendId);
                                        $orderMain->save();
                                        $dob = $orderMain->getCustomerDob();
                                        $postRefillRequest = '{
                                      "rx_numbers": [
                                        ' . $rxId . '
                                      ],
                                      "date_of_birth": "' . $dob . '",
                                      "delivery_method": "Delivery",
                                      "patient_id": ' . $drxPatiendId . '
                                    }';
                                        //$this->_drxHelper->getRefillRequest($postRefillRequest, $order_Id);
                                        /*$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/refill_request.log');
                                    $logger = new \Zend_Log();
                                    $logger->addWriter($writer);
                                    $logger->info("refills length :".$newRefillCount);*/
                                    }
                                }
                            }
                        }
                    } else {

                        $postObject = new \Magento\Framework\DataObject();
                        $post = [
                            'first_name' => $order->getFirstName(),
                            'last_name' => $order->getLastName(),
                            'patient_id' => $drxPatiendId,
                            'product_info' => $product->getName(),
                            'date_of_refil' => date("Y-m-d h:i:sa"),
                        ];

                        $postObject->setData($post);

                        $templateOptions = array('area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => $order->getStore()->getId());
                        $fromEmail = $this->scopeConfig->getValue("trans_email/ident_support/email", ScopeInterface::SCOPE_STORE);
                        $fromName = $this->scopeConfig->getValue("trans_email/ident_support/name", ScopeInterface::SCOPE_STORE);
                        $from = array('email' => $fromEmail, 'name' => $fromName);
                        $email = $order->getCustomerEmail();
                        /* $templateId  = $this->scopeConfig->getValue("pcustomer/failed_refill/emailtemplate",ScopeInterface::SCOPE_STORE);
                        $transports  = $this->transportBuilder
                        ->setTemplateIdentifier($templateId)
                        ->setTemplateOptions($templateOptions)
                        ->setTemplateVars(['data' => $postObject])
                        ->setFrom($from)
                        ->addTo($email)
                        ->getTransport();

                        $transports->sendMessage();
                         */
                        throw new \Exception("Unable to prescribe because {$preResult['message']}");
                    }
                    /* CREATE Prescription IN DRX */
                }
                if ($flag) {
                    if (isset($ordereData['simple_productid']) && $ordereData['simple_productid'] != '') {
                        foreach ($ordereData['simple_productid'] as $simpleId) {
                            foreach ($order->getAllVisibleItems() as $item) {
                                $itemIds = $item->getId();
                                if ($item->getProductType() == "configurable") {
                                    if (count($resultSubscription) > 0) {
                                        $productNmme = $item->getName();
                                        $simpleProductId = "";
                                        if (isset($item->getProductOptions()['info_buyRequest']['selected_configurable_option']) && !empty($item->getProductOptions()['info_buyRequest']['selected_configurable_option'])) {
                                            $selectedOption = $item->getProductOptions()['info_buyRequest']['selected_configurable_option'];
                                            $simpleProductId = $selectedOption;
                                        } else {
                                            if (isset($item->getProductOptions()['simple_sku']) && !empty($item->getProductOptions()['simple_sku'])) {
                                                $simpleSku = $item->getProductOptions()['simple_sku'] ?? null;
                                                if ($simpleSku) {
                                                    $simpleProductId = $this->productFactory->create()->getIdBySku($simpleSku);
                                                }
                                            } else {
                                                throw new \Magento\Framework\Exception\LocalizedException(
                                                    __('Missing SKU for simple product in item options.')
                                                );
                                            }
                                        }
                                        if ($simpleId == $simpleProductId) {
                                            $simpleProduct = $this->productFactory->create()->load($simpleProductId);
                                            if (isset($ordereData['refills'][$itemIds]) && $ordereData['refills'][$itemIds] != '') {
                                                $refillCount = $ordereData['refills'][$itemIds];
                                                if ($refillCount > 0) {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = $refillCount WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                } else {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = 0, `status` = 'canceled' WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                }
                                            }
                                        }
                                    }
                                }
                                if ($item->getProductType() == "simple") {
                                    if (count($resultSubscription) > 0) {
                                        $productNmme = $item->getName();
                                        if ($simpleId == $item->getProductId()) {
                                            if (isset($ordereData['refills'][$itemIds]) && !empty($ordereData['refills'][$itemIds])) {
                                                $refillCount = $ordereData['refills'][$itemIds];
                                                if ($refillCount > 0) {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = $refillCount WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                } else {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = 0, `status` = 'canceled' WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $order->setState('new')->setStatus('prescribed');

                if (isset($ordereData['assign_prescriber'])) {
                    $order->setPrescriberId($ordereData['assign_prescriber']);
                }

                $order->save();
            } else {
                $order = $this->_orderFactory->create()->load($ordereData['order_id']);
                $ship1 = $order->getShippingDescription();
                $shippingMethod = preg_replace('/[^a-zA-Z0-9\.]/', '', $ship1);
                $magentoCustomerId = $order->getCustomerId();
                $patiendIdResult = $this->_drxHelper->getPatiendId($magentoCustomerId, $order);

                if (!$patiendIdResult['success']) {
                    throw new \Exception($patiendIdResult['message']);
                }

                $drxPatiendId = $patiendIdResult['patient_id'];
                $flag = false;
                $orderItems = $orderMain->getAllItems();

                foreach ($orderItems as $item) {
                    if ($item->getParentItem()) {
                        continue;
                    }
                    $newRefillCount = (int) $item->getNumberOfRefills();
                    $newSigCode = $item->getSigCode();
                    $orderedQty = (int) $item->getQtyOrdered();
                    $simpleProductId = '';
                    $simplePillCount = 1;

                    if ($item->getProductType() == "configurable") {
                        $productOptions = $item->getProductOptions();

                        if (isset($productOptions['info_buyRequest']['selected_configurable_option']) && !empty($productOptions['info_buyRequest']['selected_configurable_option'])) {
                            $selectedOption = $productOptions['info_buyRequest']['selected_configurable_option'];
                            $simpleProductId = $selectedOption;
                        } else {
                            if (isset($productOptions['simple_sku']) && !empty($productOptions['simple_sku'])) {
                                $simpleSku = $productOptions['simple_sku'] ?? null;
                                if ($simpleSku) {
                                    $simpleProductId = $this->productFactory->create()->getIdBySku($simpleSku);
                                }
                            } else {
                                throw new \Magento\Framework\Exception\LocalizedException(
                                    __('Missing SKU for simple product in item options.')
                                );
                            }
                        }
                        $simpleProduct = $this->groupHelper->loadProduct($simpleProductId);
                        $simplePillCount = $simpleProduct->getResource()->getAttribute('med_qty')->getFrontend()->getValue($simpleProduct);
                    } else {
                        $simpleProductId = $item->getProductId();
                    }

                    $pillCount = $simplePillCount;

                    if ($item->getProductOptions()) {
                        $proOption = $item->getProductOptions();
                        if (isset($proOption['attributes_info'])) {
                            $attrOpt = $proOption['attributes_info'];
                            foreach ($attrOpt as $attrOptKey => $attrOptValue) {
                                if ($attrOptValue['option_id'] == 237) {
                                    $pillCount = $attrOptValue['value'];
                                }
                            }
                        }
                    }

                    $pillCountString = $pillCount;
                    preg_match('/\d+/', $pillCountString, $matches);
                    $pillCount = $orderedQty * intval($matches[0]);

                    $product = $this->productFactory->create()->load($simpleProductId);

                    if ($product->getData('telyrx_product_type') == 1) {
                        continue;
                    }

                    $skuNdc = $this->_drxHelper->skuNdc($product, $item);
                    $drxDoctorId = $this->_drxHelper->drxDoctorId($item, $orderMain);

                    $prescriptionData = $this->_drxHelper->prescription($drxPatiendId, $newSigCode, $pillCount, $drxDoctorId, $skuNdc, $newRefillCount, $shippingMethod, $magentoCustomerId, $orderMain);

                    //$this->logger->debug("Send Prescription: Order: " . $order->getOrderId() . print_r($prescriptionData, true));
                    $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/prescription.log');
                    $logger = new \Zend_Log();
                    $logger->addWriter($writer);
                    $logger->info("Order id: " . $ordereData['order_id']);
                    $logger->info("Order increment id: " . $order->getIncrementId());
                    $preResult = $this->_drxHelper->createPrescription($prescriptionData, $orderMain);

                    if ($preResult['success']) {
                        $flag = true;
                        $item->setIsPrescribed(1);
                        $item->setDrxPrescriptionResponse($preResult['data']);
                        $item->save();
                        if (array_key_exists('data', $preResult)) {
                            $dataResult = json_decode($preResult['data'], true);
                            if (array_key_exists('data', $dataResult)) {
                                if (array_key_exists(0, $dataResult['data'])) {
                                    if (array_key_exists('rx_id', $dataResult['data'][0])) {
                                        $refillStatus = $this->_drxHelper->getRefillApiStatus();
                                        if ($refillStatus == 0) {
                                            $rxId = '';
                                        } else {
                                            $rxId = $dataResult['data'][0]['rx_id'];
                                        }
                                        $orderMain->setRxId($rxId);
                                        $orderMain->setPatientId($drxPatiendId);
                                        $orderMain->save();
                                        $dob = $orderMain->getCustomerDob();
                                        $postRefillRequest = '{
                                        "rx_numbers": [
                                            ' . $rxId . '
                                        ],
                                        "date_of_birth": "' . $dob . '",
                                        "delivery_method": "Delivery",
                                        "patient_id": ' . $drxPatiendId . '
                                        }';
                                        //$this->_drxHelper->getRefillRequest($postRefillRequest, $order_Id);
                                        /*$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/refill_request.log');
                                    $logger = new \Zend_Log();
                                    $logger->addWriter($writer);
                                    $logger->info("refills length :".$newRefillCount);*/
                                    }
                                }
                            }
                        }
                    } else {

                        $postObject = new \Magento\Framework\DataObject();
                        $post = [
                            'first_name' => $order->getFirstName(),
                            'last_name' => $order->getLastName(),
                            'patient_id' => $drxPatiendId,
                            'product_info' => $product->getName(),
                            'date_of_refil' => date("Y-m-d h:i:sa"),
                        ];

                        $postObject->setData($post);

                        $templateOptions = array('area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => $order->getStore()->getId());
                        $fromEmail = $this->scopeConfig->getValue("trans_email/ident_support/email", ScopeInterface::SCOPE_STORE);
                        $fromName = $this->scopeConfig->getValue("trans_email/ident_support/name", ScopeInterface::SCOPE_STORE);
                        $from = array('email' => $fromEmail, 'name' => $fromName);
                        $email = $order->getCustomerEmail();
                        /* $templateId  = $this->scopeConfig->getValue("pcustomer/failed_refill/emailtemplate",ScopeInterface::SCOPE_STORE);
                        $transports  = $this->transportBuilder
                        ->setTemplateIdentifier($templateId)
                        ->setTemplateOptions($templateOptions)
                        ->setTemplateVars(['data' => $postObject])
                        ->setFrom($from)
                        ->addTo($email)
                        ->getTransport();

                        $transports->sendMessage();
                         */
                        throw new \Exception("Unable to prescribe because {$preResult['message']}");
                    }
                    /* CREATE Prescription IN DRX */
                }
                if ($flag) {
                    if (isset($ordereData['simple_productid']) && $ordereData['simple_productid'] != '') {
                        foreach ($ordereData['simple_productid'] as $simpleId) {
                            foreach ($order->getAllVisibleItems() as $item) {
                                $itemIds = $item->getId();
                                if ($item->getProductType() == "configurable") {
                                    if (count($resultSubscription) > 0) {
                                        $productNmme = $item->getName();
                                        $simpleProductId = "";
                                        if (isset($item->getProductOptions()['info_buyRequest']['selected_configurable_option']) && !empty($item->getProductOptions()['info_buyRequest']['selected_configurable_option'])) {
                                            $selectedOption = $item->getProductOptions()['info_buyRequest']['selected_configurable_option'];
                                            $simpleProductId = $selectedOption;
                                        } else {
                                            if (isset($item->getProductOptions()['simple_sku']) && !empty($item->getProductOptions()['simple_sku'])) {
                                                $simpleSku = $item->getProductOptions()['simple_sku'] ?? null;
                                                if ($simpleSku) {
                                                    $simpleProductId = $this->productFactory->create()->getIdBySku($simpleSku);
                                                }
                                            } else {
                                                throw new \Magento\Framework\Exception\LocalizedException(
                                                    __('Missing SKU for simple product in item options.')
                                                );
                                            }
                                        }
                                        if ($simpleId == $simpleProductId) {
                                            $simpleProduct = $this->productFactory->create()->load($simpleProductId);
                                            if (isset($ordereData['refills'][$itemIds]) && $ordereData['refills'][$itemIds] != '') {
                                                $refillCount = $ordereData['refills'][$itemIds];
                                                if ($refillCount > 0) {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = $refillCount WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                } else {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = 0, `status` = 'canceled' WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                }
                                            }
                                        }
                                    }
                                }
                                if ($item->getProductType() == "simple") {
                                    if (count($resultSubscription) > 0) {
                                        $productNmme = $item->getName();
                                        if ($simpleId == $item->getProductId()) {
                                            if (isset($ordereData['refills'][$itemIds]) && !empty($ordereData['refills'][$itemIds])) {
                                                $refillCount = $ordereData['refills'][$itemIds];
                                                if ($refillCount > 0) {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = $refillCount WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                } else {
                                                    $updateSubscription = "UPDATE `paradoxlabs_subscription` SET `length` = 0, `status` = 'canceled' WHERE `keyword_fulltext` LIKE '%$orderNumber%' AND `description` LIKE '%$productNmme%'";
                                                    $connection->query($updateSubscription);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $order->setState('new')->setStatus('prescribed');

                if (isset($ordereData['assign_prescriber'])) {
                    $order->setPrescriberId($ordereData['assign_prescriber']);
                }

                $order->save();
            }
            $orderMain->setAcknowledgeName($ordereData['esigncopy'])
                ->setAcknowledgeDate($ordereData['esigndate'])
                ->setAcknowledgeIp($ordereData['esignip']);

            $payment = $orderMain->getPayment();
            $method = $payment->getMethodInstance();
            $methodCode = $method->getCode();
            if ($methodCode == "authnetcim") {
                if ($orderMain->canInvoice()) {
                    $invoice = $this->invoiceService->prepareInvoice($orderMain);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    $invoice->getOrder()->setCustomerNoteNotify(false);
                    //$invoice->getOrder()->setIsInProcess(true);
                    $transactionSave = $this->transactionFactory->create();
                    $transactionSave->addObject($invoice)->addObject($invoice->getOrder());
                    $transactionSave->save();
                    $orderMain->setState('new')->setStatus('prescribed');
                }
            }
            $orderMain->setState('new')->setStatus('prescribed');
            $current_time = date("Y-m-d H:i:s");
            $orderMain->setOrderPrescribedAt($current_time);
            $prescriberId = $this->groupHelper->getUserId();
            $prescriberData = $this->groupHelper->getPrescriberData($prescriberId);
            $prescriberFullName = $prescriberData->getFirstname() . ', ' . $prescriberData->getLastname();
            $orderMain->setPrescriberId($prescriberId);
            $orderMain->setPrescriberName($prescriberFullName);
            $orderMain->save();
            $this->sendDeclineMedicationsNotification($orderMain);
            $this->orderSender->send($orderMain);
            $this->processGeneratePdf($orderMain, $ordereData);

            $this->messageManager->addSuccess(__('The prescription(s) in this order were successfully submitted for processing'));

            return $result->setData([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            $this->messageManager->addError($e->getMessage());
            return $result->setData([
                'success' => false,
            ]);
        }
    }

    /**
     * Generates a PDF for the doctor's note based on the order details.
     *
     * @param \Magento\Sales\Model\Order $orderMain
     * @param $ordereData
     * @return void
     */
    private function processGeneratePdf($orderMain, $ordereData)
    {
        $orderItems          = $orderMain->getAllItems();
        $shippingAddress     = $orderMain->getShippingAddress();

        foreach ($orderItems as $item) {
            $productId          = $item->getProductId();
            $product            = $this->productFactory->create()->load($productId);
            $productType        = $product->getData('telyrx_product_type');
            $productOptions     = $item->getProductOptions();
            $drNoteSelectedType = '';

            // Retrieve the selected doctor's note type from product options
            switch ($item->getSku()) {
                case 'doctors_note-illness':
                    $drNoteSelectedType = 'Illness';
                    break;
                case 'doctors_note-injury':
                    $drNoteSelectedType = 'Injury';
                    break;
                case 'emotional_support_animal_letter':
                    $drNoteSelectedType = 'Emotional Support Animal Letter';
                    break;
                default:
                    break;
            }

            if ($productType == 1) {
                $doctorsNoteProduct = $item->getDoctorsNoteProduct();
                if ($doctorsNoteProduct) {
                    $drNoteOptions   = json_decode($doctorsNoteProduct, true);
                    $drNoteType      = strtolower($drNoteSelectedType);
                    $patientName     = isset($drNoteOptions['Patient_Name']) ? ucwords(strtolower(trim($drNoteOptions['Patient_Name']))) : "Unknown";
                    $patientAddress  = $drNoteOptions['Patient_Address'] ?? "Unknown";
                    $startDate       = isset($drNoteOptions['Start_Date']) ? $this->formatDate($drNoteOptions['Start_Date']) : "Unknown";
                    $endDate         = isset($drNoteOptions['End_Date']) ? $this->formatDate($drNoteOptions['End_Date']) : "Unknown";
                    $workOrSchool    = isset($drNoteOptions['Used_For']) ? strtolower($drNoteOptions['Used_For']) : "Unknown";
                    $startOfCareDate = isset($drNoteOptions['Start_Of_Care_Date']) ? $this->formatDate($drNoteOptions['Start_Of_Care_Date']) : "Unknown";
                    $animalType      = strtolower($drNoteOptions['Animal_Type'] ?? "Unknown");
                    $animalName      = ucwords(strtolower($drNoteOptions['Animal_Name'] ?? "Unknown"));
                    $disability      = $drNoteOptions['Disability'] ?? "Unknown";
                    $currentDate     = $this->formatDate(date('Y-m-d'));
                    $addressParts    = explode(',', $patientAddress);
                    $eSignature      = $orderMain->getDrNoteAcknowledgeName();

                    if (empty($eSignature)) {
                        $eSignature = isset($ordereData['esigncopy']) ? $ordereData['esigncopy'] : "Default Signature";
                    }

                    $streetAddressLine1 = "";
                    $streetAddressLine2 = "";
                    $streetAddress      = "";
                    $cityStateZip       = "";

                    if (count($addressParts) == 4) {
                        $streetAddress = isset($addressParts[0]) ? trim($addressParts[0]) : '';
                        $city          = isset($addressParts[1]) ? ucwords(strtolower(trim($addressParts[1]))) : '';
                        $state         = isset($addressParts[2]) ? trim($addressParts[2]) : '';
                        $zipCode       = isset($addressParts[3]) ? trim($addressParts[3]) : '';
                        $cityStateZip  = $city . ', ' . $state . ' ' . $zipCode;
                    } elseif (count($addressParts) == 5) {
                        $streetAddressLine1 = isset($addressParts[0]) ? trim($addressParts[0]) : '';
                        $streetAddressLine2 = isset($addressParts[1]) ? trim($addressParts[1]) : '';
                        $city               = isset($addressParts[2]) ? ucwords(strtolower(trim($addressParts[2]))) : '';
                        $state              = isset($addressParts[3]) ? trim($addressParts[3]) : '';
                        $zipCode            = isset($addressParts[4]) ? trim($addressParts[4]) : '';
                        $cityStateZip       = $city . ', ' . $state . ' ' . $zipCode;
                    }

                    // Create new PDF document
                    if ($item->getSku() == 'doctors_note-illness' || $item->getSku() == 'doctors_note-injury') {
                        $this->generateDoctorsNotePdf($currentDate, $patientName, $addressParts, $streetAddress, $cityStateZip, $streetAddressLine1, $streetAddressLine2, $drNoteType, $workOrSchool, $startDate, $endDate, $orderMain);
                    } elseif ($item->getSku() == 'emotional_support_animal_letter') {
                        $this->generateESALetterPdf($currentDate, $patientName, $addressParts, $streetAddress, $cityStateZip, $streetAddressLine1, $streetAddressLine2, $drNoteType, $disability, $animalType, $animalName, $orderMain);
                    }
                }
            }
        }
    }

    /**
     * Generate the Excuse's Note PDF
     * 
     * @param string $currentDate
     * @param string $patientName
     * @param array  $addressParts
     * @param string $streetAddress
     * @param string $cityStateZip
     * @param string $streetAddressLine1
     * @param string $streetAddressLine2
     * @param string $drNoteType
     * @param string $workOrSchool
     * @param string $startDate
     * @param string $endDate
     * @param \Magento\Sales\Model\Order $orderMain
     */
    private function generateDoctorsNotePdf($currentDate, $patientName, $addressParts, $streetAddress, $cityStateZip, $streetAddressLine1, $streetAddressLine2, $drNoteType, $workOrSchool, $startDate, $endDate, $orderMain)
    {
        $pdf  = new Zend_Pdf();
        $page = new Zend_Pdf_Page(Zend_Pdf_Page::SIZE_A4);

        // Set font style
        $style = new Zend_Pdf_Style();
        $font  = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_TIMES);
        $fontSize = 12;
        $style->setFont($font, $fontSize);
        $page->setStyle($style);

        $doctorSettings = $this->getDoctorSettings($orderMain);

        // Add logo to PDF
        $imagePath   = $this->getImagePath($doctorSettings['doctors_note_logo']);
        $image       = \Zend_Pdf_Image::imageWithPath($imagePath);
        $imageWidth  = 175;
        $imageHeight = 90;
        $pageWidth   = $page->getWidth();
        $xPosition   = ($pageWidth - $imageWidth) / 2;
        $yPosition   = $page->getHeight() - $imageHeight - 10; // Close to the top of the page
        $page->drawImage($image, $xPosition, $yPosition, $xPosition + $imageWidth, $yPosition + $imageHeight);

        // Draw bold line below the logo
        $lineY = $yPosition - 20; // Position of the line below the logo
        $page->setLineColor(new Zend_Pdf_Color_Rgb(0, 0, 0)); // Black color
        $page->setLineWidth(2); // Bold line
        $page->drawLine(40, $lineY, $page->getWidth() - 40, $lineY); // Draw line from left to right

        // Measure currentDate width for centering
        $textWidth = $this->getTextWidth($currentDate, $font, $fontSize);
        $xPosition = ($pageWidth - $textWidth) / 2;

        // Draw the date at the top of the page, below the line
        $page->drawText($currentDate, $xPosition, $lineY - 20, 'UTF-8');

        // Add content to the PDF
        $page->drawText($patientName, 40, $lineY - 60);

        if (count($addressParts) == 4) {
            $page->drawText($streetAddress, 40, $lineY - 80);
            $page->drawText($cityStateZip, 40, $lineY - 100);
        } elseif (count($addressParts) == 5) {
            $page->drawText($streetAddressLine1, 40, $lineY - 80);
            $page->drawText($streetAddressLine2, 40, $lineY - 100);
            $page->drawText($cityStateZip, 40, $lineY - 120);
        }
        // Add space before "To Whom It May Concern:"
        $yPosition = $lineY - 140;
        $page->drawText(" ", 40, $yPosition);
        $yPosition -= 20;
        $page->drawText("To Whom It May Concern:", 40, $yPosition);

        // Add space after "To Whom It May Concern:"
        $yPosition -= 30;

        // Add paragraphs to the PDF
        $paragraphs = [
            "Please allow this correspondence to serve as notice that, based on the information provided to me, $patientName has an $drNoteType. Due to their current $drNoteType, it is recommended that $patientName be granted an absence from $workOrSchool for a period beginning on $startDate and ending on $endDate.",
            "As is consistent with medical privacy laws, specific details of the diagnosis and treatment are confidential.",
            "Thank you for your understanding and cooperation."
        ];

        $maxWidth   = 520;
        $lineHeight = 20;

        foreach ($paragraphs as $paragraph) {
            $words = explode(' ', $paragraph);
            $line  = '';
            foreach ($words as $word) {
                $testLine      = $line . ' ' . $word;
                $testLineWidth = $this->getTextWidth(trim($testLine), $font, $fontSize);
                if ($testLineWidth <= $maxWidth) {
                    $line = $testLine;
                } else {
                    $page->drawText(trim($line), 40, $yPosition);
                    $line       = $word;
                    $yPosition -= $lineHeight;
                }
            }
            $page->drawText(trim($line), 40, $yPosition);
            $yPosition -= $lineHeight + 10; // Add space between paragraphs
        }

        // Add signature and footer
        $yPosition -= 20; // Adjusted space
        $page->drawText("Sincerely,", 40, $yPosition);
        $yPosition -= 70; // Adjusted space for eSignature

        $signatureImagePath = $this->getImagePath($doctorSettings['doctors_note_signature']); // Function to get the path of the signature image
        $signatureImage     = \Zend_Pdf_Image::imageWithPath($signatureImagePath);

        // Define the desired dimensions for the signature
        $signatureImageWidth  = 200;  // Adjust width as needed
        $signatureImageHeight = 50;   // Adjust height as needed
        $signatureXPosition   = 40;   // X position for the signature image
        $signatureYPosition   = $yPosition;  // Y position for the signature image

        // Draw the image
        $page->drawImage(
            $signatureImage,
            $signatureXPosition,
            $signatureYPosition,
            $signatureXPosition + $signatureImageWidth,
            $signatureYPosition + $signatureImageHeight
        );
        $yPosition -= 30; // Adjusted space
        $page->drawText($doctorSettings['doctors_signature_name'], 40, $yPosition);

        $yPosition -= 20; // Adjusted space

        $page->drawText("NPI: #" . $doctorSettings['doctors_npi_number'], 40, $yPosition);

        $yPosition -= 60; // Adjusted space for Dr Address

        $page->drawText($doctorSettings['prescriber_group_name'], 40, $yPosition);

        $yPosition -= 20; // Adjusted space

        $page->drawText($doctorSettings['prescriber_group_street'], 40, $yPosition);

        $yPosition -= 20; // Adjusted space

        $page->drawText($doctorSettings['prescriber_group_full_address'], 40, $yPosition);

        $yPosition -= 60;

        $page->drawText("Verification ID: " . $orderMain->getIncrementID(), 40, $yPosition);

        $yPosition -= 20;

        // Draw the description text in black
        $descriptionText = "Verify the authenticity of this Doctor’s Note at: ";
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0)); // Reset to black for the description
        $page->drawText($descriptionText, 40, $yPosition, 'UTF-8');

        // Calculate the X position for the URL (immediately after the description text)
        $urlStartX = 40 + $this->getTextWidth($descriptionText, $font, $fontSize);

        // Draw the URL in blue
        $baseUrl = $doctorSettings['doctors_note_verification_url'];
        $linkFont = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA);
        $linkFontSize = 10;
        $page->setFont($linkFont, $linkFontSize);
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 1)); // Blue color for the URL
        $page->drawText($baseUrl, $urlStartX, $yPosition, 'UTF-8');

        // Create the clickable hyperlink for the URL
        $urlEndX = $urlStartX + $this->getTextWidth($baseUrl, $linkFont, $linkFontSize);
        $target = \Zend_Pdf_Action_URI::create("https://" . $baseUrl);
        $annotation = \Zend_Pdf_Annotation_Link::create(
            $urlStartX,                     // x1 (start of the URL text)
            $yPosition - 2,                 // y1 (bottom of the URL text)
            $urlEndX,                       // x2 (end of the URL text)
            $yPosition + $linkFontSize,     // y2 (top of the URL text)
            $target                         // Target URL
        );

        // Attach the annotation to the page
        $page->attachAnnotation($annotation);

        // Reset the text color to black for the footer
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0)); // Reset to black

        // Draw Footer Line
        $footerLineY = 40; // Position of the footer line
        $page->setLineWidth(2); // Bold line
        $page->drawLine(40, $footerLineY, $page->getWidth() - 40, $footerLineY); // Draw line from left to right

        // Draw Footer text
        $footerFont = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD);
        $footerFontSize = 12;

        $footerStyle = new Zend_Pdf_Style();
        $footerStyle->setFont($footerFont, $footerFontSize);

        $page->setStyle($footerStyle);

        $footerText      = $doctorSettings['doctors_note_pdf_footer_text'];
        $footerWidth     = $this->getTextWidth($footerText, $footerFont, $footerFontSize);
        $footerXPosition = ($pageWidth - $footerWidth) / 2;

        $footerTextYPosition = $footerLineY - 30;
        $page->drawText($footerText, $footerXPosition, $footerTextYPosition, 'UTF-8');

        $fileName = sprintf('Excuse Note.pdf');

        $regardsName = $doctorSettings['regards_name'];

        $this->finalizePdf($pdf, $page, $orderMain, $drNoteType, $regardsName, 'Your Excuse Note is Attached', 'Dr Note', $fileName);
    }

    /**
     * Generate the ESA Letter PDF
     * 
     * @param string $currentDate
     * @param string $patientName
     * @param array  $addressParts
     * @param string $streetAddress
     * @param string $cityStateZip
     * @param string $streetAddressLine1
     * @param string $streetAddressLine2
     * @param string $drNoteType
     * @param string $disability
     * @param string $animalType
     * @param string $animalName
     * @param \Magento\Sales\Model\Order $orderMain
     */
    private function generateESALetterPdf($currentDate, $patientName, $addressParts, $streetAddress, $cityStateZip, $streetAddressLine1, $streetAddressLine2, $drNoteType, $disability, $animalType, $animalName, $orderMain)
    {
        $pdf  = new Zend_Pdf();
        $page = new Zend_Pdf_Page(Zend_Pdf_Page::SIZE_A4);

        // Set font style
        $style = new Zend_Pdf_Style();
        $font  = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_TIMES);
        $fontSize = 12;
        $style->setFont($font, $fontSize);
        $page->setStyle($style);

        $doctorSettings = $this->getDoctorSettings($orderMain);

        // Add logo to PDF
        $imagePath   = $this->getImagePath($doctorSettings['doctors_note_logo']);
        $image       = \Zend_Pdf_Image::imageWithPath($imagePath);
        $imageWidth  = 200;
        $imageHeight = 100;
        $pageWidth   = $page->getWidth();
        $xPosition   = ($pageWidth - $imageWidth) / 2;
        $yPosition   = $page->getHeight() - $imageHeight - 1; // Close to the top of the page
        $page->drawImage($image, $xPosition, $yPosition, $xPosition + $imageWidth, $yPosition + $imageHeight);

        // Draw bold line below the logo
        $lineY = $page->getHeight() - $imageHeight; // Position of the line below the logo
        $page->setLineColor(new Zend_Pdf_Color_Rgb(0, 0, 0)); // Black color
        $page->setLineWidth(2); // Bold line
        $page->drawLine(40, $lineY, $page->getWidth() - 40, $lineY); // Draw line from left to right

        // Measure currentDate width for centering
        $textWidth = $this->getTextWidth($currentDate, $font, $fontSize);
        $xPosition = ($pageWidth - $textWidth) / 2;

        // Draw the date at the top of the page, below the line
        $page->drawText($currentDate, $xPosition, $lineY - 20, 'UTF-8');

        // Add content to the PDF
        $page->drawText($patientName, 40, $lineY - 60);

        if (count($addressParts) == 4) {
            $page->drawText($streetAddress, 40, $lineY - 80);
            $page->drawText($cityStateZip, 40, $lineY - 100);
        } elseif (count($addressParts) == 5) {
            $page->drawText($streetAddressLine1, 40, $lineY - 80);
            $page->drawText($streetAddressLine2, 40, $lineY - 100);
            $page->drawText($cityStateZip, 40, $lineY - 120);
        }
        // Add space before "To Whom It May Concern:"
        $yPosition = $lineY - 140;
        $page->drawText(" ", 40, $yPosition);

        $yPosition -= 15;

        $page->drawText("To Whom It May Concern:", 40, $yPosition);

        // Add space after "To Whom It May Concern:"
        $yPosition -= 30;

        // Add paragraphs to the PDF

        $paragraphs = [
            "Based on the information provided to me by $patientName, obtained under oath in the form of a certification, $patientName has indicated that they have a condition that meets the definition of disability under the Americans with Disabilities Act, the Fair Housing Act, or under the Rehabilitation Act of 1973. Due to their health condition, specifically $disability, which substantially limits one or more of their major life activities, an emotional support animal is an integral part of their ongoing care.",
            "Due to their current condition or disability, it is recommended that $patientName be granted the presence of an emotional support animal.",
            "The emotional support animal that $patientName has chosen is a $animalType named $animalName. It will provide necessary support to $patientName, thus facilitating participation in daily activities.",
            "This letter serves as an official medical justification for the presence of $patientName's emotional support animal and is provided at the request of $patientName. They have my recommendation and support in this matter.",
            "Thank you for your understanding and cooperation."
        ];

        $maxWidth   = 510;
        $lineHeight = 20;

        foreach ($paragraphs as $paragraph) {
            $words = explode(' ', $paragraph);
            $line  = '';
            foreach ($words as $word) {
                $testLine      = $line . ' ' . $word;
                $testLineWidth = $this->getTextWidth(trim($testLine), $font, $fontSize);
                if ($testLineWidth <= $maxWidth) {
                    $line = $testLine;
                } else {
                    $page->drawText(trim($line), 40, $yPosition);
                    $line       = $word;
                    $yPosition -= $lineHeight;
                }
            }
            $page->drawText(trim($line), 40, $yPosition);
            $yPosition -= $lineHeight + 10; // Add space between paragraphs
        }

        // Add signature and footer
        $yPosition -= 0; // Adjusted space
        $page->drawText("Sincerely,", 40, $yPosition);
        $yPosition -= 60; // Adjusted space for eSignature   

        $signatureImagePath = $this->getImagePath($doctorSettings['doctors_note_signature']); // Function to get the path of the signature image
        $signatureImage     = \Zend_Pdf_Image::imageWithPath($signatureImagePath);

        // Define the desired dimensions for the signature
        $signatureImageWidth  = 150;  // Adjust width as needed
        $signatureImageHeight = 50;   // Adjust height as needed
        $signatureXPosition   = 40;   // X position for the signature image
        $signatureYPosition   = $yPosition;  // Y position for the signature image

        // Draw the image
        $page->drawImage(
            $signatureImage,
            $signatureXPosition,
            $signatureYPosition,
            $signatureXPosition + $signatureImageWidth,
            $signatureYPosition + $signatureImageHeight
        );

        $yPosition -= 20; // Adjusted space
        $page->drawText($doctorSettings['doctors_signature_name'], 40, $yPosition);

        $yPosition -= 20; // Adjusted space

        $page->drawText("License Number: " . $doctorSettings['doctors_npi_number'], 40, $yPosition);

        $yPosition -= 40;

        $page->drawText("Verification ID: " . $orderMain->getIncrementID(), 40, $yPosition);

        $yPosition -= 20;

        // Draw the description text in black
        $descriptionText = "Verify the authenticity of this Doctor’s Note at: ";
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0)); // Reset to black for the description
        $page->drawText($descriptionText, 40, $yPosition, 'UTF-8');

        // Calculate the X position for the URL (immediately after the description text)
        $urlStartX = 40 + $this->getTextWidth($descriptionText, $font, $fontSize);

        // Draw the URL in blue
        $baseUrl = $doctorSettings['doctors_note_verification_url'];
        $linkFont = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA);
        $linkFontSize = 10;
        $page->setFont($linkFont, $linkFontSize);
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 1)); // Blue color for the URL
        $page->drawText($baseUrl, $urlStartX, $yPosition, 'UTF-8');

        // Create the clickable hyperlink for the URL
        $urlEndX = $urlStartX + $this->getTextWidth($baseUrl, $linkFont, $linkFontSize);
        $target = \Zend_Pdf_Action_URI::create("https://" . $baseUrl);
        $annotation = \Zend_Pdf_Annotation_Link::create(
            $urlStartX,                     // x1 (start of the URL text)
            $yPosition - 2,                 // y1 (bottom of the URL text)
            $urlEndX,                       // x2 (end of the URL text)
            $yPosition + $linkFontSize,     // y2 (top of the URL text)
            $target                         // Target URL
        );

        // Attach the annotation to the page
        $page->attachAnnotation($annotation);

        // Reset the text color to black for the footer
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0)); // Reset to black

        // Draw Footer Line
        $footerLineY = 60; // Position of the footer line
        $page->setLineWidth(2); // Bold line
        $page->drawLine(40, $footerLineY, $page->getWidth() - 40, $footerLineY); // Draw line from left to right

        // Draw Footer text
        $footerFont = Zend_Pdf_Font::fontWithName(Zend_Pdf_Font::FONT_HELVETICA_BOLD);
        $footerFontSize = 12;

        $footerStyle = new Zend_Pdf_Style();
        $footerStyle->setFont($footerFont, $footerFontSize);

        $page->setStyle($footerStyle);

        $footerText      = $doctorSettings['doctors_note_pdf_footer_text'];
        $footerWidth     = $this->getTextWidth($footerText, $footerFont, $footerFontSize);
        $footerXPosition = ($pageWidth - $footerWidth) / 2;

        $footerTextYPosition = $footerLineY - 30;
        $page->drawText($footerText, $footerXPosition, $footerTextYPosition, 'UTF-8');

        $fileName = 'Emotional Support Animal Letter.pdf';

        $regardsName = $doctorSettings['regards_name'];

        $this->finalizePdf($pdf, $page, $orderMain, $drNoteType, $regardsName, 'Your Emotional Support Animal Letter is Attached', $drNoteType, $fileName);
    }

    private function getDoctorSettings($orderMain)
    {
        $settings     = [];
        $prescriberId = $this->groupHelper->getUserId();

        if (!$prescriberId) {
            throw new \Exception('Prescriber ID is missing.');
        }

        $prescriberData = $this->groupHelper->getPrescriberData($prescriberId);

        if ($prescriberData->getData('prescribe_doctors_note') == 0 || $prescriberData->getData('prescribe_doctors_note') == null) {
            $prescriberId   = $orderMain->getDoctorsNotePrescriberId();
            $prescriberData = $this->groupHelper->getPrescriberData($prescriberId);
        }

        if (!$prescriberData || !$prescriberData->getId()) {
            throw new \Exception('Prescriber data is invalid or missing.');
        }

        $prescriberGroupId = $prescriberData->getData('prescriber_group_id');
        $prescriberGroup   = $this->groupHelper->getGroupData($prescriberGroupId);

        if (!$prescriberGroup || !$prescriberGroup->getId()) {
            throw new \Exception('Prescriber group data is invalid or missing.');
        }

        $prescriberGroupAddressOne     = $prescriberGroup->getData('address_one') ?: '';
        $prescriberGroupAddressTwo     = $prescriberGroup->getData('address_two') ?: '';
        $prescriberGroupAddressCity    = $prescriberGroup->getData('city') ?: '';
        $prescriberGroupAddressStateId = $prescriberGroup->getData('state');
        $prescriberGroupAddressZipCode = $prescriberGroup->getData('zipcode') ?: '';

        if (!$prescriberGroupAddressCity || !$prescriberGroupAddressStateId || !$prescriberGroupAddressZipCode) {
            throw new \Exception('Incomplete prescriber group address data.');
        }

        $region = $this->regionFactory->create()->load($prescriberGroupAddressStateId);
        $prescriberGroupAddressState = $region->getCode();

        if (!$prescriberGroupAddressState) {
            throw new \Exception('Invalid state information for prescriber group.');
        }

        $settings['doctors_note_logo']             = $prescriberData->getData('doctors_note_logo') ?: '';
        $settings['doctors_note_signature']        = $prescriberData->getData('doctors_note_signature') ?: '';
        $settings['doctors_signature_name']        = $prescriberData->getData('doctors_signature_name') ?: '';
        $settings['doctors_note_pdf_footer_text']  = $prescriberData->getData('doctors_note_pdf_footer_text') ?: '';
        $settings['doctors_note_verification_url'] = $prescriberData->getData('doctors_note_verification_url') ?: '';
        $settings['doctors_npi_number']            = $prescriberData->getData('doctors_npi_number') ?: '';
        $settings['regards_name']                  = trim($prescriberData->getData('firstname') . ' ' . $prescriberData->getData('lastname'));
        $settings['prescriber_group_name']         = $prescriberGroup->getData('group_name') ?: '';
        $settings['prescriber_group_street']       = trim($prescriberGroupAddressOne . ', ' . $prescriberGroupAddressTwo, ', ');
        $settings['prescriber_group_full_address'] = $prescriberGroupAddressCity . ', ' . $prescriberGroupAddressState . ' ' . $prescriberGroupAddressZipCode;

        return $settings;
    }

    /**
     * Finalize and save the PDF
     * * 
     * @param Zend_Pdf $pdf
     * @param Zend_Pdf_Page $page
     * @param \Magento\Sales\Model\Order $orderMain
     * @param string $drNoteType
     * @param string $drNoteTypeForContent
     */
    private function finalizePdf($pdf, $page, $orderMain, $drNoteType, $regardsName, $emailSubject, $drNoteTypeForContent, $fileName)
    {
        $pdf->pages[] = $page;
        $directory    = $this->filesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);
        $filePath     = 'DrNotes/' . $orderMain->getIncrementId() . '/';

        if (!$directory->isExist($filePath)) {
            $directory->create($filePath);
        }

        $fullPath = $filePath . $fileName;
        $directory->writeFile($fullPath, $pdf->render());

        // Send the email with the PDF attachment
        $this->sendEmailWithAttachment($orderMain, $fullPath, $fileName, $drNoteType, $regardsName, $emailSubject, $drNoteTypeForContent);
    }

    /**
     * Function to format date to MM-DD-YYYY
     *
     * @param string $dateString
     * @return void
     */
    private function formatDate($dateString)
    {
        $date = \DateTime::createFromFormat('Y-m-d', $dateString);
        if ($date && $date->format('Y-m-d') === $dateString) {
            return $date->format('m-d-Y');
        }
        return $dateString;
    }

    /**
     * Retrieves the path of the image to be included in the PDF.
     *
     * @return string
     */
    private function getImagePath($image)
    {
        $mediaDirectory = $this->filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);
        return $mediaDirectory->getAbsolutePath($image);
    }

    /**
     * Calculates the width of the text for proper alignment.
     *
     * @param string $text
     * @param Zend_Pdf_Font $font
     * @param int $fontSize
     * @return float
     */
    private function getTextWidth($text, $font, $fontSize)
    {
        $drawingText = $text !== null ? iconv('', 'UTF-16BE', $text) : '';
        $characters  = [];
        for ($i = 0; $i < strlen($drawingText); $i++) {
            $characters[] = (ord($drawingText[$i++]) << 8) | ord($drawingText[$i]);
        }
        $glyphs = $font->glyphNumbersForCharacters($characters);
        $widths = $font->widthsForGlyphs($glyphs);
        return (array_sum($widths) / $font->getUnitsPerEm()) * $fontSize;
    }

    /**
     * Send decline medications notification.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @throws LocalizedException
     */
    private function sendDeclineMedicationsNotification(\Magento\Sales\Api\Data\OrderInterface $order)
    {
        $quoteItems = $this->getQuoteItemsByOrder($order);
        $orderItems = $order->getItems();
        $removedItems = [];
        $orderItemQuoteIds = [];

        foreach ($orderItems as $orderItem) {
            if ($orderItem->getProductType() !== 'simple') {
                continue;
            }

            $orderItemQuoteIds[] = $orderItem->getQuoteItemId();
        }

        foreach ($quoteItems as $quoteItem) {
            if ($quoteItem->getProductType() !== 'simple') {
                continue;
            }
            if (!in_array($quoteItem->getItemId(), $orderItemQuoteIds)) {
                $removedItems[] = $quoteItem->getName();
            }
        }

        if (!empty($removedItems)) {
            $this->sendEmailNotifications($order, $removedItems);
        }
    }

    /**
     * Get quote items by order.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return array
     */
    private function getQuoteItemsByOrder(\Magento\Sales\Api\Data\OrderInterface $order): array
    {
        $quoteId = $order->getQuoteId();

        if (!$quoteId) {
            return [];
        }

        $quote = $this->quoteFactory->create()->load($quoteId);
        return $quote->getAllItems();
    }

    /**
     * Send email notifications.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $removedItems
     * @throws LocalizedException
     */
    private function sendEmailNotifications(\Magento\Sales\Api\Data\OrderInterface $order, array $removedItems)
    {
        $postObject = new \Magento\Framework\DataObject();
        $post = [
            'first_name' => $order->getCustomerFirstname(),
            'last_name' => $order->getCustomerLastname(),
            'removed_items' => $removedItems,
        ];
        $postObject->setData($post);

        $templateOptions = [
            'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
            'store' => $order->getStore()->getId(),
        ];

        $fromEmail = $this->scopeConfig->getValue(
            "trans_email/ident_support/email",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $fromName = $this->scopeConfig->getValue(
            "trans_email/ident_support/name",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $from = ['email' => $fromEmail, 'name' => $fromName];
        $customerEmail = $order->getCustomerEmail();
        $prescriberId = $this->groupHelper->getUserId();
        $prescriberData = $this->groupHelper->getPrescriberData($prescriberId);
        $prescriberEmail = $prescriberData->getData('email');

        $templateId = $this->scopeConfig->getValue(
            "pcustomer/decline_notification/emailtemplate",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $transportForCustomer = $this->transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions($templateOptions)
            ->setTemplateVars(['data' => $postObject, 'order' => $order])
            ->setFrom($from)
            ->addTo($customerEmail)
            ->addBcc('hello@telyrx.com')
            ->getTransport();

        $transportForPrescriber = $this->transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions($templateOptions)
            ->setTemplateVars(['data' => $postObject, 'order' => $order])
            ->setFrom($from)
            ->addTo($prescriberEmail)
            ->getTransport();

        $transportForCustomer->sendMessage();
        $transportForPrescriber->sendMessage();
    }

    private function sendEmailWithAttachment($orderMain, $filePath, $fileName, $drNoteType, $regardsName, $emailSubject, $drNoteTypeForContent = '')
    {
        $postObject = new \Magento\Framework\DataObject();
        $post = [
            'first_name'              => $orderMain->getCustomerFirstname(),
            'last_name'               => $orderMain->getCustomerLastname(),
            'drnote_type'             => $drNoteType,
            'drnote_type_for_content' => $drNoteTypeForContent,
            'regards_name'            => $regardsName,
            'email_subject'           => $emailSubject
        ];
        $postObject->setData($post);

        $templateOptions = [
            'area'  => \Magento\Framework\App\Area::AREA_FRONTEND,
            'store' => $orderMain->getStore()->getId()
        ];

        $fromEmail = $this->scopeConfig->getValue(
            "trans_email/ident_support/email",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $fromName = $this->scopeConfig->getValue(
            "trans_email/ident_support/name",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $from = ['email' => $fromEmail, 'name' => $fromName];
        $customerEmail = $orderMain->getCustomerEmail();

        $templateId = $this->scopeConfig->getValue(
            "pcustomer/doctor_note_email/emailtemplate",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $fileContents = file_get_contents($this->filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)->getAbsolutePath($filePath));

        $this->_transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions($templateOptions)
            ->setTemplateVars(['data' => $postObject])
            ->setFrom($from)
            ->addTo($customerEmail)
            ->addBcc('notes@telyrx.com')
            ->addAttachment($fileContents, $fileName, 'application/pdf');
        $transport = $this->_transportBuilder->getTransport();
        $transport->sendMessage();
    }
}
