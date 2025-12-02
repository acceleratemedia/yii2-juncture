<?php

namespace bvb\juncture\widgets;

use kartik\date\DatePicker;
use kartik\select2\Select2;
use yii\base\InvalidConfigException;
use yii\bootstrap4\InputWidget;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\View;

/**
 * Widget for managing one-to-many child records within a juncture relationship
 * Used as an INPUT_WIDGET within JunctureField
 */
class InlineRepeaterField extends InputWidget
{
    /**
     * Input type constants matching JunctureField
     */
    const INPUT_TEXT = 'textInput';
    const INPUT_DROPDOWN = 'dropdownList';
    const INPUT_TEXTAREA = 'textArea';
    const INPUT_DATEPICKER = 'datepicker';
    const INPUT_SELECT2 = 'select2';
    const INPUT_WIDGET = 'widget';

    /**
     * @var \yii\widgets\ActiveForm
     */
    public $form;

    /**
     * The child model class name
     * @var string
     */
    public $childModelClass;

    /**
     * Array of existing child records for this parent
     * @var array
     */
    public $childRecords = [];

    /**
     * Foreign key columns that link to parent
     * @var array
     */
    public $parentFkColumns = [];

    /**
     * Values for the foreign key columns from the parent record
     * Will be set dynamically via JavaScript when used in JunctureField
     * @var array
     */
    public $parentFkValues = [];

    /**
     * Configuration for child model attributes to display
     * Similar structure to JunctureField's junctureAttributes
     * @var array
     */
    public $childAttributes = [];

    /**
     * Label for the add button
     * @var string
     */
    public $addButtonLabel = '+Add';

    /**
     * Whether to show the repeater collapsed by default
     * @var bool
     */
    public $collapsed = true;

    /**
     * CSS class for the container
     * @var string
     */
    public $containerClass = 'inline-repeater-container';

    /**
     * Name prefix for form inputs
     * Will be set based on parent juncture data structure
     * @var string
     */
    public $namePrefix;

    /**
     * The attribute name used in the form data structure for child records
     * Default is 'items' but can be customized (e.g., 'bonuses', 'details', etc.)
     * @var string
     */
    public $childAttributeName = 'items';

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        if (empty($this->childModelClass)) {
            throw new InvalidConfigException('The "childModelClass" property must be set.');
        }

        if (empty($this->childAttributes)) {
            throw new InvalidConfigException('The "childAttributes" property must be set.');
        }

