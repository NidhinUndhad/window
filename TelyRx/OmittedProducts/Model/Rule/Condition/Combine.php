<?php
namespace Telyrx\OmittedProducts\Model\Rule\Condition;

use Magento\Rule\Model\Condition\Combine as RuleCombine;

class Combine extends RuleCombine
{
    public function __construct(
        \Magento\Rule\Model\Condition\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    public function getNewChildSelectOptions()
    {
        $conditions = parent::getNewChildSelectOptions();
        // Add custom conditions here if needed
        return $conditions;
    }
}
