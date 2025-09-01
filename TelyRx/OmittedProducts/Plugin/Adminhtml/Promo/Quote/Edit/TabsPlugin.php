<?php
namespace Telyrx\OmittedProducts\Plugin\Adminhtml\Promo\Quote\Edit;

class TabsPlugin
{
    public function afterToHtml(
        \Magento\SalesRule\Block\Adminhtml\Promo\Quote\Edit\Tabs $subject,
        $result
    ) {
        // Add logic to inject the custom tab here
        // This is a placeholder for the omitted products tab
        return $result;
    }
}