        if (empty($this->parentFkColumns)) {
            throw new InvalidConfigException('The "parentFkColumns" property must be set.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->registerAssets();

        // Force DatePicker asset registration if needed
        $hasDatepicker = false;
        foreach ($this->childAttributes as $attrConfig) {
            if (($attrConfig['input'] ?? null) === self::INPUT_DATEPICKER) {
                $hasDatepicker = true;
                break;
            }
        }

        if ($hasDatepicker) {
            // Register DatePicker assets even if no rows exist by rendering a hidden widget
            // This ensures the JS/CSS are loaded for dynamically added rows. This really only
            // matters if there are no existing rows, otherwise the assets will be loaded anyway.
            DatePicker::widget([
                'name' => 'dummy-datepicker-' . $this->getId(),
                'options' => ['style' => 'display:none;'],
                'pluginOptions' => [
                    'autoclose' => true,
                    'format' => 'yyyy-mm-dd'
                ]
            ]);
        }

        $childModel = new $this->childModelClass();
        $containerId = $this->getId() . '-container';
        $rowsContainerId = $this->getId() . '-rows';

        ob_start();
?>
        <div id="<?= $containerId ?>" class="<?= $this->containerClass ?>" data-widget-id="<?= $this->getId() ?>">
            <button type="button" class="btn btn-sm btn-secondary inline-repeater-add mb-2" data-target="<?= $rowsContainerId ?>">
                <?= $this->addButtonLabel ?>
            </button>

            <?php if ($this->collapsed) : ?>
                <a href="#" class="inline-repeater-toggle ml-2" data-target="<?= $rowsContainerId ?>">
                    Collapse / Expand
                    <span class="badge badge-info row-count">
                        <?= count($this->childRecords) ?>
                    </span>
                </a>
            <?php endif; ?>
        </div>

        <div id="<?= $rowsContainerId ?>-template" style="display:none;">
            <tr class="inline-repeater-full-row <?= $this->collapsed ? 'collapse' : '' ?>" id="<?= $rowsContainerId ?>">
                <td colspan="100%">
                    <div class="inline-repeater-rows">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <?php foreach ($this->childAttributes as $attrConfig) : ?>
                                        <th <?= $childModel->getAttributeHint($attrConfig['attribute']) ? 'data-title="' . Html::encode($childModel->getAttributeHint($attrConfig['attribute'])) . '"' : '' ?>>
                                            <?= isset($attrConfig['label']) ? $attrConfig['label'] : $childModel->getAttributeLabel($attrConfig['attribute']) ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th width="50">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->childRecords as $index => $childRecord) : ?>
                                    <?= $this->renderChildRow($childRecord, $index, strtolower($childModel->formName())) ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Renders a single child record row
     * @param \yii\db\ActiveRecord $childRecord
     * @param int $index
     * @param string $childFormName
     * @return string
     */
    protected function renderChildRow($childRecord, $index, $childFormName)
    {
        $rowId = $this->getId() . '-row-' . $index;

        ob_start();
    ?>
        <tr id="<?= $rowId ?>" data-index="<?= $index ?>">
            <?php foreach ($this->childAttributes as $attrConfig) : ?>
                <td>
                    <?= $this->renderChildField($childRecord, $attrConfig, $index, $childFormName) ?>
                </td>
            <?php endforeach; ?>
            <td>
                <button type="button" class="btn btn-sm btn-danger inline-repeater-delete" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
<?php
        return ob_get_clean();
    }

    /**
     * Renders a single field for a child record
     * @param \yii\db\ActiveRecord $childRecord
     * @param array $attrConfig
     * @param int $index
     * @param string $childFormName
     * @return string
     */
    protected function renderChildField($childRecord, $attrConfig, $index, $childFormName)
    {
        $attribute = $attrConfig['attribute'];
        $inputType = $attrConfig['input'] ?? self::INPUT_TEXT;

        // Build the field name: parentPrefix[childAttributeName][0][attribute]
        $fieldName = $this->namePrefix . '[' . $this->childAttributeName . '][' . $index . '][' . $attribute . ']';
        $fieldId = $this->getId() . '-' . $attribute . '-' . $index;

        $inputOptions = ArrayHelper::merge([
            'id' => $fieldId,
            'name' => $fieldName,
            'class' => 'form-control form-control-sm'
        ], $attrConfig['inputOptions'] ?? []);

        // Render hidden fields for parent FKs
        $hiddenFields = '';
        foreach ($this->parentFkColumns as $fkColumn) {
            $fkFieldName = $this->namePrefix . '[' . $this->childAttributeName . '][' . $index . '][' . $fkColumn . ']';
            $fkValue = $this->parentFkValues[$fkColumn] ?? $childRecord->{$fkColumn} ?? '';
            $hiddenFields .= Html::hiddenInput($fkFieldName, $fkValue, [
                'class' => 'parent-fk-field',
                'data-fk-column' => $fkColumn
            ]);
        }

        // Render the ID field if this is an existing record
        if (!$childRecord->isNewRecord) {
            $idFieldName = $this->namePrefix . '[' . $this->childAttributeName . '][' . $index . '][id]';
            $hiddenFields .= Html::hiddenInput($idFieldName, $childRecord->id);
        }

        $fieldHtml = $hiddenFields;

        // Use JunctureField's approach - use ActiveField for all fields to get automatic validation
        $activeFieldOptions = [
            'template' => '{input}{error}',
            'selectors' => [
                'input' => '#' . $fieldId,
                'container' => '#' . $fieldId . '-container',
            ],
            'options' => [
                'id' => $fieldId . '-container',
                'validation_id' => $fieldId
            ]
        ];

        // Check if field is required and add to inputOptions
        $isRequired = false;
        foreach ($childRecord->rules() as $rule) {
            if (is_array($rule) && isset($rule[0]) && isset($rule[1])) {
                $attributes = is_array($rule[0]) ? $rule[0] : [$rule[0]];
                if (in_array($attribute, $attributes) && $rule[1] === 'required') {
                    $isRequired = true;
                    break;
                }
            }
        }

        if ($isRequired) {
            $inputOptions['required'] = true;
        }

        $activeField = $this->form->field($childRecord, $attribute, $activeFieldOptions);

        // Register validation using JunctureField's approach (skip for widgets as they handle their own validation)
        if ($inputType !== self::INPUT_WIDGET) {
            $this->registerChildFieldValidation($childRecord, $attribute, $fieldId, $fieldName);
        }

        switch ($inputType) {
            case self::INPUT_TEXTAREA:
                $fieldHtml .= $activeField->textArea($inputOptions);
                break;

            case self::INPUT_DROPDOWN:
                $data = $attrConfig['data'] ?? [];
                $fieldHtml .= $activeField->dropDownList($data, $inputOptions);
                break;

            case self::INPUT_DATEPICKER:
                $fieldHtml .= $activeField->widget(DatePicker::class, [
                    'options' => $inputOptions,
                    'pluginOptions' => [
                        'autoclose' => true,
                        'format' => 'yyyy-mm-dd'
                    ]
                ]);
                break;

            case self::INPUT_SELECT2:
                $data = $attrConfig['data'] ?? [];
                $fieldHtml .= $activeField->widget(Select2::class, [
                    'data' => $data,
                    'options' => $inputOptions
                ]);
                break;

            case self::INPUT_WIDGET:
                // Handle custom widgets using ActiveField like JunctureField's getNewInput() method
                if (!isset($attrConfig['widgetClass'])) {
                    throw new InvalidConfigException('The "widgetClass" property must be set when using INPUT_WIDGET type.');
                }

                $widgetOptions = $attrConfig['widgetOptions'] ?? [];
                $widgetOptions['options'] = $inputOptions;

                // Render widget through ActiveField
                $fieldHtml .= $activeField->widget($attrConfig['widgetClass'], $widgetOptions);
                break;

            default:
            case self::INPUT_TEXT:
                $fieldHtml .= $activeField->textInput($inputOptions);
                break;
        }

        return $fieldHtml;
    }

    /**
     * Register validation for a child field using JunctureField's approach
     */
    protected function registerChildFieldValidation($childRecord, $attribute, $fieldId, $fieldName)
    {
        // Get validation rules from the model
        $validationStrs = [];
        $validators = $childRecord->getActiveValidators($attribute);
        foreach ($validators as $validator) {
            $validationStrs[] = $validator->clientValidateAttribute($childRecord, $attribute, $this->getView());
        }

        // Create validation config similar to JunctureField
        $validationConfig = [
            'id' => $fieldId,
            'name' => $fieldName,
            'container' => '.field-' . $fieldId,
            'input' => '#' . $fieldId,
            'error' => '.invalid-feedback',
            'validate' => new \yii\web\JsExpression('function (attribute, value, messages, deferred, form) {' . implode("\n", $validationStrs) . '}')
        ];

        // Register the validation using JunctureField's exact approach
        $this->getView()->registerJs(
            "validateNewDynamicField(" . \yii\helpers\Json::encode(array_merge($validationConfig, ['formId' => '#' . $this->form->id])) . ");",
            \yii\web\View::POS_END
        );
    }

    /**
     * Register JavaScript and CSS assets
     */
    protected function registerAssets()
    {
        $widgetId = $this->getId();
        $rowsContainerId = $widgetId . '-rows';
        $newRowTemplate = $this->getNewRowTemplate();

        // Collect widget initialization callbacks
        $widgetCallbacks = [];
        foreach ($this->childAttributes as $attrConfig) {
            if (($attrConfig['input'] ?? null) === self::INPUT_WIDGET && isset($attrConfig['initCallback'])) {
                $widgetCallbacks[] = [
                    'attribute' => $attrConfig['attribute'],
                    'callback' => $attrConfig['initCallback']
                ];
            }
        }

        $js = <<<JS
// Copy of JunctureField's validateNewDynamicField function
function validateNewDynamicField(config)
{
    var validationConfig = {
        id: config.id,
        name: config.name,
        container: config.container,
        input: config.input,
        error: ".invalid-feedback",
        validate:  config.validate
    };
    $(config.formId).yiiActiveForm("add", validationConfig);
}

(function() {
    let widgetId = '{$widgetId}';
    let container = $('#' + widgetId + '-container');
    let rowsContainerId = '{$rowsContainerId}';

    // Insert the full-width row after the parent row on page load
    let template = $('#' + rowsContainerId + '-template').html();
    let parentRow = container.closest('tr');
    parentRow.after(template);

    let fullWidthRow = $('#' + rowsContainerId);
    let rowsContainer = fullWidthRow.find('tbody');
    let rowCounter = rowsContainer.find('tr').length;

    // Add new row
    container.on('click', '.inline-repeater-add', function(e) {
        e.preventDefault();
        let newRow = {$newRowTemplate};
        newRow = newRow.replace(/INDEX_PLACEHOLDER/g, rowCounter);
        rowsContainer.append(newRow);

        // Show the rows container if collapsed
        if (!fullWidthRow.hasClass('show')) {
            fullWidthRow.collapse('show');
        }

        // Re-initialize date pickers for the newly added row
        let lastRow = rowsContainer.find('tr:last');
        lastRow.find('.krajee-datepicker').each(function() {
            let pickerElement = $(this);
            if (pickerElement.data('kvDatepicker')) {
                pickerElement.kvDatepicker('destroy');
            }
            pickerElement.kvDatepicker({
                autoclose: true,
                format: 'yyyy-mm-dd'
            });
        });

        // Execute widget initialization callbacks if any
        {$this->renderWidgetCallbacks($widgetCallbacks)}

        rowCounter++;
        container.find('.row-count').text(rowCounter);
    });

    // Delete row
    fullWidthRow.on('click', '.inline-repeater-delete', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to delete this item?')) {
            $(this).closest('tr').remove();
            rowCounter--;
            container.find('.row-count').text(rowCounter);
        }
    });

    // Toggle collapse
    container.on('click', '.inline-repeater-toggle', function(e) {
        e.preventDefault();
        fullWidthRow.collapse('toggle');
    });
})();
JS;

