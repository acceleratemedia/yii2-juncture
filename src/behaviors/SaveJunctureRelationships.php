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
 *       'class' => SaveJunctureRelationships::class,
 *       'relationships' => [
 *           [
 *               // --- Required fields
 *               'junctureModel' => CardBenefit::class, // --- Model representing the juncture table
 *
 *               // --- Optional but can be used to determine the rest of the optional fields
 *               'relatedModel' => Benefit::class, // --- Model of the related table
 *                 
 *               // --- Optional fields; defaults can be determined by from the owner, related, and juncture tables
 *               'relationName' => 'benefits', // --- Name of the AR relationship for the related model
 *               'relatedPksAttribute' => 'benefitIds', // -- Name of owner's attribute that holds PKs of related records
 *               'junctureRelationName' => 'cardBenefits', // --- Name of the AR relationship to the juncture model
 *               'relatedPksColumnInJunctureTable' => 'benefit_id', // --- Pk field(s) in juncture table for relation
 *               'ownerPkColumnInJunctureTable' => 'card_id' // --- Name of the owner's pk field(s) in the juncture table
 *
 *               // --- Optional fields if additional data needs to be saved in the juncture records
 *               'additionalJunctureDataProp' => 'benefitsData', // --- Name of the owner's attribute which holds additional data
 *               'additionalJunctureAttributes' => [ // --- Names of additional attributes with data that needs saving
 *                  'compares'
 *               ],
 *
 *               // --- Optional configuraiton for scenarios in which a relationship should be saved
 *               // --- If this is not set it will save in all scenarios
 *               'saveScenarios' => [
 *                   OwnerClass::SCENARIO_NAME
 *               ],
 *               // --- Optional configuraiton for scenarios in which a relationship should not be saved
 *               // --- This is useful if you want to save in all scenarios except for a couple
 *               // --- saveScenarios and doNotSaveScenarios should not be used at the same time
 *               'doNotSaveScenarios' => [
 *
 *               ],
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
     * Holds juncture models for validating attributes exist. Only used if the 
     * owner does not have a related model already attached to it. Key is string
     * classname of the juncture model we want for validating and value is the
     * class itself. Need to array it because we can have multiple relationships
     * on a single model and so might need multiple juncture models to validate
     * attributes exist
     * @var yii\db\ActiveRecord
     */
    private $_junctureMmodelForValidating;

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
            throw new InvalidConfigException('At least one relationship must be configred for SaveJunctureRelationships behavior');
        }
        $this->validateRelationsConfig();
    }

    /**
     * Validates the relationship data array
     */
    private function validateRelationsConfig()
    {
        // --- Loop through all set up relations and ensure they are correctly configured and set defaults
        foreach($this->relationships as &$relationshipData){
            // --- Check for required configuration
            if(!isset($relationshipData['junctureModel'])){
                throw new InvalidConfigException('The `junctureModel` key must be set in the relationship data');
            }
            if(isset($relationshipData['saveScenarios']) && isset($relationshipData['doNotSaveScenarios'])){
                throw new InvalidConfigException('`saveScenarios` and `doNotSaveScenarios` should not both be set for a relationship.');
            }
            $this->applyDefaults($relationshipData);
        }
    }

    /**
     * Applies some default values to the relationship data if those values are not set
     * @param array $relationshipData
     */
    private function applyDefaults(&$relationshipData)
    {
        if(!isset($relationshipData['junctureRelationName'])){
            $relationshipData['junctureRelationName'] = lcfirst(Inflector::pluralize(Inflector::id2camel($relationshipData['junctureModel']::tableName(), '_')));
            $this->validateDefaultOnOwner('junctureRelationName', $relationshipData['junctureRelationName'], $relationshipData['junctureModel']);
        }

        if(!isset($relationshipData['relatedPksAttribute'])){
            $relationshipData['relatedPksAttribute'] = $this->getDefaultFromRelatedModel($relationshipData, 'relatedPksAttribute');
        }

        if(!isset($relationshipData['relationName'])){
            $relationshipData['relationName'] = $this->getDefaultFromRelatedModel($relationshipData, 'relationName');
        }

        if(!isset($relationshipData['relatedPksColumnInJunctureTable'])){
            $relationshipData['relatedPksColumnInJunctureTable'] = $this->getDefaultFromRelatedModel($relationshipData, 'relatedPksColumnInJunctureTable');
            if(!$relationshipData['junctureModel']::instance()->canGetProperty($relationshipData['relatedPksColumnInJunctureTable'])){
                throw new InvalidConfigException('A `relatedPksColumnInJunctureTable` is not set on '.get_class($this->owner).' and the default value `'.$relationshipData['relatedPksColumnInJunctureTable'].'` is not valid for the relationship to '.$relationshipData['junctureModel']);
            }
        }

        if(!isset($relationshipData['ownerPkColumnInJunctureTable'])){
            $relationshipData['ownerPkColumnInJunctureTable'] = $this->owner->tableName().'_id';
            if(!$relationshipData['junctureModel']::instance()->canGetProperty($relationshipData['ownerPkColumnInJunctureTable'])){
                throw new InvalidConfigException('A `ownerPkColumnInJunctureTable` is not set on '.get_class($this->owner).' and the default value `'.$relationshipData['ownerPkColumnInJunctureTable'].'` is not valid for the relationship to '.$relationshipData['junctureModel']);
            }
        }

        // --- If they have additional juncture data attributes set then let's imlpement more defaults
        if(isset($relationshipData['additionalJunctureAttributes'])){
            if(!isset($relationshipData['additionalJunctureDataProp'])){
                $relationshipData['additionalJunctureDataProp'] = $this->getDefaultFromRelatedModel($relationshipData, 'additionalJunctureDataProp');
            }
        }

        if(!isset($relationshipData['saveScenarios'])){
            $relationshipData['saveScenarios'] = [];
        }

        if(!isset($relationshipData['doNotSaveScenarios'])){
            $relationshipData['doNotSaveScenarios'] = [];
        }
    }

    /**
     * Tries to determine a default value for an attribute from the related model
     * @param array $relationshipData
     * @param string $relationshipPropertyName
     * @return string
     * @throws InvalidConfigException when a related model is not set for a default to be determined from, or the default property cannot be found
     */
    private function getDefaultFromRelatedModel($relationshipData, $relationshipPropertyName)
    {
        if(!isset($relationshipData['relatedModel'])){
            throw new InvalidConfigException('A `'.$relationshipPropertyName.'` is not set and there is not a `relatedModel` attribute set to try to determine a default from.');
        }

        if($relationshipPropertyName == 'relatedPksAttribute'){
            $defaultAttributeName = lcfirst(Inflector::id2camel($relationshipData['relatedModel']::tableName(), '_')).'Ids';
            $this->validateDefaultOnOwner($relationshipPropertyName, $defaultAttributeName, $relationshipData['junctureModel']);
        }

        if($relationshipPropertyName == 'relationName'){
            $defaultAttributeName = lcfirst(Inflector::pluralize(Inflector::id2camel($relationshipData['relatedModel']::tableName(), '_')));
            $this->validateDefaultOnOwner($relationshipPropertyName, $defaultAttributeName, $relationshipData['junctureModel']);
        }

        if($relationshipPropertyName == 'relatedPksColumnInJunctureTable'){
            $defaultAttributeName = ($relationshipData['relatedModel']::tableName()).'_id';
            if(!$this->junctureModelCanGetProperty($relationshipData['junctureRelationName'], $relationshipData['junctureModel'], $defaultAttributeName)){
                throw new InvalidConfigException('A `'.$relationshipPropertyName.'` is not set on '.get_class($this->owner).' for the relation '.$relationshipData['junctureModel'].' and the default value `'.$defaultAttributeName.'` is not valid');
            }
        }

        if($relationshipPropertyName == 'additionalJunctureDataProp'){
            $defaultAttributeName = lcfirst(Inflector::id2camel($relationshipData['relatedModel']::tableName(), '_')).'sData';
            $this->validateDefaultOnOwner($relationshipPropertyName, $defaultAttributeName, $relationshipData['junctureModel']);
        }

        return $defaultAttributeName;
    }

    /**
     * Validates that a default property exists on the owner model
     * @param string $relationshipPropertyName
     * @param string $defaultAttributeName
     * @param string $junctureModel
     * @return bool
     * @throws InvalidConfigException when the property does not exist on the owner
     */
    private function validateDefaultOnOwner($relationshipPropertyName, $defaultAttributeName, $junctureModel)
    {
        if(!$this->owner->canGetProperty($defaultAttributeName)){
            throw new InvalidConfigException('A `'.$relationshipPropertyName.'` is not set on '.get_class($this->owner).' and the default value `'.$defaultAttributeName.'` is not valid for the relationship to '.$junctureModel);
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
        foreach($this->relationships as &$relationshipData){
            $relationshipData['originalPks'] = [];
            foreach($this->owner->{$relationshipData['junctureRelationName']} as $junctureModel){
                $relationshipData['originalPks'][]  = $junctureModel->{$relationshipData['relatedPksColumnInJunctureTable']};
                $this->owner->{$relationshipData['relatedPksAttribute']}[] = $junctureModel->{$relationshipData['relatedPksColumnInJunctureTable']};
            }

            if(isset($relationshipData['additionalJunctureDataProp'])){
                $relationshipData['originalData'] = [];
                foreach($this->owner->{$relationshipData['junctureRelationName']} as $junctureModel){
                    $relationshipData['originalData'][$junctureModel->{$relationshipData['relatedPksColumnInJunctureTable']}]  = $junctureModel;
                    $this->owner->{$relationshipData['additionalJunctureDataProp']}[$junctureModel->{$relationshipData['relatedPksColumnInJunctureTable']}] = $junctureModel;
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
     * A check is performed to only change the juncture data into an array if it is not already an
     * instance of the juncture model. In some instances additional juncture data may not be saved so the
     * property will not be posted in array format, and in that case we want to skip this part
     * @return void
     */
    public function beforeValidate()
    {
        foreach($this->relationships as &$relationshipData){
            if(isset($relationshipData['additionalJunctureDataProp'])){
                foreach($this->owner->{$relationshipData['additionalJunctureDataProp']} as $junctureRelationshipId => $additionalJunctureData){
                    if(is_array($additionalJunctureData)){
                        $junctureModel = new $relationshipData['junctureModel'];
                        $junctureModel->attributes = $additionalJunctureData;
                        // --- Make sure to assign the id of this model
                        // --- The primary key returns an array so we may eventually need a todo to handle composite keys - but this is a juncture table so handling a composite key other model in a table with a composite key is complicated and hopefully is never run into
                        if(is_array($relationshipData['ownerPkColumnInJunctureTable'])){
                            foreach($relationshipData['ownerPkColumnInJunctureTable'] as $ownerPkAttribute => $juncturePkAttribute){
                                $junctureModel->{$juncturePkAttribute} = $this->owner->{$ownerPkAttribute};
                            }
                        } else {
                            $junctureModel->{$relationshipData['ownerPkColumnInJunctureTable']} = $this->owner->{$this->owner->primaryKey()[0]};    
                        }
                        
                        $this->owner->{$relationshipData['additionalJunctureDataProp']}[$junctureRelationshipId] = $junctureModel;
                    }
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
        foreach($this->relationships as &$relationshipData){
            if(
                // --- Check scenarios
                $this->isSaveScenario($relationshipData) && 
                 // --- Forms may post empty values resulting in non-arrays so this check makes sure we have values to save
                is_array($this->owner->{$relationshipData['relatedPksAttribute']})
            ){
                // --- Loop through and insert the values
                foreach($this->owner->{$relationshipData['relatedPksAttribute']} as $relatedPkToAdd){
                    $this->saveNewJunctureRelationship($relationshipData, $relatedPkToAdd);

                    // --- Save the added IDs so if we do two saves in a row we don't try to add them twice
                    // --- This is used in the update when checking which ones to add
                    if(!isset($relationshipData['addedPks'])){
                        $relationshipData['addedPks'] = [];
                    }
                    $relationshipData['addedPks'][] = $relatedPkToAdd;
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
        foreach($this->relationships as &$relationshipData){
            if(!$this->isSaveScenario($relationshipData)){
                // --- Skip if we aren't in a saving scenario
                continue;
            }
            // --- Have to check for this because even though we set in in afterFind() there might be some instances
            // --- where we create a model and update it and afterFind() never runs
            if(!isset($relationshipData['originalPks'])){
                $relationshipData['originalPks'] = [];
            }
            // --- Find the difference between the beginning ids and the ending ids to see which ones to remove
            $relatedPksToRemove = !is_array($this->owner->{$relationshipData['relatedPksAttribute']}) ? 
                $relationshipData['originalPks'] :
                array_diff($relationshipData['originalPks'], $this->owner->{$relationshipData['relatedPksAttribute']});
            foreach($relatedPksToRemove as $relatedPkToRemove){
                // --- Loop through to delete and do use the models so any afterDelete actions are performed on them
                foreach($this->owner->{$relationshipData['junctureRelationName']} as $junctureModel){
                    if($junctureModel->{$relationshipData['relatedPksColumnInJunctureTable']} == $relatedPkToRemove){
                        $junctureModel->delete();
                    }
                }
            }                    


            // --- Get existing ids which is the original ids on the model and any added ones if we have them
            $existingPks = (isset($relationshipData['addedPks'])) ? 
                array_merge($relationshipData['addedPks'], $relationshipData['originalPks']) : 
                $relationshipData['originalPks'];

            // --- Find which ones to add by comparing existing ids to what we have now
            $relatedPksToAdd = !is_array($this->owner->{$relationshipData['relatedPksAttribute']}) ? 
                [] :
                array_diff($this->owner->{$relationshipData['relatedPksAttribute']}, $existingPks);

            foreach($relatedPksToAdd as $relatedPkToAdd){
                $this->saveNewJunctureRelationship($relationshipData, $relatedPkToAdd);
            }

            // --- If there is additional data on the juncture relationship loop through them and check to see if we need to update anything
            if(isset($relationshipData['additionalJunctureDataProp'])){
                foreach($this->owner->{$relationshipData['additionalJunctureDataProp']} as $junctureRelationshipId => $junctureRelationshipModel){
                    if(
                        !in_array($junctureRelationshipId, $relatedPksToAdd) &&
                        !in_array($junctureRelationshipId, $relatedPksToRemove)
                    ){
                        // --- If this was not an added or removed relationship then check with the original ones to see if it needs to be updated
                        // --- @todo This is not checking whether the attributes changed and saving based on that, it's just saving no matter what
                        // --- which is a performance issue. Need to do a better check
                        foreach($relationshipData['originalData'] as $originalJunctureRelatedPk => $originalJunctureRelationshipModel){
                            if($junctureRelationshipId == $originalJunctureRelatedPk){
                                // --- Assign attributes and make sure to include attribute that are
                                // --- safe to assign that are class properties but not database columns 
                                $attributesToAssign = $junctureRelationshipModel->attributes;
                                $originalJunctureRelationshipModel->attributes = $attributesToAssign;

                                $safeAttributes = $originalJunctureRelationshipModel->safeAttributes();
                                foreach($safeAttributes as $safeAttributeName){
                                    if(!array_key_exists($safeAttributeName, $attributesToAssign)){
                                        $originalJunctureRelationshipModel->{$safeAttributeName} = $junctureRelationshipModel->{$safeAttributeName};
                                    }
                                }

                                if(!$originalJunctureRelationshipModel->save()){
                                    throw new BadRequestHttpException('There was a problem updating a relationship: '.Html::errorSummary($originalJunctureRelationshipModel));
                                }
                            }
                        }
                    } 
                }
            }
        }
    }

    /**
     * @param array $relationshipData
     * @param int $relatedPkToAdd
     * @return bool
     */
    private function saveNewJunctureRelationship($relationshipData, $relatedPkToAdd)
    {
        $junctureModel = new $relationshipData['junctureModel'];
        $pkArray = $this->owner->primaryKey();

        if(is_array($relationshipData['ownerPkColumnInJunctureTable'])){
            foreach($relationshipData['ownerPkColumnInJunctureTable'] as $ownerPkAttribute => $juncturePkAttribute){
                $junctureModel->{$juncturePkAttribute} = $this->owner->{$ownerPkAttribute};
            }
        } else {
            $junctureModel->{$relationshipData['ownerPkColumnInJunctureTable']} = $this->owner->{$pkArray[0]};    
        }
        
        $junctureModel->{$relationshipData['relatedPksColumnInJunctureTable']} = $relatedPkToAdd;

        // --- Save additional juncture attributes if they have been set in config
        // --- Also perform a check to make that additional attribtues were set in
        // --- the data property. A juncture relationship could be saved without the
        // --- additional attributes present and in that case we don't want to try
        // --- to process them because it will theow an error
        if(
            isset($relationshipData['additionalJunctureAttributes']) &&
            !empty($this->owner->{$relationshipData['additionalJunctureDataProp']}) &&
            isset($this->owner->{$relationshipData['additionalJunctureDataProp']}[$relatedPkToAdd]) && 
            !empty($this->owner->{$relationshipData['additionalJunctureDataProp']}[$relatedPkToAdd])
        ){
            // --- Loop through the attributes and get the values from POST and assign them
            foreach($relationshipData['additionalJunctureAttributes'] as $junctureAttributeName){
                $junctureModel->{$junctureAttributeName} = $this->owner->{$relationshipData['additionalJunctureDataProp']}[$relatedPkToAdd][$junctureAttributeName];
            }
        }
        if(!$junctureModel->save()){
            // --- I would like to find a better way to handle an error on a juncture
            // --- relationship with validation but for now I'm not sure how besides
            // --- throwing this error since it's so far removed from the normal
            // --- flow in this behavior
            \Yii::error(print_r($junctureModel,true));
            throw new BadRequestHttpException('There was a problem creating a relationship: '.Html::errorSummary($junctureModel));
        }

        // --- 
        return true;
    }

    /**
     * Returns an instance of the juncture model for the relationship so we can determine
     * if a default setting for a property applies correctly to the juncture model
     * @param string $junctureRelationName Relation name to check off the owner if a juncture model has a property
     * @param string $junctureModelClass Name of the juncture model class to instantiate to check for property
     * @param string $propertyName Name of the property we are checking for
     * @return mixed
     */
    private function junctureModelCanGetProperty($junctureRelationName, $junctureModelClass, $propertyName)
    {
        if(!$this->owner->isNewRecord && !empty($this->owner->{$junctureRelationName})){
            return $this->owner->{$junctureRelationName}[0]->canGetProperty($propertyName);
        } else if(empty($this->_junctureMmodelForValidating[$junctureModelClass])){
            $this->_junctureMmodelForValidating[$junctureModelClass] = new $junctureModelClass;
        }
        return $this->_junctureMmodelForValidating[$junctureModelClass]->canGetProperty($propertyName);
    }

    /**
     * Whether or not relationship data should be saved
     * If no scenarios are specified in $relationshipData['saveScenarios'] and
     * $relationshipData['doNotSaveScenarios'] it will always be true
     * If $relationshipData['saveScenarios'] is not empty, it will only save if
     * the owner's scenario is in the array
     * If $relationshipData['doNotSaveScenarios'] is not empty, it will save by default
     * unless the owner's scenario is in the array
     * @param array $relationshipData
     * @return boolean
     */
    private function isSaveScenario($relationshipData)
    {
        return (empty($relationshipData['saveScenarios']) && empty($relationshipData['doNotSaveScenarios'])) ||
            !empty($relationshipData['saveScenarios']) && in_array($this->owner->getScenario(), $relationshipData['saveScenarios']) ||
            !empty($relationshipData['doNotSaveScenarios']) && !in_array($this->owner->getScenario(), $relationshipData['doNotSaveScenarios']);
    }
}