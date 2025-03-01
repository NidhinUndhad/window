<?php

namespace Telyrx\Customerlogin\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderItems implements ArgumentInterface
{
    protected $shippingConfig;
    protected $scopeConfig;
    protected $orderRepository;
    protected $resourceConnection;

    public function __construct(
        ShippingConfig $shippingConfig,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        OrderRepositoryInterface $orderRepository,
        ResourceConnection $resourceConnection
    ) {
        $this->shippingConfig = $shippingConfig;
        $this->scopeConfig = $scopeConfig;
        $this->orderRepository = $orderRepository;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Get enabled shipping methods
     *
     * @return array
     */
    public function getAvailableShippingMethods()
    {
        $carriers = $this->shippingConfig->getActiveCarriers();
        $methods = [];

        foreach ($carriers as $carrierCode => $carrierModel) {
            if ($carrierMethods = $carrierModel->getAllowedMethods()) {
                $carrierTitle = $this->scopeConfig->getValue('carriers/' . $carrierCode . '/title');
                $carrierPrice = $this->scopeConfig->getValue('carriers/' . $carrierCode . '/price');

                foreach ($carrierMethods as $methodCode => $methodTitle) {
                    if ($carrierCode == 'flatratethree' || $carrierCode == 'freeshipping') {
                        continue;
                    }
                    $methods[] = [
                        'value' => $carrierCode,
                        'label' => $methodTitle . ' - ' . $carrierTitle . ' $' . $carrierPrice
                    ];
                }
            }
        }
        return $methods;
    }

    public function getSubscriptionDataForItem($item)
    {
        $productName = $item->getName();
        $orderId = $item->getOrderId();

        try {
            $order = $this->orderRepository->get($orderId);
            $orderNumber = $order->getIncrementId();

            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from('paradoxlabs_subscription')
                ->where('keyword_fulltext LIKE ?', '%' . $orderNumber . '%')
                ->where('description LIKE ?', '%' . $productName . '%');

            return $connection->fetchAll($select);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function isSubscriptionItem($item)
    {
        return !empty($this->getSubscriptionDataForItem($item));
    }

    public function isAvailableRefillForItem($item)
    {
        $refillData = $this->getSubscriptionDataForItem($item);
        foreach ($refillData as $row) {
            if (isset($row['length']) && isset($row['run_count']) && $row['length'] > $row['run_count']) {
                return true;
            }
        }
        return false;
    }
}
