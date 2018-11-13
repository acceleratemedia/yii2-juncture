<?php

namespace bvb\juncture\behaviors;

use yii\db\BaseActiveRecord;
use yii\helpers\Html;
use yii\web\BadRequestHttpException;

/**
 * For saving the related models that represet a juncture relationship
 *
 * There are several caveats to get this to work
 * 1) On the form, we should use a checkbox or multi-select and they should be set up so that if nothing is selected it passes through a non-array value
 * 2) The model needs to have an attribute on it which is an array of the ids of the related model in the many to many relationship
 * 3) The model needs to have a ActiveRecord relation set to the model it has a many to many relationship with AND to the juncture table
 *
 * Also:
 * 1) This was written with the ability to save extra columns on juncture relationships if configured as directed in the example below
 *
 * Suggestions:
 * 1) Use Select2 as an frontend UI choice and booststrap 4
 * 
 * Behaviors set up configuration must look like:
 *   [
 *       'class' => SaveJunctureRelationships::className(),
 *       'relationships' => [
 *           [
 *               'relation_name' => 'benefits', // --- Name of the relationship for the model this has a many to many relationship with
 *               'related_ids_attribute' => 'benefit_ids', // -- Name of the attribute on the this that holds the ids of the related records
 *               'juncture_relation_name' => 'cardBenefits', // --- Name of the relationship to the juncture table
 *               'juncture_model_name' => CardBenefit::className(), // --- Class name of the juncture model
 *               'related_id_attribute_in_juncture_table' => 'benefit_id', // --- Name of the related model's id field in the juncture table
 *               'owner_id_attribute_in_juncture_table' => 'card_id' // --- Name of this model's id field in the juncture table
 *               'additional_juncture_data_attribute' => 'benefits_data', // --- Optional if we have additional attributes on the juncture table
 *               'additional_juncture_attributes' => [ // --- Optional name of additional attributes in the juncture table
 *                  'compares'
 *               ]
 *           ]
 *       ]
 *   ] 
 */
