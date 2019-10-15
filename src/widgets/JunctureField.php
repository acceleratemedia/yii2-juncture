<?php
namespace bvb\juncture\widgets;

use bvb\juncture\behaviors\SaveJunctureRelationships;
use kartik\date\DatePicker;
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
     * @const string
     */
    const INPUT_TEXT = 'textInput';

    /**
     * Constant to identify we want to render a select field. Using this also requires a `data_list`
     * @const string
     */
    const INPUT_DROPDOWN = 'dropdownList';

    /**
     * Constant to identify we want to a datepicker field
     * @const string
     */
    const INPUT_DATEPICKER = 'datepicker';

    /**
     * Constant to identify we want to render a text area
     * @const string
     */
    const INPUT_TEXTAREA = 'textArea';

    /**
     * @var \yii\widgets\ActiveForm
     */
    public $form;

    /**
     * @var string
     */
    public $relation_name_in_juncture_model;

    /**
     * Name of the attribute on the juncture model used to label which item we are creating a juncture for
     * @var string
     */
    public $juncture_relation_display_attribute = 'name';

    /**
     * @var string
     */
    public $owner_id_attribute_in_juncture_table;

    /**
     * @var string
     */
    public $related_id_attribute_in_juncture_table;

    /**
     * Name of the property on the model that holds the additional juncture data
     * Utilized for massive assignment of juncture attribute values on the parent model for procesing using the behavior
     * @var string
     */
    public $additional_juncture_data_prop;

    /**
     * List of items to be rendered in a dropdownlist
     * @var array
     */
    public $data_list;

    /**
     * @var\yii\db\ActiveRecord
     */
    public $juncture_model;

    /**
     * Additional attribtues on the juncture model we want rendered in the widget
     * ```
     *   'juncture_attributes' => [
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
     *       ]
     *   ],
     * ```
     * @var array
     */
    public $juncture_attributes;

    /**
     * Tge default type of input to be used to render additional attributes
     * @var string
     */
    public $default_input = self::INPUT_TEXT;

    /**
     * A callback to be executed when a new juncture item is added
     * @var string
     */
    public $new_item_callback;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        $juncture_behavior_attached = false;
        if($this->model->behaviors !== null){
            // --- Just for setting up some defaults
            foreach($this->model->behaviors as $behavior){
                // --- Check to see if the behavior for saving juncture relationships is attached
                if($behavior::className() == SaveJunctureRelationships::className()){
                    $juncture_behavior_attached = true;
                    // --- Loop through the set up relationships to see if this widget is for the specified relationship
                    foreach($behavior->relationships as $relationship_data){
                        // --- Check to see if this widget is for the attribute in this set of relationship data
                        if($relationship_data['related_ids_attribute'] == $this->attribute){
                            // --- Set some defaults based on the behavior if they are not specificied in the instantiation of this widget
                            if($this->owner_id_attribute_in_juncture_table === null){
                                $this->owner_id_attribute_in_juncture_table = $relationship_data['owner_id_attribute_in_juncture_table'];
                            }

                            if($this->related_id_attribute_in_juncture_table === null){
                                $this->related_id_attribute_in_juncture_table = $relationship_data['related_id_attribute_in_juncture_table'];
                            }

                            if($this->additional_juncture_data_prop === null){
                                $this->additional_juncture_data_prop = $relationship_data['additional_juncture_data_prop'];
                            }

                            if($this->juncture_model === null){
                                $this->juncture_model = new $relationship_data['juncture_model'];
                            }

                            if($this->juncture_attributes === null){
                                foreach($relationship_data['additional_juncture_attributes'] as $attribute_name){
                                    // --- Default configuration is to use all juncture attributes as a text input
                                    $this->juncture_attributes[] = [
                                        'attribute' => $attribute_name,
                                        'input' => $this->default_input
                                    ];
                                }
                            }

                            if($this->relation_name_in_juncture_model === null){
                                $this->relation_name_in_juncture_model = lcfirst((new \ReflectionClass($relationship_data['related_model']))->getShortName());
                            }
                        }
                    }
                }
            }   
        }

        if(!$juncture_behavior_attached){
            throw new InvalidConfigException('The behavior '.SaveJunctureRelationships::className().' must be attached to '.$this->model->className().' for the juncture input widget to work');
        }
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        $this->registerJunctureUiJs();

        return $this->render('juncture_field', [
            'form' => $this->form,
            'model' => $this->model,
            'options' => $this->options,
            'related_ids_attribute' => $this->attribute,
            'relation_name_in_juncture_model' => $this->relation_name_in_juncture_model,
            'juncture_relation_display_attribute' => $this->juncture_relation_display_attribute,
            'owner_id_attribute_in_juncture_table' => $this->owner_id_attribute_in_juncture_table,
            'related_id_attribute_in_juncture_table' => $this->related_id_attribute_in_juncture_table,
            'additional_juncture_data_prop' => $this->additional_juncture_data_prop,
            'data_list' => $this->data_list,
            'juncture_model' => $this->juncture_model,
            'juncture_attributes' => $this->juncture_attributes
        ]);
    }

    /**
     * @return void
     */
    private function registerJunctureUiJs()
    {
        // --- Loop through all juncture attributes to get fields configuration data
        $fields_config_data = []; // --- Holds the special configuration for each new field being added
        $callbacks = []; // --- Holds a callback for each field requires one

        // --- If we have an overall callback for after adding a new row then run it
        if(!empty($this->new_item_callback)){
            $callbacks[] = $this->new_item_callback;
        }

        foreach($this->juncture_attributes as $juncture_attribute_data){
            // --- Loop through validators on this attribute so we can create js validation for each attribute
            $validation_strs = [];
            $validators = $this->juncture_model->getActiveValidators($juncture_attribute_data['attribute']);
            foreach($validators as $validator){
                $validation_strs[] = $validator->clientValidateAttribute($this->juncture_model, $juncture_attribute_data['attribute'], $this->getView());
            }

            // --- If the juncture attribute has an input that requires a callback to initialize, set it
            if($juncture_attribute_data['input'] == self::INPUT_DATEPICKER){
                // --- If there is a datepicker destroy instances of it and re-initialize so the new input has it working
                // --- Not sure if doing this by the input type is the best decision but for now it seems that way
                $callbacks[self::INPUT_DATEPICKER] = new JsExpression('$(".krajee-datepicker").kvDatepicker("destroy");$(".krajee-datepicker").kvDatepicker({"autoclose":true,"format":"yyyy-mm-dd"});');
            }

            // --- Set up the config for this field which will be used in the javascript
            $fields_config_data[] = [
                'attribute' => $juncture_attribute_data['attribute'],
                'new_input' => (!isset($juncture_attribute_data['new_input']) || empty($juncture_attribute_data['new_input'])) ? $this->getNewInput($juncture_attribute_data) : $juncture_attribute_data['new_input'],
                'validator' => new JsExpression('function (attribute, value, messages, deferred, form) {'.implode($validation_strs, "\n").'}')
            ];
        }

        // --- Prepare some fields we can use in the javascript
        $field_id = Html::getInputId($this->model, $this->attribute);
        $juncture_identifier_shortname = strtolower($this->juncture_model->formName());

        // --- Set up a callback function each time a new record is added consisting of all of the
        $callback = (!empty($callbacks)) ? 'function(){'.implode($callbacks, '').'}' : null;

        // --- The javascript going into document.ready is specific to this instance
        $model_identifier = $this->model->{$this->model->primaryKey()[0]};

        // --- Set up the configuraiton used when adding a new field
        $new_juncture_data_config = [
            'model_form_name' => $this->model->formName(),
            'form_id' => '#'.$this->form->id,
            'additional_juncture_data_prop' => $this->additional_juncture_data_prop,
            'related_id_attribute_in_juncture_table' => $this->related_id_attribute_in_juncture_table,
            'juncture_identifier_shortname' => $juncture_identifier_shortname,
            'model_id' => $model_identifier,
            'owner_id_attribute_in_juncture_table' => $this->owner_id_attribute_in_juncture_table,
            'selected_data' => new JsExpression('e.params.data'), // --- 'e' refers to the event of the select2 plugin
            'attribute_config_data' => $fields_config_data,
            'callback' => ($callback) ? new JsExpression($callback) : null
        ];

        $new_juncture_data_config_json = Json::encode($new_juncture_data_config);

        $ready_js = <<<JS
$("[data-toggle=tooltip]").tooltip({placement: "auto"});
$("#{$field_id}").on("select2:select", function(e){
    addNewJunctureData({$new_juncture_data_config_json})
});

$("#{$field_id}").on("select2:unselect", function(e){
    var data = e.params.data;
    $("#{$juncture_identifier_shortname}-table tbody tr#{$this->owner_id_attribute_in_juncture_table}-{$model_identifier}-{$this->related_id_attribute_in_juncture_table}-"+data.id).remove();
});
JS;
        $this->getView()->registerJs($ready_js);

        // --- This javascript is global to this ui functionality
        $js = <<<JS
function validateNewDynamicField(config)
{
    var validation_config = {
        id: config.id,
        name: config.name,
        container: config.container,
        input: config.input,
        error: ".invalid-feedback",
        validate:  config.validator
    };
    console.log(validation_config);
    $(config.form_id).yiiActiveForm("add", validation_config);
}

function addNewJunctureData(config)
{
    // --- Get the data from the select element
    var data = config.selected_data;

    // --- Create a hidden input with the id of the juncture related model
    var hidden_input = $("<input>").attr({
        type: "hidden",
        name: config.model_form_name+"["+config.additional_juncture_data_prop+"]["+data.id+"]["+config.related_id_attribute_in_juncture_table+"]",
        id: config.juncture_identifier_shortname+"-"+config.related_id_attribute_in_juncture_table+"-"+data.id,
        value: data.id
    });

    // --- Create a label cell with the id field and the label
    var label_cell = $("<td></td>")
        .append(hidden_input)
        .append("<span class=\"display-attribute\">"+data.text+"</span>");

    // --- Create a new row with the label cell
    var new_row = $("<tr></tr>")
        .attr("id", config.owner_id_attribute_in_juncture_table+"-"+config.model_id+"-"+config.related_id_attribute_in_juncture_table+"-"+data.id)
        .append(label_cell);

    // --- Append all of the juncture fields that need input to the row
    $.each(config.attribute_config_data, function(idx, attribute_config_data){
        var new_input = $(attribute_config_data.new_input);

        // --- Update the "field" class used by Yii to add the ID to the end and update the
        // --- class of the container of the new input to specify this
        var new_input_container_classes = new_input.attr("class").split(/\s+/);
        var new_classes = [];
        $.each(new_input_container_classes, function(index, item){
            if (item === "field-"+config.juncture_identifier_shortname+"-"+attribute_config_data.attribute) {
                item += "-"+data.id;
            }
            new_classes.push(item)
        });
        $(new_input).attr("class", new_classes.join(" "));

        // --- Update the name of the new input to include the id of the juncture relation
        var new_input_id = config.juncture_identifier_shortname+"-"+attribute_config_data.attribute+"-"+data.id;;
        var new_input_name = config.model_form_name+"["+config.additional_juncture_data_prop+"]["+data.id+"]["+attribute_config_data.attribute+"]";
        $("select, input, textarea", new_input)
            .attr("name", new_input_name)
            .attr("id", new_input_id);
        var new_row_cell = $("<td></td>")
            .append(new_input);
        new_row.append(new_row_cell);

        // --- Add form validation for the new field
        validateNewDynamicField({
            form_id: config.form_id,
            id: new_input_id,
            name: new_input_name,
            container: ".field-"+config.juncture_identifier_shortname+"-"+attribute_config_data.attribute+"-"+data.id,
            input: "#"+new_input_id,
            validator: attribute_config_data.validator
        });
    });

    // --- Append the new row to the table
    $("#"+config.juncture_identifier_shortname+"-table tbody").append(new_row);

    // --- Run the callback if there is one
    if(config.callback){
        config.callback();
    }
}
JS;

        $this->getView()->registerJs($js, View::POS_END, 'add-juncture-record');
    }

    /**
     * Gets a the default HTML for a new input to be used when a new juncture relation is added
     * @param array $juncture_attribute_data
     * @return string
     */
    private function getNewInput($juncture_attribute_data)
    {
        // --- Set up the default ActiveField instance
        $active_field_default_options = [
            'template'=>'{input}{error}',
            'enableClientValidation'=>false
        ];

        // --- If there was configuraiton for the active field options passed in merge them
        $active_field_options = (isset($juncture_attribute_data['active_field_options'])) ?
             ArrayHelper::merge($active_field_default_options, $juncture_attribute_data['active_field_options']) : 
             $active_field_default_options;      

        $field_default = $this->form->field(
            $this->juncture_model,
            $juncture_attribute_data['attribute'],
            $active_field_options
        );


        // --- Some default for the field
        $field_attributes = [
            'id' => null // --- This will be set in the javascript that generates the new fields so leave it blank
        ];

        if(isset($juncture_attribute_data['inputOptions']) && !empty($juncture_attribute_data['inputOptions'])){
            $field_attributes = array_merge($field_attributes, $juncture_attribute_data['inputOptions']);
        }

        // --- Apply a default value if one is set
        if(isset($juncture_attribute_data['default_value'])){
            $this->juncture_model->{$juncture_attribute_data['attribute']} = $juncture_attribute_data['default_value'];
        }

        // --- Return the input based on the type
        switch($juncture_attribute_data['input']){
            case self::INPUT_TEXT:
                return $field_default->textInput($field_attributes)->render();
            case self::INPUT_TEXTAREA: 
                return $field_default->textArea($field_attributes)->render();
            case self::INPUT_DROPDOWN: 
                return $field_default->dropdownList($juncture_attribute_data['data'], $field_attributes)->render();
            case self::INPUT_DATEPICKER:
                return $field_default->widget(DatePicker::classname(), [
                    'options' => $field_attributes,
                    'pluginOptions' => [
                        'autoclose' => true,
                        'format' => 'yyyy-mm-dd'
                    ]
                ])->render();
            default: 
        }
    }
}
?>