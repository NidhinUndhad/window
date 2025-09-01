<?php
/**
 * Copyright © Telyrx. All rights reserved.
 */
declare(strict_types=1);

namespace Telyrx\OmittedProducts\Plugin\Model;

use Magento\SalesRule\Model\Rule;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Plugin for SalesRule model to handle omitted conditions
 */
class SalesRulePlugin
{
    /**
     * @var Json
     */
    private $serializer;

    /**
     * @param Json $serializer
     */
    public function __construct(Json $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * After load plugin to load omitted conditions
     *
     * @param Rule $subject
     * @param Rule $result
     * @return Rule
     */
    public function afterLoad(Rule $subject, Rule $result)
    {
        if ($result->getId()) {
            $this->loadOmittedConditions($result);
        }
        return $result;
    }

    /**
     * Before save plugin to save omitted conditions
     *
     * @param Rule $subject
     * @return array
     */
    public function beforeSave(Rule $subject)
    {
        $this->saveOmittedConditions($subject);
        return [];
    }

    /**
     * Load omitted conditions from database
     *
     * @param Rule $rule
     * @return void
     */
    private function loadOmittedConditions(Rule $rule)
    {
        $omittedConditionsData = $rule->getData('omitted_conditions_serialized');
        if ($omittedConditionsData) {
            try {
                $conditions = $this->serializer->unserialize($omittedConditionsData);
                $omittedConditions = $rule->getConditionsInstance();
                $omittedConditions->setRule($rule);
                $omittedConditions->setId('omitted_conditions');
                $omittedConditions->setPrefix('omitted_conditions');
                $omittedConditions->loadArray($conditions);
                $rule->setOmittedConditions($omittedConditions);
            } catch (\Exception $e) {
                // If unserialization fails, create empty conditions
                $omittedConditions = $rule->getConditionsInstance();
                $omittedConditions->setRule($rule);
                $omittedConditions->setId('omitted_conditions');
                $omittedConditions->setPrefix('omitted_conditions');
                $rule->setOmittedConditions($omittedConditions);
            }
        } else {
            // Create empty omitted conditions if none exist
            $omittedConditions = $rule->getConditionsInstance();
            $omittedConditions->setRule($rule);
            $omittedConditions->setId('omitted_conditions');
            $omittedConditions->setPrefix('omitted_conditions');
            $rule->setOmittedConditions($omittedConditions);
        }
    }

    /**
     * Save omitted conditions to database
     *
     * @param Rule $rule
     * @return void
     */
    private function saveOmittedConditions(Rule $rule)
    {
        $omittedConditions = $rule->getOmittedConditions();
        if ($omittedConditions) {
            $conditionsArray = $omittedConditions->asArray();
            $rule->setData('omitted_conditions_serialized', $this->serializer->serialize($conditionsArray));
        }
    }
}