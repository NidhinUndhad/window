<?php
namespace Telyrx\OmittedProducts\Model\Rule;

use Magento\Rule\Model\AbstractModel;

class OmittedProducts extends AbstractModel
{
    protected function _construct()
    {
        parent::_construct();
        $this->setIdFieldName('rule_id');
    }

    /**
     * Get omitted products conditions instance
     */
    public function getConditionsInstance()
    {
        return \Magento\Rule\Model\Condition\Combine::class;
    }

    /**
     * Get omitted products actions instance (not used, but required by parent)
     */
    public function getActionsInstance()
    {
        return \Magento\Rule\Model\Action\Collection::class;
    }
}
