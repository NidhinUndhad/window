<?php

namespace Telyrx\Subscriptions\Model\ResourceModel\Subscription;

use ParadoxLabs\Subscriptions\Model\ResourceModel\Subscription\UiCollection as BaseUiCollection;

class UiCollection extends BaseUiCollection
{
    /**
     * Initialize the collection select
     * 
     * @return $this
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $this->getSelect()->columns(
            [
                'show_0_refills' => new \Zend_Db_Expr(
                    "CASE 
                        WHEN main_table.length = 0 THEN 0
                        WHEN main_table.length < main_table.run_count THEN 0
                        WHEN main_table.length = main_table.run_count THEN 1
                        ELSE 0
                    END"
                ),
                'subscription_status' => new \Zend_Db_Expr(
                    "CASE 
                        WHEN main_table.status = 'active' AND main_table.length = 0 THEN 'Active'
                        WHEN main_table.status = 'active' AND main_table.length > main_table.run_count THEN 'Active'
                        WHEN main_table.status = 'active' AND main_table.length <= main_table.run_count THEN 'Inactive'
                        ELSE 'Inactive'
                    END"
                ),
                'remaining_refills' => new \Zend_Db_Expr('GREATEST(main_table.length - main_table.run_count, 0)')
            ]
        );

        $this->addFilterToMap(
            'show_0_refills',
            new \Zend_Db_Expr(
                "CASE 
                    WHEN main_table.length = 0 THEN 0
                    WHEN main_table.length < main_table.run_count THEN 0
                    WHEN main_table.length = main_table.run_count THEN 1
                    ELSE 0
                END"
            )
        );

        $this->addFilterToMap(
            'subscription_status',
            new \Zend_Db_Expr(
                "CASE
                    WHEN main_table.status = 'active' AND main_table.length = 0 THEN 'Active'
                    WHEN main_table.status = 'active' AND main_table.length > main_table.run_count THEN 'Active'
                    WHEN main_table.status = 'active' AND main_table.length <= main_table.run_count THEN 'Inactive'
                    ELSE 'Inactive'
                END"
            )
        );

        $this->addFilterToMap(
            'remaining_refills',
            new \Zend_Db_Expr('GREATEST(main_table.length - main_table.run_count, 0)')
        );        
        return $this;
    }
}
