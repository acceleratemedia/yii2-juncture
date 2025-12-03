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
                                    <?php
                                    $separated = $this->separateAttributes();
                                    foreach ($separated['main'] as $attrConfig) : ?>
                                        <th>
                                            <span <?= $childModel->getAttributeHint($attrConfig['attribute']) ? 'data-title="' . Html::encode($childModel->getAttributeHint($attrConfig['attribute'])) . '"' : '' ?>>
                                                <?= isset($attrConfig['label']) ? $attrConfig['label'] : $childModel->getAttributeLabel($attrConfig['attribute']) ?>
                                            </span>
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
     * Separates child attributes into main and expandable groups
     * @return array ['main' => [...], 'expandable' => [...]]
     */
    protected function separateAttributes()
    {
        $main = [];
        $expandable = [];

        foreach ($this->childAttributes as $attrConfig) {
            if (!empty($attrConfig['expandable'])) {
                $expandable[] = $attrConfig;
            } else {
                $main[] = $attrConfig;
            }
        }

        return ['main' => $main, 'expandable' => $expandable];
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
        $expandableRowId = $rowId . '-expandable';
        $separated = $this->separateAttributes();
        $hasExpandable = !empty($separated['expandable']);

        ob_start();
    ?>
        <tr id="<?= $rowId ?>" data-index="<?= $index ?>">
            <?php foreach ($separated['main'] as $attrConfig) : ?>
                <td>
                    <?= $this->renderChildField($childRecord, $attrConfig, $index, $childFormName) ?>
                </td>
            <?php endforeach; ?>
            <td>
                <?php if ($hasExpandable) : ?>
                    <button type="button" class="btn btn-sm btn-link inline-repeater-expand-toggle p-0"
                        data-target="<?= $expandableRowId ?>"
                        title="Toggle additional fields">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-danger inline-repeater-delete" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php if ($hasExpandable) : ?>
            <tr id="<?= $expandableRowId ?>" class="collapse" data-index="<?= $index ?>" data-parent-row="<?= $rowId ?>">
                <td colspan="<?= count($separated['main']) + 1 ?>">
                    <div class="row">
                        <?php foreach ($separated['expandable'] as $attrConfig) : ?>
                            <div class="col-md-6 mb-2">
                                <label class="form-label small" <?= $childRecord->getAttributeHint($attrConfig['attribute']) ? 'data-title="' . Html::encode($childRecord->getAttributeHint($attrConfig['attribute'])) . '"' : '' ?>>
                                    <strong>
                                        <?php
                                        $childModel = new $this->childModelClass();
                                        echo isset($attrConfig['label']) ? $attrConfig['label'] : $childModel->getAttributeLabel($attrConfig['attribute']);
                                        ?>
                                    </strong>
                                </label>
                                <?= $this->renderChildField($childRecord, $attrConfig, $index, $childFormName) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
        <?php endif; ?>
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

        // Collect Select2 configurations for initialization
        $select2Configs = [];
        foreach ($this->childAttributes as $attrConfig) {
            if (($attrConfig['input'] ?? null) === self::INPUT_SELECT2) {
                $select2Configs[] = [
                    'attribute' => $attrConfig['attribute'],
                    'data' => $attrConfig['data'] ?? [],
                    'inputOptions' => $attrConfig['inputOptions'] ?? []
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
    // Count only main rows (exclude expandable rows which have data-parent-row attribute)
    let rowCounter = rowsContainer.find('tr:not([data-parent-row])').length;

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

            // Re-initialize date pickers for the newly added row(s)
            let lastMainRow = rowsContainer.find('tr[data-index="' + rowCounter + '"]:first');
            let lastExpandableRow = rowsContainer.find('tr[data-index="' + rowCounter + '"]:last');

            // Initialize date pickers in main row
            lastMainRow.find('.krajee-datepicker').each(function() {
                let pickerElement = $(this);
                if (pickerElement.data('kvDatepicker')) {
                    pickerElement.kvDatepicker('destroy');
                }
                pickerElement.kvDatepicker({
                    autoclose: true,
                    format: 'yyyy-mm-dd'
                });
            });

            // Initialize date pickers in expandable row if it exists
            if (lastExpandableRow.length && lastExpandableRow.hasClass('collapse')) {
                lastExpandableRow.find('.krajee-datepicker').each(function() {
                    let pickerElement = $(this);
                    if (pickerElement.data('kvDatepicker')) {
                        pickerElement.kvDatepicker('destroy');
                    }
                    pickerElement.kvDatepicker({
                        autoclose: true,
                        format: 'yyyy-mm-dd'
                    });
                });
            }

            // Initialize Select2 fields in the newly added row
            {$this->renderSelect2Initialization($select2Configs)}

            // Execute widget initialization callbacks if any
            {$this->renderWidgetCallbacks($widgetCallbacks)}

            rowCounter++;
            container.find('.row-count').text(rowCounter);
        });

    // Delete row (including expandable row if it exists)
    fullWidthRow.on('click', '.inline-repeater-delete', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to delete this item?')) {
            let mainRow = $(this).closest('tr');
            let expandableRow = mainRow.next('tr[data-parent-row="' + mainRow.attr('id') + '"]');
            mainRow.remove();
            if (expandableRow.length) {
                expandableRow.remove();
            }
            // Recalculate row count based on actual main rows
            rowCounter = rowsContainer.find('tr:not([data-parent-row])').length;
            container.find('.row-count').text(rowCounter);
        }
    });

    // Toggle expandable row for individual rows
    fullWidthRow.on('click', '.inline-repeater-expand-toggle', function(e) {
        e.preventDefault();
        let targetId = $(this).data('target');
        let expandableRow = $('#' + targetId);
        let icon = $(this).find('i');
        let isCurrentlyShown = expandableRow.hasClass('show');

        expandableRow.collapse('toggle');

        // Update icon based on current state (will be toggled after collapse)
        if (isCurrentlyShown) {
            icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
        } else {
            icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
        }

        // Also handle Bootstrap collapse events for consistency
        expandableRow.off('shown.bs.collapse hidden.bs.collapse');
        expandableRow.on('shown.bs.collapse', function() {
            icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
        });
        expandableRow.on('hidden.bs.collapse', function() {
            icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
        });
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
     * Render Select2 initialization code as JavaScript
     * @param array $select2Configs Array of Select2 configurations
     * @return string JavaScript code to initialize Select2
     */
    protected function renderSelect2Initialization($select2Configs)
    {
        if (empty($select2Configs)) {
            return '';
        }

        $initJs = '';
        foreach ($select2Configs as $config) {
            $attribute = $config['attribute'];
            $inputOptions = $config['inputOptions'];

            // Build Select2 plugin options from inputOptions
            $pluginOptions = [];
            if (isset($inputOptions['placeholder'])) {
                $pluginOptions['placeholder'] = $inputOptions['placeholder'];
            }
            if (isset($inputOptions['tags']) && $inputOptions['tags']) {
                $pluginOptions['tags'] = true;
            }
            // multiple is handled as HTML attribute, but Select2 also respects it
            $multiple = isset($inputOptions['multiple']) && $inputOptions['multiple'];

            $pluginOptionsJson = Json::encode($pluginOptions);

            $initJs .= "
            // Initialize Select2 for {$attribute}
            (function() {
                let selectElement = lastMainRow.find('select[id*=\"-{$attribute}-\"]').first();
                if (selectElement.length) {
                    // Destroy existing Select2 instance if any (shouldn't happen, but just in case)
                    if (selectElement.data('select2')) {
                        selectElement.select2('destroy');
                    }
                    // Initialize Select2 with options
                    selectElement.select2({$pluginOptionsJson});
                }

                // Also check expandable row if it exists
                if (lastExpandableRow.length) {
                    let expandableSelect = lastExpandableRow.find('select[id*=\"-{$attribute}-\"]').first();
                    if (expandableSelect.length) {
                        if (expandableSelect.data('select2')) {
                            expandableSelect.select2('destroy');
                        }
                        expandableSelect.select2({$pluginOptionsJson});
                    }
                }
            })();
";
        }

        return $initJs;
    }

    /**
     * Generate the template for a new row
     * @return string JSON-encoded HTML template
     */
    protected function getNewRowTemplate()
    {
        $childModel = new $this->childModelClass();
        $childFormName = strtolower($childModel->formName());
        $separated = $this->separateAttributes();
        $hasExpandable = !empty($separated['expandable']);
        $rowId = $this->getId() . '-row-INDEX_PLACEHOLDER';
        $expandableRowId = $rowId . '-expandable';

        $rowHtml = '<tr id="' . $rowId . '" data-index="INDEX_PLACEHOLDER">';

        // Render main row fields
        foreach ($separated['main'] as $attrConfig) {
            $rowHtml .= $this->renderFieldTemplate($attrConfig, 'INDEX_PLACEHOLDER');
        }

        // Actions column
        $rowHtml .= '<td>';
        if ($hasExpandable) {
            $rowHtml .= '<button type="button" class="btn btn-sm btn-link inline-repeater-expand-toggle p-0" data-target="' . $expandableRowId . '" title="Toggle additional fields"><i class="fas fa-chevron-down"></i></button>';
        }
        $rowHtml .= '<button type="button" class="btn btn-sm btn-danger inline-repeater-delete"><i class="fas fa-trash"></i></button>';
        $rowHtml .= '</td>';
        $rowHtml .= '</tr>';

        // Render expandable row if needed
        if ($hasExpandable) {
            $rowHtml .= '<tr id="' . $expandableRowId . '" class="collapse" data-index="INDEX_PLACEHOLDER" data-parent-row="' . $rowId . '">';
            $rowHtml .= '<td colspan="' . (count($separated['main']) + 1) . '">';
            $rowHtml .= '<div class="row">';

            foreach ($separated['expandable'] as $attrConfig) {
                $hint = $childModel->getAttributeHint($attrConfig['attribute']);
                $dataTitle = $hint ? ' data-title="' . Html::encode($hint) . '"' : '';

                $rowHtml .= '<div class="col-md-6 mb-2">';
                $rowHtml .= '<label class="form-label small"' . $dataTitle . '>';
                $rowHtml .= '<strong>';
                $rowHtml .= isset($attrConfig['label']) ? Html::encode($attrConfig['label']) : Html::encode($childModel->getAttributeLabel($attrConfig['attribute']));
                $rowHtml .= '</strong>';
                $rowHtml .= '</label>';
                $rowHtml .= $this->renderFieldTemplate($attrConfig, 'INDEX_PLACEHOLDER', true);
                $rowHtml .= '</div>';
            }

            $rowHtml .= '</div>';
            $rowHtml .= '</td>';
            $rowHtml .= '</tr>';
        }

        return Json::encode($rowHtml);
    }

    /**
     * Renders a field template for new rows
     * @param array $attrConfig
     * @param string $indexPlaceholder
     * @param bool $skipTd Whether to skip the <td> wrapper (for expandable fields)
     * @return string
     */
    protected function renderFieldTemplate($attrConfig, $indexPlaceholder, $skipTd = false)
    {
        $attribute = $attrConfig['attribute'];
        $inputType = $attrConfig['input'] ?? self::INPUT_TEXT;

        $fieldName = $this->namePrefix . '[' . $this->childAttributeName . '][' . $indexPlaceholder . '][' . $attribute . ']';
        $fieldId = $this->getId() . '-' . $attribute . '-' . $indexPlaceholder;

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

        $fieldHtml = '';

        if (!$skipTd) {
            $fieldHtml .= '<td>';
        }

        // Add hidden parent FK fields
        foreach ($this->parentFkColumns as $fkColumn) {
            $fkFieldName = $this->namePrefix . '[' . $this->childAttributeName . '][' . $indexPlaceholder . '][' . $fkColumn . ']';
            $fieldHtml .= Html::hiddenInput($fkFieldName, '', [
                'class' => 'parent-fk-field',
                'data-fk-column' => $fkColumn
            ]);
        }

        // Wrap field in proper container for validation
        $fieldHtml .= '<div class="field-' . $fieldId . '">';

        switch ($inputType) {
            case self::INPUT_TEXTAREA:
                $fieldHtml .= Html::textarea($fieldName, '', $inputOptions);
                break;

            case self::INPUT_DROPDOWN:
                $data = $attrConfig['data'] ?? [];
                $fieldHtml .= Html::dropDownList($fieldName, null, $data, $inputOptions);
                break;

            case self::INPUT_DATEPICKER:
                $fieldHtml .= '<input type="text" class="form-control form-control-sm krajee-datepicker" id="' . $fieldId . '" name="' . $fieldName . '">';
                break;

            case self::INPUT_SELECT2:
                // Render a plain select element that will be initialized as Select2 via JavaScript
                // This avoids issues with Select2 widget trying to auto-initialize on non-existent elements
                $data = $attrConfig['data'] ?? [];
                $fieldHtml .= Html::dropDownList($fieldName, null, $data, $inputOptions);
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

                $fieldHtml .= $widgetHtml;
                break;

            default:
            case self::INPUT_TEXT:
                $fieldHtml .= Html::textInput($fieldName, '', $inputOptions);
                break;
        }

        $fieldHtml .= '</div>';

        if (!$skipTd) {
            $fieldHtml .= '</td>';
        }

        return $fieldHtml;
    }
}