        $this->getView()->registerJs($js, View::POS_READY);
    }

    /**
     * Render widget initialization callbacks as JavaScript
     * @param array $widgetCallbacks Array of callback configurations
     * @return string JavaScript code to execute callbacks
     */
    protected function renderWidgetCallbacks($widgetCallbacks)
    {
        if (empty($widgetCallbacks)) {
            return '';
        }

        $callbackJs = '';
        foreach ($widgetCallbacks as $callback) {
            $attribute = $callback['attribute'];
            $callbackExpression = $callback['callback'];

            // Convert JsExpression to string if needed
            $callbackStr = ($callbackExpression instanceof \yii\web\JsExpression)
                ? $callbackExpression->expression
                : (string)$callbackExpression;

            // Generate code to find the widget element in the last row and execute callback
            // Inline the widgetElement variable directly into the callback expression
            $callbackJs .= "
        // Initialize {$attribute} widget
        (function() {
            let widgetElement = lastRow.find('[id*=\"-{$attribute}-\"]').first();
            if (widgetElement.length) {
                {$callbackStr}
            }
        })();
";
        }

        return $callbackJs;
    }

    /**
     * Generate the template for a new row
     * @return string JSON-encoded HTML template
     */
    protected function getNewRowTemplate()
    {
        $childModel = new $this->childModelClass();
        $childFormName = strtolower($childModel->formName());

        $rowHtml = '<tr data-index="INDEX_PLACEHOLDER">';

        foreach ($this->childAttributes as $attrConfig) {
            $attribute = $attrConfig['attribute'];
            $inputType = $attrConfig['input'] ?? self::INPUT_TEXT;

            $fieldName = $this->namePrefix . '[' . $this->childAttributeName . '][INDEX_PLACEHOLDER][' . $attribute . ']';
            $fieldId = $this->getId() . '-' . $attribute . '-INDEX_PLACEHOLDER';

            // Check if this field is required
            $isRequired = false;
            $childModel = new $this->childModelClass();
            foreach ($childModel->rules() as $rule) {
                if (is_array($rule) && isset($rule[0]) && isset($rule[1])) {
                    $attributes = is_array($rule[0]) ? $rule[0] : [$rule[0]];
                    $validator = $rule[1];
                    if (in_array($attribute, $attributes) && $validator === 'required') {
                        $isRequired = true;
                        break;
                    }
                }
            }

            $inputOptions = ArrayHelper::merge([
                'id' => $fieldId,
                'name' => $fieldName,
                'class' => 'form-control form-control-sm'
            ], $attrConfig['inputOptions'] ?? []);

            // Add required attribute if field is required
            if ($isRequired) {
                $inputOptions['required'] = true;
            }

            $rowHtml .= '<td>';

            // Add hidden parent FK fields
            foreach ($this->parentFkColumns as $fkColumn) {
                $fkFieldName = $this->namePrefix . '[' . $this->childAttributeName . '][INDEX_PLACEHOLDER][' . $fkColumn . ']';
                $rowHtml .= Html::hiddenInput($fkFieldName, '', [
                    'class' => 'parent-fk-field',
                    'data-fk-column' => $fkColumn
                ]);
            }

            // Wrap field in proper container for validation
            $rowHtml .= '<div class="field-' . $fieldId . '">';

            switch ($inputType) {
                case self::INPUT_TEXTAREA:
                    $rowHtml .= Html::textarea($fieldName, '', $inputOptions);
                    break;

                case self::INPUT_DROPDOWN:
                    $data = $attrConfig['data'] ?? [];
                    $rowHtml .= Html::dropDownList($fieldName, null, $data, $inputOptions);
                    break;

                case self::INPUT_DATEPICKER:
                    $rowHtml .= '<input type="text" class="form-control form-control-sm krajee-datepicker" id="' . $fieldId . '" name="' . $fieldName . '">';
                    break;

                case self::INPUT_SELECT2:
                    $data = $attrConfig['data'] ?? [];
                    $rowHtml .= Html::dropDownList($fieldName, null, $data, $inputOptions);
                    break;

                case self::INPUT_WIDGET:
                    // For widgets, use ActiveField like JunctureField's getNewInput() method
                    if (!isset($attrConfig['widgetClass'])) {
                        throw new InvalidConfigException('The "widgetClass" property must be set when using INPUT_WIDGET type.');
                    }

                    // Create a temporary model instance for rendering
                    $tempModel = new $this->childModelClass();

                    $activeFieldOptions = [
                        'template' => '{input}{error}',
                        'enableClientValidation' => false
                    ];

                    $activeField = $this->form->field($tempModel, $attribute, $activeFieldOptions);

                    $widgetOptions = $attrConfig['widgetOptions'] ?? [];
                    $widgetOptions['options'] = $inputOptions;

                    // Render the widget through ActiveField and get the HTML
                    $widgetHtml = $activeField->widget($attrConfig['widgetClass'], $widgetOptions)->render();

                    // Replace the model's field ID with our dynamic field ID
                    $tempFieldId = Html::getInputId($tempModel, $attribute);
                    $widgetHtml = str_replace($tempFieldId, $fieldId, $widgetHtml);

                    $rowHtml .= $widgetHtml;
                    break;

                default:
                case self::INPUT_TEXT:
                    $rowHtml .= Html::textInput($fieldName, '', $inputOptions);
                    break;
            }

            $rowHtml .= '</div></td>';
        }

        $rowHtml .= '<td><button type="button" class="btn btn-sm btn-danger inline-repeater-delete"><i class="fas fa-trash"></i></button></td>';
        $rowHtml .= '</tr>';

        return Json::encode($rowHtml);
    }
}
