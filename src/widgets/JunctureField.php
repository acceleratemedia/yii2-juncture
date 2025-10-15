<?php

namespace bvb\juncture\widgets;

use bvb\juncture\behaviors\SaveJunctureRelationships;
use kartik\date\DatePicker;
use kartik\select2\Select2;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Inflector;
use yii\helpers\Json;
use yii\validators\Validator;
use yii\web\JsExpression;
use yii\web\View;
use yii\bootstrap4\InputWidget;
use Yii;

/**
 * Widget uses the Select2 plugin to implement a UI for juncture relationships
 */
class JunctureField extends InputWidget
{
    /**
     * Constant to identify we want to render a text input
     * @var string
     */
    const INPUT_TEXT = 'textInput';

    /**
     * Constant to identify we want to render a select field. Using this also requires a `dropdownOptions`
     * @var string
     */
    const INPUT_DROPDOWN = 'dropdownList';

    /**
     * Constant to identify we want to render a text area
     * @var string
     */
    const INPUT_TEXTAREA = 'textArea';

    /**
     * Constant to identify we want to a datepicker field
     * @var string
     */
    const INPUT_DATEPICKER = 'datepicker';

    /**
     * Constant to identify we want to render a select2 widget
     * @var string
     */
    const INPUT_SELECT2 = 'select2';

    /**
     * Constant to identify we want to render a custom widget
     * @var string
     */
    const INPUT_WIDGET = 'widget';

    /**
     * @var \yii\widgets\ActiveForm
     */
    public $form;

    /**
     * @var string
     */
    public $relationNameInJunctureModel;

    /**
     * Name of the attribute on the juncture model used to label which record
     * we are creating a juncture relation for
     * @var string
     */
    public $junctureRelationDisplayAttribute = 'name';

    /**
     * @var string
     */
    public $ownerPkColumnInJunctureTable;

    /**
     * @var string
     */
    public $relatedPksColumnInJunctureTable;

    /**
     * Name of the property on the model that holds the additional juncture data
     * Utilized for massive assignment of juncture attribute values on the parent model for procesing using the behavior
     * @var string
     */
    public $additionalJunctureDataProp;

    /**
     * List of items to be rendered in a dropdownlist
     * @var array
     */
    public $dropdownOptions;

    /**
     * @var\yii\db\ActiveRecord
     */
    public $junctureModel;

    /**
     * Additional attribtues on the juncture model we want rendered in the widget
     * ```
     *   'junctureAttributes' => [
     *       [
     *           'attribute' => 'rank',
     *           'input' => JunctureField::INPUT_TEXT,
     *           'inputOptions' => [
     *               'readOnly' => true
     *           ]
     *       ],
     *       [
     *           'attribute' => 'write_up',
     *           'input' => JunctureField::INPUT_TEXTAREA,
     *       ],
     *       [
     *          'attribute' => 'custom_field',
     *          'input' => JunctureField::INPUT_WIDGET,
     *          'widgetClass' => CustomWidget::class,
     *          'widgetOptions' => [
     *              'option1' => 'value1',
     *              'option2' => 'value2'
     *          ],
     *          'initCallback' => 'function(){ console.log("Init custom widget"); }'
     *       ],
     *   ],
     * ```
     * @var array
     */
    public $junctureAttributes;

    /**
     * Tge default type of input to be used to render additional attributes
     * @var string
     */
    public $defaultInput = self::INPUT_TEXT;

    /**
     * A callback to be executed when a new juncture item is added
     * @var string
     */
    public $newItemCallback;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        $junctureBehaviorAttached = false;
        if ($this->model->behaviors !== null) { // --- To avoid error
            foreach ($this->model->behaviors as $behavior) { // --- Loop to see if SaveJunctureRelationships is attached
                if ($behavior::className() == SaveJunctureRelationships::class) { // --- keep Classname() to avoid compile error
                    $junctureBehaviorAttached = true;
                    foreach ($behavior->relationships as $relationshipData) { // --- Loop relationships until we find one for this widget
                        if ($relationshipData['relatedPksAttribute'] == $this->attribute) {
                            // --- Set some defaults based on the behavior if they are not specificied in the instantiation of this widget
                            if ($this->ownerPkColumnInJunctureTable === null) {
                                $this->ownerPkColumnInJunctureTable = $relationshipData['ownerPkColumnInJunctureTable'];
                            }

                            if ($this->relatedPksColumnInJunctureTable === null) {
                                $this->relatedPksColumnInJunctureTable = $relationshipData['relatedPksColumnInJunctureTable'];
                            }

                            if ($this->additionalJunctureDataProp === null) {
                                $this->additionalJunctureDataProp = $relationshipData['additionalJunctureDataProp'];
                            }

                            if ($this->junctureModel === null) {
                                $this->junctureModel = new $relationshipData['junctureModel'];
                            }

                            if ($this->junctureAttributes === null) {
                                foreach ($relationshipData['additionalJunctureAttributes'] as $attributeName) {
                                    // --- Default configuration is to use all juncture attributes as a text input
                                    $this->junctureAttributes[] = [
                                        'attribute' => $attributeName,
                                        'input' => $this->defaultInput
                                    ];
                                }
                            }

                            if ($this->relationNameInJunctureModel === null) {
                                $this->relationNameInJunctureModel = lcfirst((new \ReflectionClass($relationshipData['relatedModel']))->getShortName());
                            }
                        }
                    }
                }
            }
        }

