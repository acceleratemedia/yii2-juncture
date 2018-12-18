<?php

use bvb\juncture\widgets\JunctureField;
use kartik\date\DatePicker;
use kartik\select2\Select2;
use yii\helpers\Html;

$model_form_name = $model->formName();
$juncture_identifier_shortname = strtolower($juncture_model->formName());
?>

    <div id="<?= $juncture_identifier_shortname; ?>-container" class="juncture-with-extra-data-container">
        <?= $form->field($model, $related_ids_attribute, ['template' => '{input}{error}'])->widget(Select2::className(), [
            'model' => $model,
            'attribute' => $related_ids_attribute,
            'data' => $data_list,
        ]) ?>

        <div class="related-data-container">
            <table id="<?= $juncture_identifier_shortname; ?>-table" class="table table-responsive table-striped table-sm">
                <thead>
                    <th><?= $juncture_model->getAttributeLabel($related_id_attribute_in_juncture_table); ?></td>
                    <?php foreach($juncture_attributes as $juncture_attribute_data): ?>
                    <th>
                        <?= $juncture_model->getAttributeLabel($juncture_attribute_data['attribute']); ?>
                        <?php if($hint = $juncture_model->getAttributeHint($juncture_attribute_data['attribute'])): ?>
                        <i class="fas fa-question-circle" data-toggle="tooltip" title="<?= $hint; ?>"></i>
                        <?php endif; ?>
                    </th>
                    <?php endforeach; ?>
                </thead>
                <tbody>
                <?php 
                // --- Very important to use our activefield for custom validation ids on these repeating juncture records
                $original_field_class = $form->fieldClass;
                $form->fieldClass = '\bvb\juncture\widgets\ActiveField';
                foreach($model->{$additional_juncture_data_prop} as $juncture_model): ?>
                    <tr id="<?= $owner_id_attribute_in_juncture_table; ?>-<?= $juncture_model->{$owner_id_attribute_in_juncture_table}; ?>-<?= $related_id_attribute_in_juncture_table; ?>-<?= $juncture_model->{$related_id_attribute_in_juncture_table}; ?>">
                        <td>
                            <?= Html::activeHiddenInput($juncture_model, $related_id_attribute_in_juncture_table, [
                                    'name' => $model_form_name.'['.$additional_juncture_data_prop.']['.$juncture_model->{$related_id_attribute_in_juncture_table}.']['.$related_id_attribute_in_juncture_table.']',
                                    'id' => Html::getInputId($juncture_model, $related_id_attribute_in_juncture_table).'-'.$juncture_model->{$related_id_attribute_in_juncture_table}
                            ]); ?>
                            <?= $juncture_model->{$relation_name_in_juncture_model}->{$juncture_relation_display_attribute}; ?>
                        </td>
                        <?php


                        // --- Loop through all juncture atrtibtues
                        foreach($juncture_attributes as $juncture_attribute_data): // --- Renders the existing values
                            // --- Get a unique id and name for each based on juncture relationships
                            $input_id = Html::getInputId($juncture_model, $juncture_attribute_data['attribute']).'-'.$juncture_model->{$related_id_attribute_in_juncture_table};
                            $input_name = $model_form_name.'['.$additional_juncture_data_prop.']['.$juncture_model->{$related_id_attribute_in_juncture_table}.']['.$juncture_attribute_data['attribute'].']';

                            // --- Set some defaults for the activefield
                            $active_field_default = $form->field($juncture_model, $juncture_attribute_data['attribute'], [
                                'template' => '{input}{error}',
                                'selectors' => [
                                    'input' => '#'.$input_id,
                                    'container' => '#'.$input_id.'-container',
                                ],
                                'options' => [
                                    'id' => $input_id.'-container',
                                    'validation_id' => $input_id
                                ]
                            ]);

                            $input_options_defaults = [
                                'name' => $input_name,
                                'id' => $input_id
                            ];
                        ?>
                        <td>
                            <?php 
                            if($juncture_attribute_data['input'] == JunctureField::INPUT_DROPDOWN){
                                echo $active_field_default->dropDownList($juncture_attribute_data['data'], $input_options_defaults);
                            } elseif($juncture_attribute_data['input'] == JunctureField::INPUT_TEXT){
                                echo $active_field_default->textInput($input_options_defaults);
                            } elseif($juncture_attribute_data['input'] == JunctureField::INPUT_TEXTAREA){
                                echo $active_field_default->textArea($input_options_defaults);
                            } elseif($juncture_attribute_data['input'] == JunctureField::INPUT_DATEPICKER){
                                echo $active_field_default->widget(DatePicker::classname(), [
                                    'options' => $input_options_defaults,
                                    'pluginOptions' => [
                                        'autoclose' => true,
                                        'format' => 'yyyy-mm-dd'
                                    ]
                                ]);
                            }
                            ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php 
                endforeach;
                // --- Reset the field class to the original since we are done repeating our fields
                $form->fieldClass = $original_field_class; ?>
                </tbody>
            </table>
        </div>
    </div>