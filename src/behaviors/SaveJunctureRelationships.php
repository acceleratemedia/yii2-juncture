<?php

namespace bvb\juncture\behaviors;

use Yii;
use yii\db\BaseActiveRecord;
use yii\helpers\Html;
use yii\helpers\Inflector;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;

/**
 * SaveJunctureRelationships is a behavior that can be attached to ActiveRecord models to save related data
 * into a juncture table when saving data on the model itself . This also comes with JunctureField which is
 * a widget that creates a UI using the Select2 jQuery plugin for setting related data
 *
 * Minimum Requirements:
 * 1) The owner needs an attribute which is an array of the ids of the related model in the many
 * to many relationship. Make sure this attribute can be massively assigned with proper validation rules.
 * 2) The owner needs to have relations set to the model it has a many to many relationship with AND to the
 * juncture table
 *
 * This behavior can also be used to save juncture records with additional data. If this is desired, additional
 * requirements are:
 * 1) The owner needs an attribute which is an array for the additional data for each juncture that can be massively assigned
 * with validation rules
 * 
 * Example configuration in behaviors():
 *   [
 *       'class' => SaveJunctureRelationships::className(),
 *       'relationships' => [
 *           [
 *               // --- Required fields
 *               'juncture_model' => CardBenefit::className(), // --- Model representing the juncture table
 *
 *              // --- Optional but can be used to determine the rest of the optional fields
 *               'related_model' => Benefit::className(), // --- Model of the related table
 *                 
 *               // --- Optional fields; defaults will be set for these fields based on names of the owner, related, and juncture tables
 *               'relation_name' => 'benefits', // --- Name of the AR relationship for the related model
 *               'related_ids_attribute' => 'benefit_ids', // -- Name of the owner's attribute that holds the ids of related records
 *               'juncture_relation_name' => 'cardBenefits', // --- Name of the AR relationship to the juncture model
 *               'related_id_attribute_in_juncture_table' => 'benefit_id', // --- Name of the related table's id field in the juncture table
 *               'owner_id_attribute_in_juncture_table' => 'card_id' // --- Name of the owner's id field in the juncture table
 *
 *               // --- Optional fields if additional data needs to be saved in the juncture records
 *               'additional_juncture_data_prop' => 'benefits_data', // --- Name of the owner's attribute which holds additional data
 *               'additional_juncture_attributes' => [ // --- Names of additional attributes with data that needs saving
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
     * Holds juncture models for validating attributes exist. Only used if the owner does not have
     * a related model already attached to it. Key is string classname of the juncture model
     * we want for validating and value is the class itself. Need to array it because we can have multiple relationships
     * on a single model and so might need multiple juncture models to validate attributes exist
     * @var yii\db\ActiveRecord
     */
    private $_juncture_model_for_validating;

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
     * {@inheritdoc}
     */
    public function attach($owner){
        parent::attach($owner);
        if(empty($this->relationships)){
            throw new InvalidConfigException('Relationships must be configred for SaveJunctureRelationships behavior to work');
        }
        foreach($this->relationships as &$relationship_data){
            $this->validateRelationConfig($relationship_data);
        }
    }

    /**
     * Validates the relationship data array
     */
    private function validateRelationConfig()
    {
        // --- Loop through all set up relations and ensure they are correctly configured and set defaults
        foreach($this->relationships as &$relationship_data){
            // --- Check for required configuration
            if(!isset($relationship_data['juncture_model'])){
                throw new InvalidConfigException('The `juncture_model` key must be set in the relationship data');
            }

            $this->applyDefaults($relationship_data);
        }
    }

    /**
     * Applies some default values to the relationship data if those values are not set
     * @param array $relationship_data
     */
    private function applyDefaults(&$relationship_data)
    {
        if(!isset($relationship_data['juncture_relation_name'])){
            $relationship_data['juncture_relation_name'] = lcfirst(Inflector::pluralize(Inflector::id2camel($relationship_data['juncture_model']::tableName(), '_')));
        }

        if(!isset($relationship_data['related_ids_attribute'])){
            $relationship_data['related_ids_attribute'] = $this->getDefaultFromRelatedModel($relationship_data, 'related_ids_attribute');
        }

        if(!isset($relationship_data['relation_name'])){
            $relationship_data['relation_name'] = $this->getDefaultFromRelatedModel($relationship_data, 'relation_name');
        }

        if(!isset($relationship_data['related_id_attribute_in_juncture_table'])){
            $relationship_data['related_id_attribute_in_juncture_table'] = $this->getDefaultFromRelatedModel($relationship_data, 'related_id_attribute_in_juncture_table');
        }

        if(!isset($relationship_data['owner_id_attribute_in_juncture_table'])){
            $relationship_data['owner_id_attribute_in_juncture_table'] = $this->owner->tableName().'_id';
        }

        // --- If they have additional juncture data attributes set then let's imlpement more defaults
        if(isset($relationship_data['additional_juncture_attributes'])){
            if(!isset($relationship_data['additional_juncture_data_prop'])){
                $relationship_data['additional_juncture_data_prop'] = $this->getDefaultFromRelatedModel($relationship_data, 'additional_juncture_data_prop');
            }
        }
    }

    /**
     * Tries to determine a default value for an attribute from the related model
     * @param array $relationship_data
     * @param string $relationship_property_name
     * @return string
     * @throws InvalidConfigException when a related model is not set for a default to be determined from, or the default property cannot be found
     */
    private function getDefaultFromRelatedModel($relationship_data, $relationship_property_name)
    {
        if(!isset($relationship_data['related_model'])){
            throw new InvalidConfigException('A `'.$relationship_property_name.'` is not set and there is not a `related_model` attribute set to try to determine a default from.');
        }

        if($relationship_property_name == 'related_ids_attribute'){
            $default_attribute_name = ($relationship_data['related_model']::tableName()).'_ids';
            $this->validateDefaultOnOwner($relationship_property_name, $default_attribute_name);
        }

        if($relationship_property_name == 'relation_name'){
            $default_attribute_name = lcfirst(Inflector::pluralize(Inflector::id2camel($relationship_data['related_model']::tableName(), '_')));
            $this->validateDefaultOnOwner($relationship_property_name, $default_attribute_name);
        }

        if($relationship_property_name == 'related_id_attribute_in_juncture_table'){
            $default_attribute_name = ($relationship_data['related_model']::tableName()).'_id';
            if(!$this->junctureModelCanGetProperty($relationship_data['juncture_relation_name'], $relationship_data['juncture_model'], $default_attribute_name)){
                throw new InvalidConfigException('A `'.$relationship_property_name.'` is not set and the default value `'.$default_attribute_name.'` is not valid');
            }
        }

        if($relationship_property_name == 'additional_juncture_data_prop'){
            $default_attribute_name = $relationship_data['related_model']::tableName().'s_data';
            $this->validateDefaultOnOwner($relationship_property_name, $default_attribute_name);
        }

        return $default_attribute_name;
    }

    /**
     * Validates that a default property exists on the owner model
     * @param string $relationship_property_name
     * @param string $default_attribute_name
     * @return bool
     * @throws InvalidConfigException when the property does not exist on the owner
     */
    private function validateDefaultOnOwner($relationship_property_name, $default_attribute_name)
    {
        if(!$this->owner->canGetProperty($default_attribute_name)){
            throw new InvalidConfigException('A `'.$relationship_property_name.'` is not set and the default value `'.$default_attribute_name.'` is not valid');
        }
        return true;
    }

    /**
     * Populates original data for juncture relationships so we can see what to add/delete/update after save
     * Due to the nature of this populating from each juncture relationship it is VERY WISE to join using 'with'
     * for all relations in the query to improve performance and avoid lazy loading
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

            if(isset($relationship_data['additional_juncture_data_prop'])){
                $relationship_data['original_data'] = [];
                foreach($this->owner->{$relationship_data['juncture_relation_name']} as $juncture_model){
                    $relationship_data['original_data'][$juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']}]  = $juncture_model;
                    $this->owner->{$relationship_data['additional_juncture_data_prop']}[$juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']}] = $juncture_model;
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
            if(isset($relationship_data['additional_juncture_data_prop'])){
                foreach($this->owner->{$relationship_data['additional_juncture_data_prop']} as $juncture_relationship_id => $additional_juncture_data_array){
                    $juncture_model = new $relationship_data['juncture_model'];
                    $juncture_model->attributes = $additional_juncture_data_array;
                    // --- Make sure to assign the id of this model
                    // --- The primary key returns an array so we may eventually need a todo to handle composite keys - but this is a juncture table so handling a composite key other model in a table with a composite key is complicated and hopefully is never run into
                    $juncture_model->{$relationship_data['owner_id_attribute_in_juncture_table']} = $this->owner->{$this->owner->primaryKey()[0]};
                    $this->owner->{$relationship_data['additional_juncture_data_prop']}[$juncture_relationship_id] = $juncture_model;
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

                    // --- Save the added IDs so if we do two saves in a row we don't try to add them twice
                    // --- This is used in the update when checking which ones to add
                    if(!isset($relationship_data['added_ids'])){
                        $relationship_data['added_ids'] = [];
                    }
                    $relationship_data['added_ids'][] = $related_id_to_add;
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
            // --- Have to check for this because even though we set in in afterFind() there might be some instances
            // --- where we create a model and update it and afterFind() never runs
            if(!isset($relationship_data['original_ids'])){
                $relationship_data['original_ids'] = [];
            }
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


            // --- Get existing ids which is the original ids on the model and any added ones if we have them
            $existing_ids = (isset($relationship_data['added_ids'])) ? 
                array_merge($relationship_data['added_ids'], $relationship_data['original_ids']) : 
                $relationship_data['original_ids'];

            // --- Find which ones to add by comparing existing ids to what we have now
            $related_ids_to_add = !is_array($this->owner->{$relationship_data['related_ids_attribute']}) ? 
                [] :
                array_diff($this->owner->{$relationship_data['related_ids_attribute']}, $existing_ids);

            foreach($related_ids_to_add as $related_id_to_add){
                $this->saveNewJunctureRelationship($relationship_data, $related_id_to_add);
            }

            // --- If there is additional data on the juncture relationship loop through them and check to see if we need to update anything
            if(isset($relationship_data['additional_juncture_data_prop'])){
                foreach($this->owner->{$relationship_data['additional_juncture_data_prop']} as $juncture_relationship_id => $juncture_relationship_model){
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
        $juncture_model = new $relationship_data['juncture_model'];
        $pkArray = $this->owner->primaryKey();
        $juncture_model->{$relationship_data['owner_id_attribute_in_juncture_table']} = $this->owner->{$pkArray[0]};
        $juncture_model->{$relationship_data['related_id_attribute_in_juncture_table']} = $related_id_to_add;

        // --- If we have additional attributes in the juncture relationship we want to get those and save them
        if(isset($relationship_data['additional_juncture_attributes'])){
            // --- Loop through the attributes and get the values from POST and assign them
            foreach($relationship_data['additional_juncture_attributes'] as $juncture_attribute_name){
                $juncture_model->{$juncture_attribute_name} = $this->owner->{$relationship_data['additional_juncture_data_prop']}[$related_id_to_add][$juncture_attribute_name];
            }
        }
        if(!$juncture_model->save()){
            // --- I would like to find a better way to handle an error on a juncture relationship with validation but for now I'm not sure how besides throwing this error since it's so far removed from the normal flow in this behavior
            Yii::error('There was a problem saving a '.get_class($juncture_model).' record for '.get_class($this->owner).' & '.$relationship_data['related_model']);
            throw new BadRequestHttpException('There was a problem creating a relationship: '.Html::errorSummary($juncture_model));
        }

        // --- 
        return true;
    }

    /**
     * Returns an instance of the juncture model for the relationship so we can determine
     * if a default setting for a property applies correctly to the juncture model
     * @param string $juncture_relation_name Relation name to check off the owner if a juncture model has a property
     * @param string $juncture_model_classname Name of the juncture model class to instantiate to check for property
     * @param string $property_name Name of the property we are checking for
     * @return mixed
     */
    private function junctureModelCanGetProperty($juncture_relation_name, $juncture_model_classname, $property_name)
    {
        if(!$this->owner->isNewRecord && !empty($this->owner->{$juncture_relation_name})){
            return $this->owner->{$juncture_relation_name}[0]->canGetProperty($property_name);
        } else if(empty($this->_juncture_model_for_validating[$juncture_model_classname])){
            $this->_juncture_model_for_validating[$juncture_model_classname] = new $juncture_model_classname;
        }
        return $this->_juncture_model_for_validating[$juncture_model_classname]->canGetProperty($property_name);
    }
}