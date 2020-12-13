<?php

namespace bvb\juncture\behaviors;

use Yii;
use yii\base\UnknownMethodException;
use yii\base\UnknownPropertyException;
use yii\db\BaseActiveRecord;
use yii\helpers\Inflector;

/**
 * SelfRelatedBehavior provides common functionality for coordinating records
 * which have a relation to objects in the same DB table. A juncture table is
 * created under which column 'a' and column 'b' are both ids of objects of the
 * same type that are related. The trick is that whether an object ends up in
 * column 'a' or 'b' it needs to show as related to the other object in both
 * directions not just objects in column a's related objects are listed in column
 * b.
 */
class SelfRelatedBehavior extends \yii\base\Behavior
{
    /**
     * Name of the field that identifies the taxonomy term in the juncture table
     * @var string
     */
    public $firstRelationIdColumn;

    /**
     * Name of the field that identifies the owner in the juncture table
     * @var string
     */
    public $secondRelationIdColumn;

    /**
     * Name of the ActiveRecord class for the model that will be created by
     * new records in the juncture table
     * @var string
     */
    public $selfRelatedJunctureModelClass;

    /**
     * The class that will be used in the query to get the self-related models
     * By default this will use the class of the owner model, but this may
     * be manually set in cases where the behavior is attached to a subclass of
     * the model it is originally assigned to and the original model is the
     * desired relation class. Or other contexts, but this is the one it came
     * up in.
     * @var string
     */
    public $selfRelationClass;

    /**
     * Array of names of different relations. Will probably only ever need one
     * but in some rare instances maybe objects can have multiple self-relations
     * based on certain conditions
     * @var array
     */
    public $selfRelations = [];

    /**
     * Name of a parameter to check for self-related parameters being sent via
     * in the application request (can be used for searching or updating models)
     * @var string
     */
    public $selfRelatedRequestParam;

    /**
     * Contains related object ids under the keys created in [[$selfRelations]]
     * ```
     * [
     *      'relatedArticles' => [1,3],
     *      'sisterArticles' => => [4,10]
     * ]
     * @var array
     */
    protected $_selfRelatedIds = [];

    /**
     * Original classifications to compare against with any updates to make
     * the appropriate DB queries to add/remove records in the same format as
     * [[$classificationTermIds]]
     * @var array
     */
    protected $_originalSelfRelatedIds = [];

    /**
     * Contains a map with keys being the values in [[$selfRelations]] and values
     * being the result of running [[self::getSelfRelatedIdsFieldName]] on it
     * @var array
     */
    protected $_relationNameToIdsNameMap = [];

