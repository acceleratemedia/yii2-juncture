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
        <!-- This goes in the table cell -->
        <div id="<?= $containerId ?>" class="<?= $this->containerClass ?>" data-widget-id="<?= $this->getId() ?>">
            <button type="button" class="btn btn-sm btn-secondary inline-repeater-add mb-2" data-target="<?= $rowsContainerId ?>">
                <?= $this->addButtonLabel ?>
            </button>

            <?php if ($this->collapsed) : ?>
                <a href="#" class="inline-repeater-toggle ml-2" data-target="<?= $rowsContainerId ?>">
                    Collapse / Expand
                </a>
            <?php endif; ?>
        </div>

        <!-- This will be inserted as a new full-width row -->
        <script type="text/template" id="<?= $rowsContainerId ?>-template">
            <tr class="inline-repeater-full-row <?= $this->collapsed ? 'collapse' : '' ?>" id="<?= $rowsContainerId ?>">
            <td colspan="100%">
                <div class="inline-repeater-rows">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <?php foreach ($this->childAttributes as $attrConfig) : ?>
                                    <th><?= $childModel->getAttributeLabel($attrConfig['attribute']) ?></th>
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
    </script>
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

        switch ($inputType) {
            case self::INPUT_TEXTAREA:
                $fieldHtml .= Html::textarea($fieldName, $childRecord->{$attribute}, $inputOptions);
                break;

            case self::INPUT_DROPDOWN:
                $data = $attrConfig['data'] ?? [];
                $fieldHtml .= Html::dropDownList($fieldName, $childRecord->{$attribute}, $data, $inputOptions);
                break;

            case self::INPUT_DATEPICKER:
                $fieldHtml .= $this->form->field($childRecord, $attribute, [
                    'template' => '{input}',
                    'options' => ['tag' => false]
                ])->widget(DatePicker::class, [
                    'options' => $inputOptions,
                    'pluginOptions' => [
                        'autoclose' => true,
                        'format' => 'yyyy-mm-dd'
                    ]
                ]);
                break;

            case self::INPUT_SELECT2:
                $data = $attrConfig['data'] ?? [];
                $fieldHtml .= $this->form->field($childRecord, $attribute, [
                    'template' => '{input}',
                    'options' => ['tag' => false]
                ])->widget(Select2::class, [
                    'data' => $data,
                    'options' => $inputOptions
                ]);
                break;

            default:
            case self::INPUT_TEXT:
                $fieldHtml .= Html::textInput($fieldName, $childRecord->{$attribute}, $inputOptions);
                break;
        }

        return $fieldHtml;
    }

    /**
     * Register JavaScript and CSS assets
     */
    protected function registerAssets()
    {
        $widgetId = $this->getId();
        $rowsContainerId = $widgetId . '-rows';
        $newRowTemplate = $this->getNewRowTemplate();

        $js = <<<JS
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

        rowCounter++;
    });

    // Delete row
    container.on('click', '.inline-repeater-delete', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to delete this item?')) {
            $(this).closest('tr').remove();
        }
    });

    // Toggle collapse
    container.on('click', '.inline-repeater-toggle', function(e) {
        e.preventDefault();
        fullWidthRow.collapse('toggle');
    });
})();
JS;

        $this->getView()->registerJs($js, View::POS_LOAD); // POS_READY didn't work for some reason, and since we're using jQuery we can't use POS_END either.
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

            $inputOptions = ArrayHelper::merge([
                'id' => $fieldId,
                'name' => $fieldName,
                'class' => 'form-control form-control-sm'
            ], $attrConfig['inputOptions'] ?? []);

            $rowHtml .= '<td>';

            // Add hidden parent FK fields
            foreach ($this->parentFkColumns as $fkColumn) {
                $fkFieldName = $this->namePrefix . '[' . $this->childAttributeName . '][INDEX_PLACEHOLDER][' . $fkColumn . ']';
                $rowHtml .= Html::hiddenInput($fkFieldName, '', [
                    'class' => 'parent-fk-field',
                    'data-fk-column' => $fkColumn
                ]);
            }

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

                default:
                case self::INPUT_TEXT:
                    $rowHtml .= Html::textInput($fieldName, '', $inputOptions);
                    break;
            }

            $rowHtml .= '</td>';
        }

        $rowHtml .= '<td><button type="button" class="btn btn-sm btn-danger inline-repeater-delete"><i class="fas fa-trash"></i></button></td>';
        $rowHtml .= '</tr>';

        return Json::encode($rowHtml);
    }
}