class SaveJunctureRelationships extends \yii\base\Behavior
{
    /**
     * The configured relationships for the model to be used for saving
     * relational data in the behavior
     * @var array
     */
    public $relationships = [];

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            BaseActiveRecord::EVENT_AFTER_FIND => 'afterFind',
            BaseActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
        ];
    }

    /**
     * Populates original data for juncture relationships so we can see what to add/delete/update after save
     * @return void
     */
    public function afterFind()
    {
        foreach($this->relationships as &$relationship_data){
            $relationship_data['original_ids'] = [];
            foreach($this->owner->{$relationship_data['juncture_relation_name']} as $juncture_model){
                $relationship_data['original_ids'][]  = $juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']};
                $this->owner->{$relationship_data['related_ids_attribute']}[] = $juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']};
            }

            if(isset($relationship_data['additional_juncture_data_attribute'])){
                $relationship_data['original_data'] = [];
                foreach($this->owner->{$relationship_data['juncture_relation_name']} as $juncture_model){
                    $relationship_data['original_data'][$juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']}]  = $juncture_model;
                    $this->owner->{$relationship_data['additional_juncture_data_attribute']}[$juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']}] = $juncture_model;
                }
            }
        }
    }


    /**
     * Changes any array data from juncture relationships with extra data into a corresponding model
     * The idea here is that when we POST the data it gets assigned to the model's property in array form
     * and by doing this we restore that model property to an array of models instead of an array of arrays
     * and this means that if there is a validation error the page reloads with models and not arrays
     * so the ui can still work as expected
     * @return void
     */
    public function beforeValidate()
    {
        foreach($this->relationships as &$relationship_data){
            if(isset($relationship_data['additional_juncture_data_attribute'])){
                foreach($this->owner->{$relationship_data['additional_juncture_data_attribute']} as $juncture_relationship_id => $additional_juncture_data_array){
                    $juncture_model = new $relationship_data['juncture_model_name'];
                    $juncture_model->attributes = $additional_juncture_data_array;
                    // --- Make sure to assign the id of this model
                    // --- The primary key returns an array so we may eventually need a todo to handle composite keys - but this is a juncture table so handling a composite key other model in a table with a composite key is complicated and hopefully is never run into
                    $juncture_model->{$relationship_data['owner_id_attribute_in_juncture_table']} = $this->owner->{$this->owner->primaryKey()[0]};
                    $this->owner->{$relationship_data['additional_juncture_data_attribute']}[$juncture_relationship_id] = $juncture_model;
                }
            }
        }
    }

    /**
     * Inserts related models
     * @param yii\db\AfterSaveEvent $event
     * @return void
     */
    public function afterInsert($event)
    {
        foreach($this->relationships as &$relationship_data){
            // --- Make sure it's an array so we know to insert
            if(is_array($this->owner->{$relationship_data['related_ids_attribute']})){
                // --- Loop through and insert the values
                foreach($this->owner->{$relationship_data['related_ids_attribute']} as $related_id_to_add){
                    $this->saveNewJunctureRelationship($relationship_data, $related_id_to_add);
                }
            }
        }
    }

    /**
     * Updates or deletes related models
     * @param yii\db\AfterSaveEvent $event
     * @return void
     */
    public function afterUpdate($event)
    {
        foreach($this->relationships as &$relationship_data){
            // --- Find the difference between the beginning ids and the ending ids to see which ones to remove
            $related_ids_to_remove = !is_array($this->owner->{$relationship_data['related_ids_attribute']}) ? 
                $relationship_data['original_ids'] :
                array_diff($relationship_data['original_ids'], $this->owner->{$relationship_data['related_ids_attribute']});
            foreach($related_ids_to_remove as $related_id_to_remove){
                // --- Loop through to delete and do use the models so any afterDelete actions are performed on them
                foreach($this->owner->{$relationship_data['juncture_relation_name']} as $juncture_model){
                    if($juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']} == $related_id_to_remove){
                        $juncture_model->delete();
                    }
                }
            }                    


            // --- Find the difference between the beginning ids and ending ids to see which oens to add
            $related_ids_to_add = !is_array($this->owner->{$relationship_data['related_ids_attribute']}) ? 
                [] :
                array_diff($this->owner->{$relationship_data['related_ids_attribute']}, $relationship_data['original_ids']);
            foreach($related_ids_to_add as $related_id_to_add){
                $this->saveNewJunctureRelationship($relationship_data, $related_id_to_add);
            }

            // --- If there is additional data on the juncture relationship loop through them and check to see if we need to update anything
            if(isset($relationship_data['additional_juncture_data_attribute'])){
                foreach($this->owner->{$relationship_data['additional_juncture_data_attribute']} as $juncture_relationship_id => $juncture_relationship_model){
                    if(!in_array($juncture_relationship_id, $related_ids_to_add) && !in_array($juncture_relationship_id, $related_ids_to_remove)){
                        // --- If this was not an added or removed relationship then check with the original ones to see if it needs to be updated
                        foreach($relationship_data['original_data'] as $original_juncture_related_id => $original_juncture_relationship_model){
                            if($juncture_relationship_id == $original_juncture_related_id){
                                $original_juncture_relationship_model->attributes = $juncture_relationship_model->attributes;
                                if(!$original_juncture_relationship_model->save()){
                                    throw new BadRequestHttpException('There was a problem udpating a relationship: '.Html::errorSummary($original_juncture_relationship_model));
                                }
                            }
                        }
                    } 
                }
            }
        }
    }

    /**
     * @param array $relationship_data
     * @param int $related_id_to_add
     * @return bool
     */
    private function saveNewJunctureRelationship($relationship_data, $related_id_to_add)
    {
        $juncture_model = new $relationship_data['juncture_model_name'];
        $juncture_model->{$relationship_data['owner_id_attribute_in_juncture_table']} = $this->owner->id;
        $juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']} = $related_id_to_add;

        // --- If we have additional attributes in the juncture relationship we want to get those and save them
        if(isset($relationship_data['additional_juncture_data_attribute_names'])){
            // --- Loop through the attributes and get the values from POST and assign them
            foreach($relationship_data['additional_juncture_data_attribute_names'] as $juncture_attribute_name){
                $juncture_model->{$juncture_attribute_name} = $this->owner->{$relationship_data['additional_juncture_data_attribute']}[$related_id_to_add][$juncture_attribute_name];
            }
        }
        if(!$juncture_model->save()){
            // --- I would like to find a better way to handle an error on a juncture relationship with validation but for now I'm not sure how besides throwing this error since it's so far removed from the normal flow in this behavior
            throw new BadRequestHttpException('There was a problem creating a relationship: '.Html::errorSummary($juncture_model));
        }
        return true;
    }
}