    /**
     * Creates the necessary indexes
     * {@inheritdoc}
     */
    public function attach($owner){
        parent::attach($owner);

        foreach($this->selfRelations as $selfRelationName){
            $selfRelatedIdsFieldName = $this->getSelfRelatedIdsFieldName($selfRelationName);
            $this->_selfRelatedIds[$selfRelatedIdsFieldName] = [];
            $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName] = [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return [
            BaseActiveRecord::EVENT_AFTER_FIND => 'afterFind'
        ];
    }

    /**
     * Attempt to make magic relation names so users can use them in [[ActiveQuery::with]]
     * based on the keys from [[$selfRelations]]
     * {@inheritdoc}
     */
    public function __call($name, $params)
    {
        try {
            return parent::__call($name, $params);
        } catch( UnknownMethodException $e ){
            if($this->nameCouldBeForSelfRelation($name)){
                return $this->getSelfRelation($name);
            }
            throw $e;
        }
    }

    /**
     * If there is a request for a relation that is based on a configured self
     * relation then let's make a magic method for that
     * @return boolean
     */
    public function hasMethod($name, $checkBehaviors = true){
        if(parent::hasMethod($name, $checkBehaviors)){
            return true;
        }
        return $this->nameCouldBeForSelfRelation($name);
    }

    /**
     * Check whether the name for a function being called could be a getter
     * for a self relation
     * @return boolean
     */
    private function nameCouldBeForSelfRelation($name)
    {
        if(substr($name, 0, 3) === 'get'){
            $possibleRelationName = lcFirst(substr($name, 3));
            if(in_array($possibleRelationName, $this->selfRelations)){
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a relation like used in [[yii\db\ActiveRecord]] for objects of
     * the same type as the owner that are related via the juncture table
     * @param string $name
     * @return \yii\db\ActiveQuery
     */
    private function getSelfRelation($name)
    {
        $firstRelation = $this->owner->hasMany(
                    $this->getSelfRelationClass(),
                    ['id' => $this->getSecondRelationIdColumn()]
                )->viaTable(
                    $this->selfRelatedJunctureModelClass::instance()::tablename(),
                    [$this->getFirstRelationIdColumn() => 'id'],
                )->alias($name.'A');
        $secondRelation = $this->owner->hasMany(
                    $this->getSelfRelationClass(),
                    ['id' => $this->getFirstRelationIdColumn()]
                )->viaTable(
                    $this->selfRelatedJunctureModelClass::instance()::tablename(),
                    [$this->getSecondRelationIdColumn() => 'id'],
                )->alias($name.'B');
        return $secondRelation->union($firstRelation);
    }

    /**
     * Extend to allow for getting/setting of the ids of self relations to the
     * owner model. Will populate the relation for that ids field if it hasn't
     * already been done
     * {@inheritdoc}
     */
    public function __get($name)
    {
        try{
            return parent::__get($name);   
        } catch(UnknownPropertyException $e){
            if(isset($this->_selfRelatedIds[$name])){
                // --- We run into a huge potential issue here if we are using the 'ids'
                // --- term to populate an ActiveField but we didn't do the proper joins
                // --- so those values are populated properly on the model. This may not be
                // --- the best idea for performance but as a test right now we are going
                // --- to try to query for and auto-populate the relation and the ids 
                // --- pseudo field if it is called for
                // --- The conditional for isNewRecord was added because when using owner models
                // --- in search scenarios that the ensureRelationPopulated function would
                // --- set these termIds fields to empty after the search terms had been
                // --- loaded because there were not values in the db. This might not be
                // --- the best way to do this and maybe checking a scenario on the model would
                // --- be better but for now we will go with this and see if it works
                if(!$this->owner->isNewRecord){
                    $this->ensureRelationPopulated(null, $name);
                }
                return $this->_selfRelatedIds[$name];
            }

            if(in_array($name, $this->selfRelations)){
                return $this->getSelfRelation($name);
            }
            throw $e;
        }
    }

    /**
     * Take into account our self relations and ids fields
     * {@inheritdoc}
     */
    public function canGetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        return parent::canGetProperty($name, $checkVars) || 
            in_array($name, $this->selfRelations) ||
            isset($this->_selfRelatedIds[$name]);
    }

    /**
     * Extend the default functionality of the setter to set the self related ids
     * property which may submitted via forms or assigned in code
     * {@inheritdoc}
     */
    public function __set($name, $value)
    {
        try{
            parent::__set($name, $value);   
        } catch(UnknownPropertyException $e){
            if(isset($this->_selfRelatedIds[$name])){
                $this->_selfRelatedIds[$name] = $value;
            } else {
                throw $e;
            }
        }
    }

    /**
     * Extend the default functionality of the setter to set the pseudo property
     * holding the self-related ids
     * {@inheritdoc}
     */
    public function canSetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        return parent::canSetProperty($name, $checkVars) ||
            isset($this->_selfRelatedIds[$name]);
    }

    /**
     * Populates the data for the self-related ids into [[$_selfRelatedIds]] and
     * [[$_originalSelfRelatedIds]] if the relation is populated so we can safely
     * use the ids for comparison in case updates are being made
     * @return void
     */
    public function afterFind()
    {
        foreach($this->selfRelations as $selfRelationName){
            if($this->owner->isRelationPopulated($selfRelationName)){
                $selfRelatedIdsFieldName = $this->getSelfRelatedIdsFieldName($selfRelationName);
                $this->_selfRelatedIds[$selfRelatedIdsFieldName] = [];
                $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName] = [];
                foreach($this->owner->{$selfRelationName} as $relatedModel){
                    $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName][] = $relatedModel->id;
                    $this->_selfRelatedIds[$selfRelatedIdsFieldName][] = $relatedModel->id;    
                }
            }
        }
    }

    /**
     * Returns the name of the key that will hold the ids of the related objects
     * in [[$_selfRelatedIds]] for the relation with the specified name  
     * @return string
     */
    public function getSelfRelatedIdsFieldName($selfRelationName)
    {
        if(empty($this->_relationNameToIdsNameMap[$selfRelationName])){
            $this->_relationNameToIdsNameMap[$selfRelationName] = Inflector::singularize($selfRelationName).'Ids';
        }
        return $this->_relationNameToIdsNameMap[$selfRelationName];
    }

