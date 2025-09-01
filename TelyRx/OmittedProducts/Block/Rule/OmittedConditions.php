<?php
/**
 * Copyright © Telyrx. All rights reserved.
 */
declare(strict_types=1);

namespace Telyrx\OmittedProducts\Block\Rule;

use Magento\Rule\Block\Conditions;

/**
 * Custom conditions renderer for omitted products to avoid ID conflicts
 */
class OmittedConditions extends Conditions
{
    /**
     * @var string
     */
    protected $_nameInLayout = 'omitted_conditions_renderer';

    /**
     * Render condition as HTML
     *
     * @return string
     */
    public function _toHtml()
    {
        $this->setIdPrefix('omitted_');
        return parent::_toHtml();
    }

    /**
     * Get new child URL for AJAX requests
     *
     * @return string
     */
    public function getNewChildUrl()
    {
        return $this->getUrl('telyrx_omittedproducts/promo_quote/newOmittedConditionHtml', $this->getRequest()->getParams());
    }
}