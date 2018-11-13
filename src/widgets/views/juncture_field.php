<?php

use kartik\select2\Select2;
use yii\helpers\Html;

$model_form_name = $model->formName();
$juncture_identifier_shortname = strtolower($juncture_model->formName());
?>

    <div id="<?= $juncture_identifier_shortname; ?>-container" class="juncture-with-extra-data-container">
        <?= $form->field($model, $related_ids_attribute)->widget(Select2::className(), [
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
                        <i class="fas fa-question-circle" data-toggle="tooltip" title="<?= $juncture_model->getAttributeHint($juncture_attribute_data['attribute']); ?>"></i>
                    </th>
                    <?php endforeach; ?>
                </thead>
                <tbody>
                <?php foreach($model->{$additional_juncture_data_attribute} as $juncture_model): ?>
                    <tr id="<?= $owner_id_attribute_in_juncture_table; ?>-<?= $juncture_model->{$owner_id_attribute_in_juncture_table}; ?>-<?= $related_id_attribute_in_juncture_table; ?>-<?= $juncture_model->{$related_id_attribute_in_juncture_table}; ?>">
                        <td>
                            <?= Html::activeHiddenInput($juncture_model, $related_id_attribute_in_juncture_table, [
                                    'name' => $model_form_name.'['.$additional_juncture_data_attribute.']['.$juncture_model->{$related_id_attribute_in_juncture_table}.']['.$related_id_attribute_in_juncture_table.']',
                                    'id' => Html::getInputId($juncture_model, $related_id_attribute_in_juncture_table).'-'.$juncture_model->{$related_id_attribute_in_juncture_table}
                            ]); ?>
                            <?= $juncture_model->{$relation_name_in_juncture_table}->{$juncture_relation_display_attribute}; ?>
                        </td>
                        <?php
                        foreach($juncture_attributes as $juncture_attribute_data): // --- Renders the existing values
                            $input_id = Html::getInputId($juncture_model, $juncture_attribute_data['attribute']).'-'.$juncture_model->{$related_id_attribute_in_juncture_table};
                            $input_name = $model_form_name.'['.$additional_juncture_data_attribute.']['.$juncture_model->{$related_id_attribute_in_juncture_table}.']['.$juncture_attribute_data['attribute'].']';
                        ?>
                        <td>
                            <?php if($juncture_attribute_data['input'] == 'dropDownList'): ?>
                            <?= $form->field($juncture_model, $juncture_attribute_data['attribute'], [
                                'template' => '{input}{error}'
                            ])->dropDownList($juncture_attribute_data['data'], [
                                'name' => $input_name,
                                'id' => $input_id
                            ]);?>
                            <?php else: ?>
                            <?= $form->field($juncture_model, $juncture_attribute_data['attribute'], [
                                'template' => '{input}{error}',
                                'selectors' => [
                                    'input' => '#'.$input_id,
                                    'container' => '#'.$input_id.'-container',
                                    'validation_id' => $input_id
                                ],
                                'options' => [
                                    'id' => $input_id.'-container',
                                ]
                            ])->textInput([
                                'name' => $input_name,
                                'id' => $input_id
                            ]);?>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>