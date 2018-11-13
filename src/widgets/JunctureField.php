<?php
namespace bvb\juncture\widgets;

use bvb\juncture\behaviors\SaveJunctureRelationships;
use yii\helpers\Html;
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
     * @var \yii\widgets\ActiveForm
     */
    public $form;

    /**
     * @var string
     */
    public $relation_name_in_juncture_table;

    /**
     * @var string
     */
    public $juncture_relation_display_attribute;

    /**
     * @var string
     */
    public $owner_id_attribute_in_juncture_table;

    /**
     * @var string
     */
    public $related_id_attribute_in_juncture_table;

    /**
     * @var string
     */
    public $additional_juncture_data_attribute;

    /**
     * @var array
     */
    public $data_list;

    /**
     * @var\yii\db\ActiveRecord
     */
    public $juncture_model;

    /**
     * @var array
     */
    public $juncture_attributes;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        // --- Just for setting up some defaults
        foreach($this->model->behaviors as $behavior){
            // --- Check to see if the behavior for saving juncture relationships is attached
            if($behavior::className() == SaveJunctureRelationships::className()){
                // --- Loop through the set up relationships to see if this widget is for the specified relationship
                foreach($behavior->relationships as $relationship_data){
                    // --- Use some default settings if we have null values
                    if($relationship_data['related_ids_attribute'] == $this->attribute){
                        if($this->owner_id_attribute_in_juncture_table === null){
                            $this->owner_id_attribute_in_juncture_table = $relationship_data['owner_id_attribute_in_juncture_table'];
                        }

                        if($this->related_id_attribute_in_juncture_table === null){
                            $this->related_id_attribute_in_juncture_table = $relationship_data['related_id_attribute_in_juncture_table'];
                        }

                        if($this->additional_juncture_data_attribute === null){
                            $this->additional_juncture_data_attribute = $relationship_data['additional_juncture_data_attribute'];
                        }

                        if($this->juncture_model === null){
                            $this->juncture_model = new $relationship_data['juncture_model'];
                        }
                    }
                }
            }
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
            'related_ids_attribute' => $this->attribute,
            'relation_name_in_juncture_table' => $this->relation_name_in_juncture_table,
            'juncture_relation_display_attribute' => $this->juncture_relation_display_attribute,
            'owner_id_attribute_in_juncture_table' => $this->owner_id_attribute_in_juncture_table,
            'related_id_attribute_in_juncture_table' => $this->related_id_attribute_in_juncture_table,
            'additional_juncture_data_attribute' => $this->additional_juncture_data_attribute,
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
        $fields_config_data = [];
        foreach($this->juncture_attributes as $juncture_attribute_data){
            // --- Loop through validators on this attribute so we can create js validation for each attribute
            $validation_strs = [];
            $validators = $this->juncture_model->getActiveValidators($juncture_attribute_data['attribute']);
            foreach($validators as $validator){
                $validation_strs[] = $validator->clientValidateAttribute($this->juncture_model, $juncture_attribute_data['attribute'], $this->getView());
            }

            // --- Set up the config for this field which will be used in the javascript
            $fields_config_data[] = [
                'attribute' => $juncture_attribute_data['attribute'],
                'new_input' => (!isset($juncture_attribute_data['new_input']) || empty($juncture_attribute_data['new_input'])) ? $this->getNewInput($juncture_attribute_data) : $juncture_attribute_data['new_input'],
                'validator' => new JsExpression('function (attribute, value, messages, deferred, form) {'.implode($validation_strs, "\n").'}')
            ];
        }

        // --- Prepare some fields we can use in the javascript
        $fields_config_json = Json::encode($fields_config_data);
        $field_id = Html::getInputId($this->model, $this->attribute);
        $juncture_identifier_shortname = strtolower($this->juncture_model->formName());

        // --- The javascript going into document.ready is specific to this instance
        $ready_js = <<<JS
$("[data-toggle=tooltip]").tooltip({placement: "auto"});
$("#{$field_id}").on("select2:select", function(e){
    addNewJunctureData({
        model_form_name: "{$this->model->formName()}",
        form_id: "#{$this->form->id}",
        additional_juncture_data_attribute: "{$this->additional_juncture_data_attribute}",
        related_id_attribute_in_juncture_table: "{$this->related_id_attribute_in_juncture_table}",
        juncture_identifier_shortname: "{$juncture_identifier_shortname}",
        model_id : "{$this->model->id}",
        owner_id_attribute_in_juncture_table: "{$this->owner_id_attribute_in_juncture_table}",
        selected_data: e.params.data,
        attribute_config_data: {$fields_config_json}
    })
});

$("#{$field_id}").on("select2:unselect", function(e){
    var data = e.params.data;
    $("#{$juncture_identifier_shortname}-table tbody tr#{$this->owner_id_attribute_in_juncture_table}-{$this->model->id}-{$this->related_id_attribute_in_juncture_table}-"+data.id).remove();
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
        name: config.model_form_name+"["+config.additional_juncture_data_attribute+"]["+data.id+"]["+config.related_id_attribute_in_juncture_table+"]",
        id: config.juncture_identifier_shortname+"-"+config.related_id_attribute_in_juncture_table+"-"+data.id,
        value: data.id
    });

    // --- Create a label cell with the id field and the label
    var label_cell = $("<td></td>")
        .append(hidden_input)
        .append(data.text);

    // --- Create a new row with the label cell
    var new_row = $("<tr></tr>")
        .attr("id", config.owner_id_attribute_in_juncture_table+"-"+config.model_id+"-"+config.related_id_attribute_in_juncture_table+"-"+data.id)
        .append(label_cell);

    // --- Append all of the juncture fields that need input to the row
    $.each(config.attribute_config_data, function(idx, attribute_config_data){
        console.log(attribute_config_data);
        var new_input = $(attribute_config_data.new_input);
        console.log(new_input);
        // --- Update the container of the new input to specify the id of the juncture relation
        var new_input_container_class = new_input.attr("class")+"-"+data.id;
        $(new_input).attr("class", new_input_container_class);

        // --- Update the name of the new input to include the id of the juncture relation
        var new_input_id = config. juncture_identifier_shortname+"-"+attribute_config_data.attribute+"-"+data.id;;
        var new_input_name = config.model_form_name+"["+config.additional_juncture_data_attribute+"]["+data.id+"]["+attribute_config_data.attribute+"]";
        $("select, input", new_input)
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
        switch($juncture_attribute_data['input']){
            case 'textInput':
                return $this->form->field($this->juncture_model, $juncture_attribute_data['attribute'], ['template'=>'{input}{error}', 'enableClientValidation'=>false])->textInput(['id'=>null])->render();
            case 'dropdownList': 
                return null;
        }
    }
}
?>