        if (!$junctureBehaviorAttached) {
            throw new InvalidConfigException('The behavior ' . SaveJunctureRelationships::className() . ' must be attached to ' . $this->model->className() . ' for the juncture input widget to work');
        }
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        $this->registerJunctureUiJs();

        return $this->render('juncture-field', [
            'form' => $this->form,
            'model' => $this->model,
            'options' => $this->options,
            'relatedPksAttribute' => $this->attribute,
            'relationNameInJunctureModel' => $this->relationNameInJunctureModel,
            'junctureRelationDisplayAttribute' => $this->junctureRelationDisplayAttribute,
            'ownerPkColumnInJunctureTable' => $this->ownerPkColumnInJunctureTable,
            'relatedPksColumnInJunctureTable' => $this->relatedPksColumnInJunctureTable,
            'additionalJunctureDataProp' => $this->additionalJunctureDataProp,
            'dropdownOptions' => $this->dropdownOptions,
            'junctureModel' => $this->junctureModel,
            'junctureAttributes' => $this->junctureAttributes
        ]);
    }

    /**
     * @return void
     */
    private function registerJunctureUiJs()
    {
        // --- Loop through all juncture attributes to get fields configuration data
        $fieldsConfigData = []; // --- Holds the special configuration for each new field being added
        $callbacks = []; // --- Holds a callback for each field requires one

        // --- If we have an overall callback for after adding a new row then run it
        if (!empty($this->newItemCallback)) {
            $callbacks[] = $this->newItemCallback;
        }

        foreach ($this->junctureAttributes as $junctureAttributeData) {
            // --- Loop through validators on this attribute so we can create js validation for each attribute
            $validationStrs = [];
            $validators = $this->junctureModel->getActiveValidators($junctureAttributeData['attribute']);
            foreach ($validators as $validator) {
                $validationStrs[] = $validator->clientValidateAttribute($this->junctureModel, $junctureAttributeData['attribute'], $this->getView());
            }

            // --- Set up the config for this field which will be used in the javascript
            $fieldConfigData = [
                'attribute' => $junctureAttributeData['attribute'],
                'newInput' => (!isset($junctureAttributeData['newInput']) || empty($junctureAttributeData['newInput'])) ? $this->getNewInput($junctureAttributeData) : $junctureAttributeData['newInput'],
                'validator' => new JsExpression('function (attribute, value, messages, deferred, form) {' . implode("\n", $validationStrs) . '}'),
                'multiple' => false
            ];

            // --- If the juncture attribute has an input that requires a callback to initialize, set it
            if ($junctureAttributeData['input'] == self::INPUT_DATEPICKER) {
                // --- If there is a datepicker destroy instances of it and re-initialize so the new input has it working
                // --- Not sure if doing this by the input type is the best decision but for now it seems that way
                $callbacks[self::INPUT_DATEPICKER] = new JsExpression('$(".krajee-datepicker").kvDatepicker("destroy");$(".krajee-datepicker").kvDatepicker({"autoclose":true,"format":"yyyy-mm-dd"});');
            }

            if ($junctureAttributeData['input'] == self::INPUT_SELECT2) {
                $junctureIdentifierShortname = strtolower($this->junctureModel->formName());
                // --- If there is a select2 make sure to properly initialize this new one
                // --- This was tricky because of the way the plugin does it. I have to try
                // --- to pull the variable names it creates out of the new field string
                preg_match('/"s2options_(.+?)\\"/', $fieldConfigData['newInput'], $matches);
                $s2OptionsStr = $matches[1];
                preg_match('/"select2_(.+?)\\"/', $fieldConfigData['newInput'], $matches);
                $s2ConfigStr = $matches[1];

                // --- Then, copying the plugin code that initializes the new field
                // --- and using a selector targeting it from the last row of the table
                // --- we will run that code on the newly created instance
                $genericInputId = Html::getInputId($this->junctureModel, $junctureAttributeData['attribute']);
                $selector = '#' . $junctureIdentifierShortname . '-table tr:last select[id*=' . strtolower($junctureAttributeData['attribute']) . ']';
                $js = <<<JAVASCRIPT
jQuery.when(jQuery("{$selector}").select2(select2_{$s2ConfigStr}))
    .done(function(e){
        initS2Loading("{$genericInputId}","s2options_{$s2OptionsStr}");
        initS2ToggleAll(jQuery("{$selector}").attr("id"));
    });
JAVASCRIPT;
                $callbacks[self::INPUT_SELECT2] = new JsExpression($js);
                $fieldConfigData['multiple'] = true;
            }

            // --- Handle custom widget callbacks
            if ($junctureAttributeData['input'] == self::INPUT_WIDGET && isset($junctureAttributeData['initCallback'])) {
                $callbackKey = self::INPUT_WIDGET . '_' . $junctureAttributeData['attribute'];
                $callbacks[$callbackKey] = new JsExpression($junctureAttributeData['initCallback']);
            }

            $fieldsConfigData[] = $fieldConfigData;
        }

        // --- Prepare some fields we can use in the javascript
        $fieldId = Html::getInputId($this->model, $this->attribute);
        $junctureIdentifierShortname = strtolower($this->junctureModel->formName());

        // --- Set up a callback function each time a new record is added consisting of all of the
        $callback = (!empty($callbacks)) ? 'function(){' . implode('', $callbacks) . '}' : null;

        // --- The javascript going into document.ready is specific to this instance
        if (is_array($this->ownerPkColumnInJunctureTable)) {
            $ownerPks = [];
            foreach ($this->model->primaryKey() as $attributeName) {
                $ownerPks[] = $this->model->{$attributeName};
            }
            $modelIdentifier = implode('-', $ownerPks);
        } else {
            $modelIdentifier = $this->model->{$this->model->primaryKey()[0]};
        }

        // --- Set up the configuraiton used when adding a new field
        $newJunctureDataConfig = [
            'modelFormName' => $this->model->formName(),
            'formId' => '#' . $this->form->id,
            'additionalJunctureDataProp' => $this->additionalJunctureDataProp,
            'relatedPksColumnInJunctureTable' => $this->relatedPksColumnInJunctureTable,
            'junctureIdentifierShortname' => $junctureIdentifierShortname,
            'modelId' => $modelIdentifier,
            'ownerPkColumnInJunctureTable' => $this->ownerPkColumnInJunctureTable,
            'selectedData' => new JsExpression('e.params.data'), // --- 'e' refers to the event of the select2 plugin
            'attributeConfigData' => $fieldsConfigData,
            'callback' => ($callback) ? new JsExpression($callback) : null
        ];

        $newJunctureDataConfigJson = Json::encode($newJunctureDataConfig);

        $rowId = (is_array($this->ownerPkColumnInJunctureTable)) ? implode('-', $this->ownerPkColumnInJunctureTable) : $this->ownerPkColumnInJunctureTable;
        $ready_js = <<<JAVASCRIPT
$("[data-toggle=tooltip]").tooltip({placement: "auto"});
$("#{$fieldId}").on("select2:select", function(e){
    addNewJunctureData({$newJunctureDataConfigJson})
});

$("#{$fieldId}").on("select2:unselect", function(e){
    var data = e.params.data;
    $("#{$junctureIdentifierShortname}-table tbody tr#{$rowId}-{$modelIdentifier}-{$this->relatedPksColumnInJunctureTable}-"+data.id).remove();
});
JAVASCRIPT;
        $this->getView()->registerJs($ready_js);

        // --- This javascript is global to this ui functionality
        $js = <<<JAVASCRIPT
function validateNewDynamicField(config)
{
    var validationConfig = {
        id: config.id,
        name: config.name,
        container: config.container,
        input: config.input,
        error: ".invalid-feedback",
        validate:  config.validator
    };
    $(config.formId).yiiActiveForm("add", validationConfig);
}

function addNewJunctureData(config)
{
    // --- Get the data from the select element
    var data = config.selectedData;

    // --- Create a hidden input with the id of the juncture related model
    var hiddenInput = $("<input>").attr({
        type: "hidden",
        name: config.modelFormName+"["+config.additionalJunctureDataProp+"]["+data.id+"]["+config.relatedPksColumnInJunctureTable+"]",
        id: config.junctureIdentifierShortname+"-"+config.relatedPksColumnInJunctureTable+"-"+data.id,
        value: data.id
    });

    // --- Create a label cell with the id field and the label
    var labelCell = $("<td></td>")
        .append(hiddenInput)
        .append("<span class=\"display-attribute\">"+data.text+"</span>");

    // --- Create a new row with the label cell
    var newRow = $("<tr></tr>")
        .attr("id", config.ownerPkColumnInJunctureTable+"-"+config.modelId+"-"+config.relatedPksColumnInJunctureTable+"-"+data.id)
        .append(labelCell);

    // --- Append all of the juncture fields that need input to the row
    $.each(config.attributeConfigData, function(idx, attributeConfigData){
        var newInput = $(attributeConfigData.newInput);

        // --- Update the "field" class used by Yii to add the ID to the end and update the
        // --- class of the container of the new input to specify this
        var newInput_container_classes = newInput.attr("class").split(/\s+/);
        var newClasses = [];
        $.each(newInput_container_classes, function(index, item){
            if (item === "field-"+config.junctureIdentifierShortname+"-"+attributeConfigData.attribute.toLowerCase()) {
                item += "-"+data.id;
            }
            newClasses.push(item)
        });
        $(newInput).attr("class", newClasses.join(" "));

        // --- Update the name of the new input to include the id of the juncture relation
        var newInputId = config.junctureIdentifierShortname+"-"+attributeConfigData.attribute.toLowerCase()+"-"+data.id;;
        var newInputName = config.modelFormName+"["+config.additionalJunctureDataProp+"]["+data.id+"]["+attributeConfigData.attribute+"]";
        if(attributeConfigData.multiple){
            newInputName += "[]";
        }

        $("select, input[type!=hidden], textarea", newInput)
            .attr("name", newInputName)
            .attr("id", newInputId);
        var newRowCell = $("<td></td>")
            .append(newInput);
        newRow.append(newRowCell);

        // --- Add form validation for the new field
        validateNewDynamicField({
            formId: config.formId,
            id: newInputId,
            name: newInputName,
            container: ".field-"+config.junctureIdentifierShortname+"-"+attributeConfigData.attribute.toLowerCase()+"-"+data.id,
            input: "#"+newInputId,
            validator: attributeConfigData.validator
        });
    });

    // --- Append the new row to the table
    $("#"+config.junctureIdentifierShortname+"-table tbody").append(newRow);

    // --- Run the callback if there is one
    if(config.callback){
        config.callback();
    }
}
JAVASCRIPT;

        $this->getView()->registerJs($js, View::POS_END, 'add-juncture-record');
    }

    /**
     * Gets a the default HTML for a new input to be used when a new juncture relation is added
     * @param array $junctureAttributeData
     * @return string
     */
    private function getNewInput($junctureAttributeData)
    {
        // --- Set up the default ActiveField instance
        $activeFieldDefaultOptions = [
            'template' => '{input}{error}',
            'enableClientValidation' => false
        ];

        // --- If there was configuraiton for the active field options passed in merge them
        $activeFieldOptions = (isset($junctureAttributeData['activeFieldOptions'])) ?
            ArrayHelper::merge($activeFieldDefaultOptions, $junctureAttributeData['activeFieldOptions']) :
            $activeFieldDefaultOptions;

        $fieldDefault = $this->form->field(
            $this->junctureModel,
            $junctureAttributeData['attribute'],
            $activeFieldOptions
        );


        // --- Some default for the field
        $fieldAttributes = [
            'id' => null // --- This will be set in the javascript that generates the new fields so leave it blank
        ];

        if (isset($junctureAttributeData['inputOptions']) && !empty($junctureAttributeData['inputOptions'])) {
            $fieldAttributes = array_merge($fieldAttributes, $junctureAttributeData['inputOptions']);
        }

        // --- Apply a default value if one is set
        if (isset($junctureAttributeData['defaultValue'])) {
            $this->junctureModel->{$junctureAttributeData['attribute']} = $junctureAttributeData['defaultValue'];
        }

        // --- Return the input based on the type
        switch ($junctureAttributeData['input']) {
            case self::INPUT_TEXTAREA:
                return $fieldDefault->textArea($fieldAttributes)->render();
            case self::INPUT_DROPDOWN:
                return $fieldDefault->dropdownList($junctureAttributeData['data'], $fieldAttributes)->render();
            case self::INPUT_DATEPICKER:
                return $fieldDefault->widget(DatePicker::class, [
                    'options' => $fieldAttributes,
                    'pluginOptions' => [
                        'autoclose' => true,
                        'format' => 'yyyy-mm-dd'
                    ]
                ])->render();
            case self::INPUT_SELECT2:
                return $fieldDefault->widget(Select2::class, [
                    'options' => $fieldAttributes,
                    'data' => $junctureAttributeData['data']
                ])->render();
            case self::INPUT_WIDGET:
                // --- Handle custom widgets
                if (!isset($junctureAttributeData['widgetClass'])) {
                    throw new InvalidConfigException('The "widgetClass" property must be set when using INPUT_WIDGET type.');
                }

                $widgetOptions = isset($junctureAttributeData['widgetOptions']) ? $junctureAttributeData['widgetOptions'] : [];
                $widgetOptions['options'] = $fieldAttributes;

                return $fieldDefault->widget($junctureAttributeData['widgetClass'], $widgetOptions)->render();
            default:
            case self::INPUT_TEXT:
                return $fieldDefault->textInput($fieldAttributes)->render();
        }
    }
}