    /**
     * Ensures that a relation is populated on the owner for a self relation. This can
     * be important when saving data so we have the ids of assigned related objects
     * for comparing before/after and making additions/deletions as necessary
     * @param string $relationName
     * @param string $selfRelatedIdsFieldName
     * @return void
     */
    protected function ensureRelationPopulated($relationName = null, $selfRelatedIdsFieldName = null)
    {
        if(!$relationName){
            $relationName = array_search($selfRelatedIdsFieldName, $this->_relationNameToIdsNameMap);
        }
        if(!$this->owner->isRelationPopulated($relationName)){
            if(!$selfRelatedIdsFieldName){
                $selfRelatedIdsFieldName = $this->getSelfRelatedIdsFieldName($relationName);
            }
            $this->_selfRelatedIds[$selfRelatedIdsFieldName] = [];
            $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName] = [];
            $relatedModels = $this->owner->getRelation($relationName)->all();
            foreach($relatedModels as $relatedModel){
                $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName][] = $relatedModel->id;
                $this->_selfRelatedIds[$selfRelatedIdsFieldName][] = $relatedModel->id;    
            }
            $this->owner->populateRelation($relationName, $relatedModels);
        }
    }

    /**
     * Checks request params for field names which could be for ids of terms
     * for taxonomies on the owner model and applies them to [[$_selfRelatedIds]]
     * under the appropriate key so they can be retrieved via getter requests
     * on the owner model
     * @return void
     */
    public function loadSelfRelatedParams()
    {
        $ownerParams = $this->getOwnerRequestParams();
        if($ownerParams){
            foreach($this->selfRelations as $selfRelationName){
                $selfRelatedIdsFieldName = $this->getSelfRelatedIdsFieldName($selfRelationName);
                if(isset($ownerParams[$selfRelatedIdsFieldName])){
                    $this->_selfRelatedIds[$selfRelatedIdsFieldName] = $ownerParams[$selfRelatedIdsFieldName];
                }
            }            
        }
    }

    /**
     * Gets the request parameters that would apply to the owner model by checking
     * for the [[\yii\base\Model::formname()]] or for a specific [[$selfRelatedRequestParam]]
     * which can be set here or via the owner model
     * @return array|void
     */
    protected function getOwnerRequestParams()
    {
        $params = Yii::$app->request->bodyParams;
        if(empty($params)){
            // --- Making an attempt here to use GET request params as a fallback
            // --- since in some instances like searches that have those params
            // --- in the URL will want to get them this way, but most are
            // --- going to be POST params done for updating / saving
            $params = Yii::$app->request->get();
        }
        $ownerParams = null;
        if(isset($params[$this->owner->formName()])){
            $ownerParams = $params[$this->owner->formName()];
        } elseif(
            // --- Helps if a the model using this behavior is a subclass of the actual
            // --- model this behavior is attached to
            isset($this->owner->selfRelatedRequestParam) &&
            isset($params[$this->owner->selfRelatedRequestParam])
        ){
            $ownerParams = $params[$this->owner->selfRelatedRequestParam];
        }
        return $ownerParams;
    }

    /**
     * Getter for [[self::$selfRelationClass]] and sets a default value of the
     * owner class if not provided
     * @return string
     */
    public function getSelfRelationClass()
    {
        if($this->selfRelationClass === null){
            $this->selfRelationClass = get_class($this->owner);
        }
        return $this->selfRelationClass;
    }

    /**
     * Returns the id column for first relation (first,second not mattering
     * which is which really) with a default value of the table name of the
     * owner model followed by '_id_a'
     * @return string
     */
    public function getFirstRelationIdColumn()
    {
        if(empty($this->firstRelationIdColumn)){
            $this->firstRelationIdColumn = $this->owner->tableName().'_id_a';
        }
        return $this->firstRelationIdColumn;
    }

    /**
     * Returns the id column for first relation (first,second not mattering
     * which is which really) with a default value of the table name of the
     * owner model followed by '_id_b'
     * @return string
     */
    public function getSecondRelationIdColumn()
    {
        if(empty($this->secondRelationIdColumn)){
            $this->secondRelationIdColumn = $this->owner->tableName().'_id_b';
        }
        return $this->secondRelationIdColumn;
    }
}