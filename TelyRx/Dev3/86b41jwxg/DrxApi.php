<?php

namespace Telyrx\Prescriber\Helper;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;
use Telyrx\Customerlogin\Helper\CountryData;

class DrxApi extends AbstractHelper
{

    protected $request;
    protected $orderRepository;
    protected $_resource;
    protected $countryDataHelper;

    const XML_PATH_ENDPOINTS_URL = 'drx_api/endpoints/base_url';
    const XML_PATH_ENDPOINTS_DEMO_URL = 'drx_api/endpoints/demo_url';
    const XML_PATH_DRX_API = 'drx_api/drxapikey/base_key';
    const XML_PATH_DRX_DEMO_API = 'drx_api/drxapikey/demo_key';
    const XML_PATH_ENDPOINTS_API_MODE = 'drx_api/endpoints/api_mode';
    const XML_PATH_SEARCH_PROVIDER_CATALOG = 'drx_api/endpoints/search_provider_catalog';
    const XML_PATH_CREATE_PATIENT = 'drx_api/endpoints/create_patient';
    const XML_PATH_CREATE_PRESCRIPTION = 'drx_api/endpoints/create_prescription';
    const XML_PATH_GET_PRODUCT = 'drx_api/endpoints/get_product';
    const XML_PATH_ENABLE_REFILL_API = 'drx_api/refillapi/active';

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        Session $authSession,
        \Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory $orderItems,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\App\ResourceConnection $resource,
        CountryData $countryDataHelper
    ) {
        $this->customerFacory = $customerFactory;
        $this->productFactory = $productFactory;
        $this->authSession = $authSession;
        $this->_orderItems = $orderItems;
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->_resource = $resource;
        $this->countryDataHelper = $countryDataHelper;
        parent::__construct($context);
    }

    public function getDrxApiMode($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_ENDPOINTS_API_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get API Key
     * @param int|null $storeId
     * @return bool|string
     */
    public function getDrxApiKey($storeId = null)
    {
        $apiMode = $this->getDrxApiMode();
        if ($apiMode == "production") {
            return $this->scopeConfig->getValue(
                self::XML_PATH_DRX_API,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        } else {
            return $this->scopeConfig->getValue(
                self::XML_PATH_DRX_DEMO_API,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
    }

    /**
     * Get Base URL
     * @param int|null $storeId
     * @return bool|string
     */
    public function getEndpointsUrl($storeId = null)
    {
        $apiMode = $this->getDrxApiMode();
        if ($apiMode == "production") {
            return $this->scopeConfig->getValue(
                self::XML_PATH_ENDPOINTS_URL,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        } else {
            return $this->scopeConfig->getValue(
                self::XML_PATH_ENDPOINTS_DEMO_URL,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
    }

    /**
     * Get Search Provider Catalog
     * @param int|null $storeId
     * @return bool|string
     */
    public function getSearchProviderCatalog($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_SEARCH_PROVIDER_CATALOG,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get Patiend End Point
     * @param int|null $storeId
     * @return bool|string
     */
    public function getPatiendEndPoint($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_CREATE_PATIENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get Product
     * @param int|null $storeId
     * @return bool|string
     */
    public function getProductEndPoint($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_GET_PRODUCT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Create Prescription
     * @param int|null $storeId
     * @return bool|string
     */
    public function getCreatePrescriptionEndPoint($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_CREATE_PRESCRIPTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getRefillApiStatus($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_ENABLE_REFILL_API,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getPatiendId($customerId, $order)
    {
        $result = [];
        try {
            $customer = $this->customerFacory->create()->load($customerId);
            $customerData = $customer->getData();
            if (isset($customerData['telyrx_patient_id']) && !empty($customerData['telyrx_patient_id'])) {
                $result['success'] = true;
                $result['patient_id'] = $customerData['telyrx_patient_id'];
            } else {
                // Create patient in Drx system
                $patientId = $this->createPatientInDrx($customer, $order);
                if (!empty($patientId) && is_numeric($patientId)) {
                    // Save patient ID to customer
                    $customer->setTelyrxPatientId($patientId);
                    $customer->save();
                    // Create third-party patient in Drx system
                    $thirdParty = $this->createPatientThirdPartyInDrx($patientId);
                    if (!empty($thirdParty) && is_numeric($thirdParty)) {
                        $result['success'] = true;
                        $result['patient_id'] = $patientId;
                    } else {
                        throw new \Exception("Patient third-party fetch error: " . $thirdParty);
                    }
                } else {
                    throw new \Exception("Patient fetch error: " . $patientId);
                }
            }
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = $e->getMessage();
        }
        return $result;
    }

    public function createPatientInDrx($customerData, $order)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/prescription.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        $endpointUrl = $this->getEndpointsUrl();
        $patiendEndPt = $this->getPatiendEndPoint();
        $endpointUrl = $endpointUrl . $patiendEndPt;
        $addressArr = $telephone = array();
        $dob = $customerData->getDob();
        $gender = $customerData->getGender();
        $apiKey = $this->getDrxApiKey();
        $telephontArr = array();
        $phoneNumber = $this->removeCountryCodes($customerData->getPhone());

        $logger->info("Create Patient Api: " . $endpointUrl);

        if (!empty($dob)) {
            $dob = date('m/d/Y', strtotime($dob));
        }

        if ($gender == 1) {
            $gender = 'M';
        } elseif ($gender == 2) {
            $gender = 'F';
        } else {
            $gender = '';
        }

        if (!empty($shippingAddress = $order->getShippingAddress())) {
            $street = $shippingAddress->getStreet();

            if (is_array($street)) {
                $streetStr = implode(',', $street);
            } else {
                $streetStr = $street;
            }

            $addressArr = array(
                "city" => $shippingAddress->getCity(),
                "state" => $shippingAddress->getRegionCode(),
                "zip" => $shippingAddress->getPostcode(),
                "type_" => "default",
                "street" => $streetStr,
            );

            $telephontArr = array(
                "phone_type" => "home",
                "number" => $phoneNumber,
            );
        }

        $patiendData = array(
            "first_name" => $customerData->getFirstname(),
            "last_name" => $customerData->getLastname(),
            "dob" => $dob,
            "gender" => $gender,
            "delivery_method" => "Ship",
            "notify_method" => "0",
            "race" => "Human",
        );

        $postData = array(
            'patient' => $patiendData,
            'phone_numbers' => [$telephontArr],
            'addresses' => [$addressArr],
        );

        $postDataJson = json_encode($postData);
        $logger->info("Create Patient Api Payload: " . $postDataJson);

        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        @curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataJson);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-DRX-Key: ' . $apiKey,
            'Content-Type: application/json',
        ));
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = @curl_exec($ch);
        $responseData = json_decode($response, true);
        $logger->info("Create Patient Api Response: " . json_encode($responseData));

        if (isset($responseData['success']) && $responseData['success'] == true) {
            if (isset($responseData['patient_id'])) {
                $patiendId = $responseData['patient_id'];
                return $patiendId;
            } elseif ($responseData['message'] == 'Patient already exists') {
                return 'Patient already exists';
            }
        } elseif (isset($responseData['success']) && $responseData['success'] === false) {
            if (isset($responseData['patient_id'])) {
                $patiendId = $responseData['patient_id'];
                return $patiendId;
            }
            return $responseData['message'];
        } else {
            return $responseData['message'];
        }
        @curl_close($ch);
    }

    public function getDrxProductId($productId, $skuNdc)
    {
        $result = array();
        try {
            $product = $this->productFactory->create()->load($productId);
            $productData = $product->getData();
            if (isset($productData['drx_product_id']) && !empty($productData['drx_product_id'])) {
                $result['success'] = true;
                $result['item_id'] = $productData['drx_product_id'];
            } else {
                //$ndcNumber  =   $product->getSkuNdc();
                if (empty($skuNdc)) {
                    throw new \Exception("Drx Item Id Fetch Error For Product {$product->getEntityId()} : No NDC number available.");
                }
                $skuNdcCorrect = preg_replace("/[^0-9]/", "", $skuNdc);
                $itemId = $this->getItemIdFromDrx($skuNdcCorrect);
                if (!empty($itemId) && is_numeric($itemId)) {
                    $product->setDrxProductId($itemId);
                    $product->save();
                    $result['success'] = true;
                    $result['item_id'] = $itemId;
                } else {
                    throw new \Exception("Drx Item Id Fetch Error For NDC Number {$skuNdc} : " . $itemId);
                }
            }
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = $e->getMessage();
        }
        return $result;
    }

    public function getItemIdFromDrx($ndcNumber)
    {
        $ndcNumber = str_replace('-', '', $ndcNumber);
        $endpointUrl = $this->getEndpointsUrl();
        $productEndPt = $this->getProductEndPoint();
        $apiKey = $this->getDrxApiKey();
        $endpointUrl = $endpointUrl . $productEndPt . '/' . $ndcNumber;

        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        @curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        @curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-DRX-Key: ' . $apiKey));
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = @curl_exec($ch);
        $responseData = json_decode($response, true);
        if (isset($responseData['success']) && $responseData['success'] == true) {
            return $responseData['item']['id'];
        } else {
            return $responseData['message'];
        }
        @curl_close($ch);
    }

    public function getDoctorId($orderMain)
    {
        if ($orderMain->getPrescriberAdminEntityId() != null) {
            $userId = $orderMain->getPrescriberAdminEntityId();
        } else {
            $userId = $this->authSession->getUser()->getUserId();
        }
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $connection = $objectManager->get('Magento\Framework\App\ResourceConnection')->getConnection('\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION');
        $prescriberId = $connection->fetchOne("SELECT prescriber_id FROM prescriber WHERE admin_entity_id = '{$userId}'");
        return $prescriberId;
    }

    public function createPrescription($prescriptionData)
    {

        $result = array();
        $endpointUrl = $this->getEndpointsUrl();
        $prescriptionEndPt = $this->getCreatePrescriptionEndPoint();
        $endpointUrl = $endpointUrl . $prescriptionEndPt;
        $apiKey = $this->getDrxApiKey();
        $postDataJson = json_encode($prescriptionData);
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        @curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataJson);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-DRX-Key: ' . $apiKey,
            'Content-Type: application/json',
            'accept: application/json',
        ));
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = @curl_exec($ch);
        $responseData = json_decode($response, true);
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/prescription.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info(var_export($responseData, true));
        $logger->info($postDataJson);
        if (isset($responseData['success']) && $responseData['success'] == true) {
            $result['success'] = true;
            $result['data'] = $response;
        } else {
            $result['success'] = false;
            $result['message'] = "while creating prescription then " . $responseData['message'];
        }
        return $result;
    }

    public function createPrescriptionFromOrder($order)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/prescription.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        try {
            $logger->info("Order id: " . $order['order_id']);
            $logger->info("Order Increment id " . $order->getIncrementId());

            $magentoCustomerId = $order->getCustomerId();
            $incId = $order->getIncrementId();
            $order_Id = $incId ? $incId : '';
            $patiendIdResult = $this->getPatiendId($magentoCustomerId, $order);
            if (!$patiendIdResult['success']) {
                throw new \Exception($patiendIdResult['message']);
            }
            $drxPatiendId = $patiendIdResult['patient_id'];
            $drxDoctorId = 85;
            if (empty($drxDoctorId)) {
                throw new \Exception("Missing doctor/prescriber id in the user.");
            }
            $orderItems = $order->getAllItems();
            $ship1 = $order->getShippingDescription();
            $shippingMethod = preg_replace('/[^a-zA-Z0-9\.]/', '', $ship1);

            foreach ($orderItems as $orderItemsKey => $orderItemsValue) {
                if ($orderItemsValue->getParentItem()):
                    continue;
                endif;
                $proId = $orderItemsValue->getProductId();
                $itemId = $orderItemsValue->getItemId();
                $product = $this->productFactory->create()->load($proId);

                if ($orderItemsValue->getProductType() == "configurable") {
                    $proIds = $orderItemsValue->getProductOptions()['info_buyRequest']['selected_configurable_option'];
                    $product = $this->productFactory->create()->load($proIds);
                } else {
                    $proIds = $orderItemsValue->getProductId();
                }

                $pillCount = (int) $orderItemsValue->getQtyOrdered();
                $daysSupply = $product->getResource()->getAttribute('number_of_refills')->getFrontend()->getValue($product);
                $sigCode = $product->getDirectionsSigCodes();
                /* CREATE Prescription IN DRX */
                $itemIdResult = $this->getOtcDrxProductId($proIds);
                if (!$itemIdResult['success']) {
                    throw new \Exception($itemIdResult['message']);
                }
                $drxProductId = $itemIdResult['item_id'];
                $days = date('Y-m-d H:m:s', strtotime('+364 days'));
                $customer = $this->customerFacory->create()->load($magentoCustomerId);
                $customerEmail = $customer->getEmail();
                $shippingAddress = $order->getShippingAddress();
                $customerPhoneNumber = $shippingAddress->getTelephone();
                $street = null;
                $city = null;
                $state = null;
                $zipCode = null;

                if ($shippingAddress) {
                    $streetArray = $shippingAddress->getStreet();
                    $street = implode(' ', $streetArray);
                    $city = $shippingAddress->getCity();
                    $state = $shippingAddress->getRegion();
                    $zipCode = $shippingAddress->getPostcode();
                }

                $prescriptionData = array(
                    "patient" => [
                        "id" => $drxPatiendId,
                        "email" => $customerEmail,
                        "phone_number" => $customerPhoneNumber,
                        "address" => [
                            "street" => $street,
                            "city" => $city,
                            "state" => $state,
                            "zip_code" => $zipCode,
                        ],
                    ],
                    "doctor" => [
                        "id" => $drxDoctorId,
                    ],
                    "medication" => [
                        "id" => $drxProductId,
                        "sig" => $sigCode,
                        "quantity" => $pillCount,
                        "dispensed_quantity" => $pillCount,
                        "pharmacist_id" => 9,
                        "days_supply" => $daysSupply,
                        "date_expires" => $days,
                        "origin_code" => "Electronic",
                        "primary_third_party_bin" => "014798",
                        "fill_tags" => [
                            "$shippingMethod",
                        ],
                    ],
                );
                $preResult = $this->createOtcPrescription($prescriptionData);
                if ($preResult['success']) {
                    if (array_key_exists('data', $preResult)) {
                        $dataResult = json_decode($preResult['data'], true);
                        if (array_key_exists('data', $dataResult)) {
                            if (array_key_exists(0, $dataResult['data'])) {
                                if (array_key_exists('rx_id', $dataResult['data'][0])) {
                                    $refillStatus = $this->getRefillApiStatus();
                                    if ($refillStatus == 0) {
                                        $rxId = '';
                                    } else {
                                        $rxId = $dataResult['data'][0]['rx_id'];
                                    }
                                    $order->setRxId($rxId);
                                    $order->setPatientId($drxPatiendId);
                                    $order->save();
                                    $dob = $order->getCustomerDob();
                                    $postRefillRequest = '{
                                      "rx_numbers": [
                                        ' . $rxId . '
                                      ],
                                      "date_of_birth": "' . $dob . '",
                                      "delivery_method": "Delivery",
                                      "patient_id": ' . $drxPatiendId . '
                                    }';
                                    $connection = $this->_resource->getConnection();
                                    $sql = "SELECT * FROM `paradoxlabs_subscription` WHERE `keyword_fulltext` LIKE '%$incId%'";
                                    $result = $connection->fetchAll($sql);
                                    if (count($result) > 0) {
                                        $this->getRefillRequest($postRefillRequest, $order_Id);
                                    }
                                }
                            }
                        }
                    }
                    $orderItem = $this->_orderItems->create()->addFieldToFilter('item_id', $itemId)->getFirstItem();
                    $orderItem->setSigCode($sigCode);
                    $orderItem->setDrxPrescriptionResponse($preResult['data']);
                    $logger->info("success in creating prescription for OTC order number {$incId}.");
                    $orderItem->save();
                } else {
                    throw new \Exception("Unable to prescribe because {$preResult['message']}");
                }
                /* CREATE Prescription IN DRX */
            }
        } catch (\Exception $e) {
            $logger->info("Error in creating prescription for OTC order number {$incId}. Error {$e->getMessage()}");
        }
    }

    public function prescription($drxPatiendId, $sigCode, $pillCount, $drxDoctorId, $drxProductId, $refillCount, $shippingMethod, $magentoCustomerId, $orderMain)
    {
        $days = date('Y-m-d H:m:s', strtotime('+364 days'));
        $customer = $this->customerFacory->create()->load($magentoCustomerId);
        $customerEmail = $customer->getEmail();
        $shippingAddress = $orderMain->getShippingAddress();
        $customerPhoneNumber = $shippingAddress->getTelephone();
        $street = null;
        $city = null;
        $state = null;
        $zipCode = null;

        if ($shippingAddress) {
            $streetArray = $shippingAddress->getStreet();
            $street = implode(' ', $streetArray);
            $city = $shippingAddress->getCity();
            $state = $shippingAddress->getRegion();
            $zipCode = $shippingAddress->getPostcode();
        }
        $prescriptionData = array(
            "patient" => [
                "id" => $drxPatiendId,
                "email" => $customerEmail,
                "phone_number" => $customerPhoneNumber,
                "address" => [
                    "street" => $street,
                    "city" => $city,
                    "state" => $state,
                    "zip_code" => $zipCode,
                ],
            ],
            "doctor" => [
                "id" => $drxDoctorId,
            ],
            "medication" => [
                "id" => $drxProductId,
                "sig" => $sigCode,
                "quantity" => $pillCount,
                "refills" => $refillCount,
                "dispensed_quantity" => $pillCount,
                "days_supply" => $pillCount,
                "status" => "Print",
                "date_expires" => $days,
                "origin_code" => "Electronic",
                "primary_third_party_bin" => "014798",
                "fill_tags" => [
                    "$shippingMethod",
                ],
            ],
        );
        return $prescriptionData;
    }

    public function drxDoctorId($orderItemsValue, $orderMain)
    {
        $productData = $this->productFactory->create()->loadByAttribute('sku', $orderItemsValue->getSku());
        if (($productData && $productData->getAttributeSetId()) && ($productData->getAttributeSetId() == 11 || $productData->getAttributeSetId() == 15)) {
            $drxDoctorId = 85;
        } else {
            $drxDoctorId = $this->getDoctorId($orderMain);
        }

        if (empty($drxDoctorId)) {
            throw new \Exception("Missing doctor/prescriber id in the user.");
        }
        return $drxDoctorId;
    }

    public function skuNdc($product, $orderItemsValue)
    {
        $configSkuNdc = $product->getSkuNdc();
        if ($orderItemsValue->getProductType() == "configurable") {
            $productOptions = $orderItemsValue->getProductOptions();
            if (isset($productOptions['info_buyRequest']) && isset($productOptions['info_buyRequest']['selected_configurable_option']) && !empty($productOptions['info_buyRequest']['selected_configurable_option'])) {
                $selectedOption  = $productOptions['info_buyRequest']['selected_configurable_option'];
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
            $simpleProduct = $this->productFactory->create()->load($simpleProductId);
            $simpleSkuNdc = $simpleProduct->getSkuNdc();
        }
        $skuNdc = $configSkuNdc ? $configSkuNdc : $simpleSkuNdc;
        return $skuNdc;
    }

    public function pillCount($orderItemsValue)
    {
        $productData = $this->productFactory->create()->loadByAttribute('sku', $orderItemsValue->getSku());
        $simplePillCount = $productData->getPrescribedQuantity();
        $pillCount = isset($simplePillCount) ? $simplePillCount : 1;
        return (int) $pillCount;
    }

    public function createOtcPrescription($prescriptionData)
    {
        $result = array();
        $endpointUrl = $this->getEndpointsUrl();
        $prescriptionEndPt = $this->getCreatePrescriptionEndPoint();
        $endpointUrl = $endpointUrl . $prescriptionEndPt;
        $apiKey = $this->getDrxApiKey();
        $postDataJson = json_encode($prescriptionData);
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        @curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataJson);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-DRX-Key: ' . $apiKey,
            'Content-Type: application/json',
            'accept: application/json',
        ));
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = @curl_exec($ch);
        $responseData = json_decode($response, true);
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/prescription.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info(var_export($responseData, true));
        $logger->info($postDataJson);

        if (isset($responseData['success']) && $responseData['success'] == true) {
            $result['success'] = true;
            $result['data'] = $response;
        } else {
            $result['success'] = false;
            $result['message'] = "while creating prescription then " . $responseData['message'];
        }
        return $result;
    }

    public function getOtcDrxProductId($productId)
    {
        $result = array();
        try {
            $product = $this->productFactory->create()->load($productId);
            $productData = $product->getData();

            if (isset($productData['drx_product_id']) && !empty($productData['drx_product_id'])) {
                $result['success'] = true;
                $result['item_id'] = $productData['drx_product_id'];
            } else {
                $ndcNumber = $product->getSkuNdc();

                if (empty($ndcNumber)) {
                    throw new \Exception("Drx Item Id Fetch Error For Product {$product->getEntityId()} : No NDC number available.");
                }
                $itemId = $this->getItemIdFromDrx($ndcNumber);
                if (!empty($itemId) && is_numeric($itemId)) {
                    $product->setDrxProductId($itemId);
                    $product->save();
                    $result['success'] = true;
                    $result['item_id'] = $itemId;
                } else {
                    throw new \Exception("Drx Item Id Fetch Error For NDC Number {$ndcNumber} : " . $itemId);
                }
            }
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = $e->getMessage();
        }
        return $result;
    }

    public function getRefillRequest($postRefillRequest, $order_Id)
    {
        if ($order_Id && $order_Id != '') {
            $orderIncrementId = $order_Id;
        }

        $result = array();
        $endpointUrl = $this->getEndpointsUrl();
        $prescriptionEndPt = $this->getCreatePrescriptionEndPoint();
        $endpointUrl = $endpointUrl . 'refill-request';
        $apiKey = $this->getDrxApiKey();
        //$postDataJson       =   json_encode($postRefillRequest);
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        @curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postRefillRequest);
        @curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-DRX-Key: ' . $apiKey,
            'Content-Type: application/json',
            'accept: application/json',
        ));
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = @curl_exec($ch);
        $responseData = json_decode($response, true);
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/refill_request.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info("Order Increment id :" . $orderIncrementId);
        $logger->info(var_export($postRefillRequest, true));
        $logger->info(var_export($responseData, true));
    }

    /**
     * Creates a third-party patient in Drx system.
     *
     * @param int $patientId The ID of the patient.
     * @return int|string The ID of the created third-party or error message.
     */
    public function createPatientThirdPartyInDrx($patientId)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/prescription.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info("patientId :" . $patientId);

        // Constructing endpoint URL and API key
        $endpointUrl = $this->getEndpointsUrl() . 'third-parties/' . $patientId;
        $apiKey = $this->getDrxApiKey();
        $logger->info("endpointUrl :" . $endpointUrl);

        // Constructing POST data
        $postData = [
            "bin_number" => "014798",
            "name" => "TelyRx Cash Pay",
            "pcn" => "DRX",
            "group" => "DRX",
            "cardholder_id" => 12345,
            "start_date" => date("d-m-Y"),
            "end_date" => "01-01-2099",
        ];
        $postDataJson = json_encode($postData);
        $logger->info(var_export($postDataJson, true));

        // Initializing cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpointUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'X-DRX-Key: ' . $apiKey,
            'Content-Type: application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Executing cURL request
        $response = curl_exec($ch);
        $responseData = json_decode($response, true);
        $logger->info(var_export($responseData, true));

        // Handling response
        if (isset($responseData['success']) && $responseData['success'] == true) {
            $thirdPartyId = $responseData['third_party']['id'];
            curl_close($ch);
            return $thirdPartyId;
        } else {
            $errorMessage = isset($responseData['message']) ? $responseData['message'] : 'Unknown error occurred';
            curl_close($ch);
            return $errorMessage;
        }
    }
    public function removeCountryCodes($phoneNumber)
    {
        $countryCollection = $this->countryDataHelper->getCountryCollection();
        foreach ($countryCollection as $country) {
            if (isset($country['code'])) {
                $code = $country['code'];
                if (strpos($phoneNumber, $code) === 0) {
                    $phoneNumber = substr($phoneNumber, strlen($code));
                    break;
                }
            }
        }

        $phoneNumber = preg_replace("/[^0-9]/", "", $phoneNumber);

        return substr($phoneNumber, -10);
    }
}
