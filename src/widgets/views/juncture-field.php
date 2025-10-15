<?php

use bvb\juncture\widgets\ActiveField;
use bvb\juncture\widgets\InlineRepeaterField;
use bvb\juncture\widgets\JunctureField;
use kartik\date\DatePicker;
use kartik\select2\Select2;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

$modelFormName = $model->formName();
$junctureIdentifierShortname = strtolower($junctureModel->formName());
?>

<div id="<?= $junctureIdentifierShortname; ?>-container" class="juncture-with-extra-data-container">
    <?= $form->field($model, $relatedPksAttribute, ['template' => '{input}{error}'])->widget(Select2::class, [
        'model' => $model,
        'attribute' => $relatedPksAttribute,
        'data' => $dropdownOptions,
        'options' => ArrayHelper::merge($options, [
            'multiple' => true
        ]),
        'pluginOptions' => [
            'allowClear' => true,
            'closeOnSelect' => false
        ]
    ]) ?>

    <div class="related-data-container table-responsive">
        <table id="<?= $junctureIdentifierShortname; ?>-table" class="table table-striped table-sm">
            <thead>
                <th><?= $junctureModel->getAttributeLabel($relatedPksColumnInJunctureTable); ?></td>
                    <?php foreach ($junctureAttributes as $junctureAttributeData) : ?>
                <th>
                    <?= $junctureModel->getAttributeLabel($junctureAttributeData['attribute']); ?>
                    <?php if ($hint = $junctureModel->getAttributeHint($junctureAttributeData['attribute'])) : ?>
                        <i class="fas fa-question-circle" data-toggle="tooltip" title="<?= $hint; ?>"></i>
                    <?php endif; ?>
                </th>
            <?php endforeach; ?>
            </thead>
            <tbody>
                <?php
                // --- Very important to use our activefield for custom validation ids on these repeating juncture records
                $originalFieldClass = $form->fieldClass;
                $form->fieldClass = ActiveField::class;

                // --- The javascript going into document.ready is specific to this instance
                if (is_array($ownerPkColumnInJunctureTable)) {
                    $ownerPkFieldNames = [];
                    foreach ($model->primaryKey() as $attributeName) {
                        $ownerPkFieldValues[] = $model->{$attributeName};
                    }
                    $ownerPkFieldNameForRowId = implode('-', $ownerPkColumnInJunctureTable);
                    $ownerPkFieldValueForRowId = implode('-', $ownerPkFieldValues);
                } else {
                    $ownerPkFieldNameForRowId = $ownerPkColumnInJunctureTable;
                    $ownerPkFieldValueForRowId = $model->{$model->primaryKey()[0]};
                }
                foreach ($model->{$additionalJunctureDataProp} as $junctureModel) : ?>
                    <tr id="<?= $ownerPkFieldNameForRowId; ?>-<?= $ownerPkFieldValueForRowId; ?>-<?= $relatedPksColumnInJunctureTable; ?>-<?= $junctureModel->{$relatedPksColumnInJunctureTable}; ?>">
                        <td>
                            <?= Html::activeHiddenInput($junctureModel, $relatedPksColumnInJunctureTable, [
                                'name' => $modelFormName . '[' . $additionalJunctureDataProp . '][' . $junctureModel->{$relatedPksColumnInJunctureTable} . '][' . $relatedPksColumnInJunctureTable . ']',
                                'id' => Html::getInputId($junctureModel, $relatedPksColumnInJunctureTable) . '-' . $junctureModel->{$relatedPksColumnInJunctureTable}
                            ]); ?>
                            <span class="display-attribute"><?= $junctureModel->{$relationNameInJunctureModel}->{$junctureRelationDisplayAttribute}; ?></span>
                        </td>
                        <?php


                        // --- Loop through all juncture atrtibtues
                        foreach ($junctureAttributes as $junctureAttributeData) : // --- Renders the existing values
                            // --- Get a unique id and name for each based on juncture relationships
                            $inputId = Html::getInputId($junctureModel, $junctureAttributeData['attribute']) . '-' . $junctureModel->{$relatedPksColumnInJunctureTable};
                            $inputName = $modelFormName . '[' . $additionalJunctureDataProp . '][' . $junctureModel->{$relatedPksColumnInJunctureTable} . '][' . $junctureAttributeData['attribute'] . ']';

                            // --- Set some defaults for the activefield
                            $activeFieldDefaultOptions = [
                                'template' => '{input}{error}',
                                'selectors' => [
                                    'input' => '#' . $inputId,
                                    'container' => '#' . $inputId . '-container',
                                ],
                                'options' => [
                                    'id' => $inputId . '-container',
                                    'validation_id' => $inputId
                                ]
                            ];

                            // --- If there was configuraiton for the active field options passed in merge them
                            $activeFieldOptions = (isset($junctureAttributeData['activeFieldOptions'])) ?
                                ArrayHelper::merge($activeFieldDefaultOptions, $junctureAttributeData['activeFieldOptions']) :
                                $activeFieldDefaultOptions;

                            $activeFieldDefault = $form->field($junctureModel, $junctureAttributeData['attribute'], $activeFieldOptions);

                            $inputOptionsDefaults = [
                                'name' => $inputName,
                                'id' => $inputId
                            ];

                            if (!empty($junctureAttributeData['inputOptions'])) {
                                $inputOptionsDefaults = array_merge($inputOptionsDefaults, $junctureAttributeData['inputOptions']);
                            }
                        ?>
                            <td>
                                <?php
                                if ($junctureAttributeData['input'] == JunctureField::INPUT_DROPDOWN) {
                                    echo $activeFieldDefault->dropDownList($junctureAttributeData['data'], $inputOptionsDefaults);
                                } elseif ($junctureAttributeData['input'] == JunctureField::INPUT_TEXT) {
                                    echo $activeFieldDefault->textInput($inputOptionsDefaults);
                                } elseif ($junctureAttributeData['input'] == JunctureField::INPUT_TEXTAREA) {
                                    echo $activeFieldDefault->textArea($inputOptionsDefaults);
                                } elseif ($junctureAttributeData['input'] == JunctureField::INPUT_DATEPICKER) {
                                    echo $activeFieldDefault->widget(DatePicker::class, [
                                        'options' => $inputOptionsDefaults,
                                        'pluginOptions' => [
                                            'autoclose' => true,
                                            'format' => 'yyyy-mm-dd'
                                        ]
                                    ]);
                                } elseif ($junctureAttributeData['input'] == JunctureField::INPUT_SELECT2) {
                                    echo $activeFieldDefault->widget(Select2::class, [
                                        'data' => $junctureAttributeData['data'],
                                        'options' => $inputOptionsDefaults
                                    ]);
                                } elseif ($junctureAttributeData['input'] == JunctureField::INPUT_WIDGET) {
                                    // --- Handle custom widgets
                                    if (!isset($junctureAttributeData['widgetClass'])) {
                                        throw new InvalidConfigException('The "widgetClass" property must be set when using INPUT_WIDGET type.');
                                    }

                                    $widgetOptions = isset($junctureAttributeData['widgetOptions']) ? $junctureAttributeData['widgetOptions'] : [];
                                    $widgetOptions['options'] = $inputOptionsDefaults;

                                    // --- Special handling for InlineRepeaterField
                                    if ($junctureAttributeData['widgetClass'] === InlineRepeaterField::class) {
                                        // Set the name prefix for the repeater so it generates correct field names
                                        $widgetOptions['namePrefix'] = $modelFormName . '[' . $additionalJunctureDataProp . '][' . $junctureModel->{$relatedPksColumnInJunctureTable} . ']';

                                        // Pass existing child records (bonuses) if they exist
                                        if (isset($junctureModel->{$junctureAttributeData['attribute']}) && is_array($junctureModel->{$junctureAttributeData['attribute']})) {
                                            $widgetOptions['childRecords'] = $junctureModel->{$junctureAttributeData['attribute']};
                                        } else {
                                            $widgetOptions['childRecords'] = [];
                                        }

                                        // Set parent FK values for existing records
                                        if (isset($widgetOptions['parentFkColumns'])) {
                                            $parentFkValues = [];
                                            foreach ($widgetOptions['parentFkColumns'] as $fkColumn) {
                                                $parentFkValues[$fkColumn] = $junctureModel->{$fkColumn} ?? null;
                                            }
                                            $widgetOptions['parentFkValues'] = $parentFkValues;
                                        }
                                    }

                                    echo $activeFieldDefault->widget($junctureAttributeData['widgetClass'], $widgetOptions);
                                }
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php
                endforeach;
                // --- Reset the field class to the original since we are done repeating our fields
                $form->fieldClass = $originalFieldClass; ?>
            </tbody>
        </table>
    </div>
</div>
