<?php
/**
 * Copyright © Telyrx. All rights reserved.
 */
declare(strict_types=1);

namespace Telyrx\OmittedProducts\Block\Adminhtml\Promo\Quote\Edit\Tab;

use Magento\Framework\App\ObjectManager;
use Magento\Backend\Block\Widget\Form\Renderer\Fieldset;
use Magento\SalesRule\Model\Rule;

/**
 * Block for rendering Omitted Products Conditions tab on Sales Rules creation page.
 */
class OmittedConditions extends \Magento\Backend\Block\Widget\Form\Generic implements
    \Magento\Ui\Component\Layout\Tabs\TabInterface
{
    /**
     * Core registry
     *
     * @var \Magento\Backend\Block\Widget\Form\Renderer\Fieldset
     */
    protected $_rendererFieldset;

    /**
     * @var \Magento\Rule\Block\Conditions
     */
    protected $_conditions;

    /**
     * @var string
     */
    protected $_nameInLayout = 'omitted_conditions_apply_to';

    /**
     * @var \Magento\SalesRule\Model\RuleFactory
     */
    private $ruleFactory;

    /**
     * @var \Telyrx\OmittedProducts\Block\Rule\OmittedConditions
     */
    protected $_omittedConditionsRenderer;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Data\FormFactory $formFactory
     * @param \Magento\Rule\Block\Conditions $conditions
     * @param \Magento\Backend\Block\Widget\Form\Renderer\Fieldset $rendererFieldset
     * @param array $data
     * @param \Magento\SalesRule\Model\RuleFactory|null $ruleFactory
     * @param \Telyrx\OmittedProducts\Block\Rule\OmittedConditions|null $omittedConditionsRenderer
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Data\FormFactory $formFactory,
        \Magento\Rule\Block\Conditions $conditions,
        \Magento\Backend\Block\Widget\Form\Renderer\Fieldset $rendererFieldset,
        array $data = [],
        \Magento\SalesRule\Model\RuleFactory $ruleFactory = null,
        \Telyrx\OmittedProducts\Block\Rule\OmittedConditions $omittedConditionsRenderer = null
    ) {
        $this->_rendererFieldset = $rendererFieldset;
        $this->_conditions = $conditions;
        $this->ruleFactory = $ruleFactory ?: ObjectManager::getInstance()
            ->get(\Magento\SalesRule\Model\RuleFactory::class);
        $this->_omittedConditionsRenderer = $omittedConditionsRenderer ?: ObjectManager::getInstance()
            ->get(\Telyrx\OmittedProducts\Block\Rule\OmittedConditions::class);
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * @inheritdoc
     */
    public function getTabClass()
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getTabUrl()
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function isAjaxLoaded()
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getTabLabel()
    {
        return __('Omitted Products');
    }

    /**
     * @inheritdoc
     */
    public function getTabTitle()
    {
        return __('Omitted Products');
    }

    /**
     * @inheritdoc
     */
    public function canShowTab()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function isHidden()
    {
        return false;
    }

    /**
     * Prepare form before rendering HTML
     *
     * @return $this
     */
    protected function _prepareForm()
    {
        $model = $this->_coreRegistry->registry(\Magento\SalesRule\Model\RegistryConstants::CURRENT_SALES_RULE);
        $form = $this->addTabToForm($model);
        $this->setForm($form);

        return parent::_prepareForm();
    }

    /**
     * Handles addition of omitted products conditions tab to supplied form.
     *
     * @param Rule $model
     * @param string $fieldsetId
     * @param string $formName
     * @return \Magento\Framework\Data\Form
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function addTabToForm($model, $fieldsetId = 'omitted_conditions_fieldset', $formName = 'sales_rule_form')
    {
        if (!$model) {
            $id = $this->getRequest()->getParam('id');
            $model = $this->ruleFactory->create();
            $model->load($id);
        }

        // Create unique fieldset ID for omitted conditions
        $omittedConditionsFieldSetId = $formName . '_omitted_conditions';
        $newChildUrl = $this->getUrl(
            'telyrx_omittedproducts/promo_quote/newOmittedConditionHtml/form/' . $omittedConditionsFieldSetId,
            ['form_namespace' => $formName]
        );

        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create();
        $form->setHtmlIdPrefix('omitted_rule_');

        $renderer = $this->getLayout()->createBlock(Fieldset::class);
        $renderer->setTemplate(
            'Magento_CatalogRule::promo/fieldset.phtml'
        )->setNewChildUrl(
            $newChildUrl
        )->setFieldSetId(
            $omittedConditionsFieldSetId
        );

        $fieldset = $form->addFieldset(
            $fieldsetId,
            [
                'legend' => __(
                    'Omit products from rule if the following conditions are met (leave blank to include all products).'
                )
            ]
        )->setRenderer(
            $renderer
        );

        // Get omitted conditions from model or create new ones
        $omittedConditions = $model->getOmittedConditions();
        if (!$omittedConditions) {
            $omittedConditions = $model->getConditionsInstance();
            $omittedConditions->setRule($model);
            $omittedConditions->setId('omitted_conditions');
            $omittedConditions->setPrefix('omitted_conditions');
        }

        // Set unique form name and ID prefix for omitted conditions
        $this->setConditionFormName($omittedConditions, $formName, 'omitted_');

        $fieldset->addField(
            'omitted_conditions',
            'text',
            [
                'name'           => 'omitted_conditions',
                'label'          => __('Omitted Conditions'),
                'title'          => __('Omitted Conditions'),
                'required'       => false,
                'data-form-part' => $formName
            ]
        )->setRule(
            $model
        )->setRenderer(
            $this->_omittedConditionsRenderer
        );

        $form->setValues($model->getData());
        return $form;
    }

    /**
     * Handles addition of form name to condition and its conditions.
     *
     * @param \Magento\Rule\Model\Condition\AbstractCondition $conditions
     * @param string $formName
     * @param string $idPrefix
     * @return void
     */
    private function setConditionFormName(\Magento\Rule\Model\Condition\AbstractCondition $conditions, $formName, $idPrefix = 'omitted_')
    {
        $conditions->setFormName($formName);
        
        // Set unique ID prefix to avoid conflicts
        $conditions->setIdPrefix($idPrefix);
        
        if ($conditions->getConditions() && is_array($conditions->getConditions())) {
            foreach ($conditions->getConditions() as $condition) {
                $this->setConditionFormName($condition, $formName, $idPrefix);
            }
        }
    }
}