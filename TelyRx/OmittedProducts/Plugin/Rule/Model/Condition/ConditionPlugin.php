<?php
/**
 * Copyright © Telyrx. All rights reserved.
 */
declare(strict_types=1);

namespace Telyrx\OmittedProducts\Plugin\Rule\Model\Condition;

use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * Plugin to ensure unique IDs for omitted conditions
 */
class ConditionPlugin
{
    /**
     * Around getId to add prefix for omitted conditions
     *
     * @param AbstractCondition $subject
     * @param callable $proceed
     * @return string
     */
    public function aroundGetId(AbstractCondition $subject, callable $proceed)
    {
        $id = $proceed();
        
        // Check if this is an omitted condition by looking at the prefix or form name
        $prefix = $subject->getPrefix();
        $formName = $subject->getFormName();
        
        if ($prefix === 'omitted_conditions' || strpos($formName, 'omitted') !== false) {
            return 'omitted_' . $id;
        }
        
        return $id;
    }

    /**
     * Around asHtml to ensure proper ID handling
     *
     * @param AbstractCondition $subject
     * @param callable $proceed
     * @return string
     */
    public function aroundAsHtml(AbstractCondition $subject, callable $proceed)
    {
        $prefix = $subject->getPrefix();
        
        if ($prefix === 'omitted_conditions') {
            // Temporarily set a unique ID prefix
            $originalId = $subject->getId();
            if (strpos($originalId, 'omitted_') !== 0) {
                $subject->setId('omitted_' . $originalId);
            }
        }
        
        return $proceed();
    }
}