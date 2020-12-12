<?php

namespace bvb\juncture\behaviors;

use Yii;
use yii\db\BaseActiveRecord;
use yii\web\ServerErrorHttpException;

/**
 * This extends the self related behavior with functionality needed for
 * sving the related models
 */
class SaveSelfRelatedBehavior extends \bvb\juncture\behaviors\SelfRelatedBehavior
{
    /**
     * Whether to throw an error if a juncture model doesn't save
     * @var boolean
     */
    public $throwErrorOnFailedSave = true;

    /**
     * The name of the [[self::getSelfRelatedIdsFieldName()]] pseudo fields
     * that we will save relations for
     * @var boolean
     */
    protected $_selfRelatedIdsFieldsToSave = [];

    /**
     * {@inheritdoc}
     */
    public function events()
    {
        return array_merge(parent::events(), [
            BaseActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
        ]);
    }

    /**
     * Loads params manually so we don't have to set the variables that keys
     * in the [[$classifications]] and [[$_selfRelatedIds]] as 'safe'
     * in the models to have the params properly load
     * If the owner class declares an [[]] variable and
     * @return void
     */
    public function beforeValidate()
    {
        $ownerParams = $this->getOwnerRequestParams();   
        if($ownerParams){
            foreach($this->_selfRelatedIds as $selfRelatedIdsFieldName => $selfRelatedIds){
                if(isset($ownerParams[$selfRelatedIdsFieldName])){
                    // --- Ensure that the relation is populated so we know that 
                    // --- the original values are populated as we set the new ones
                    $this->ensureRelationPopulated(null, $selfRelatedIdsFieldName);
                    $this->_selfRelatedIds[$selfRelatedIdsFieldName] = $ownerParams[$selfRelatedIdsFieldName];
                    $this->_selfRelatedIdsFieldsToSave[] = $selfRelatedIdsFieldName;
                } else {
                    // --- Not set on owner probably means we shouldn't try to save this one because
                    // --- it might be accidentally deleting them all
                }
            }
        }
    }

    /**
     * Save the related taxonomy items based on the differences in IDs
     * @return void
     */
    public function afterSave()
    {
        $idsToDeleteByRelation = $idsToAddByRelation = [];
        foreach($this->selfRelations as $selfRelationName){
            $selfRelatedIdsFieldName = $this->getSelfRelatedIdsFieldName($selfRelationName);
            if(!in_array($selfRelatedIdsFieldName, $this->_selfRelatedIdsFieldsToSave)){
                // --- If a taxonomy is not set to save do not run save/delete
                continue;
            }
            $this->ensureRelationPopulated($selfRelationName);
            if(is_array($this->_selfRelatedIds[$selfRelatedIdsFieldName])){
                $idsToDeleteByRelation[$selfRelationName] = array_diff(
                    $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName],
                    $this->_selfRelatedIds[$selfRelatedIdsFieldName]
                );
                $idsToAddByRelation[$selfRelationName] = array_diff(
                    $this->_selfRelatedIds[$selfRelatedIdsFieldName],
                    $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName]
                );
            } else {
                $idsToDeleteByRelation[$selfRelationName] = $this->_originalSelfRelatedIds[$selfRelatedIdsFieldName];
            }
        }

        foreach($idsToDeleteByRelation as $selfRelationName => $selfRelatedIdsToDelete){
            if(!empty($selfRelatedIdsToDelete)){
                Yii::$app->db->createCommand()
                    ->delete(
                        $this->selfRelatedJunctureModelClass::instance()::tableName(),
                        [
                            'OR',
                            [
                                'AND',
                                [$this->getFirstRelationIdColumn() => $this->owner->id],
                                ['IN', $this->getSecondRelationIdColumn(), $selfRelatedIdsToDelete],
                            ],
                            [
                                'AND',
                                [$this->getSecondRelationIdColumn() => $this->owner->id],
                                ['IN', $this->getFirstRelationIdColumn(), $selfRelatedIdsToDelete],
                            ],
                        ]
                    )
                    ->execute();
            }
        }
        foreach($idsToAddByRelation as $selfRelationName => $selfRelatedIdsToAdd){
            foreach($selfRelatedIdsToAdd as $selfRelatedIdToAdd){
                $junctureModel = new $this->selfRelatedJunctureModelClass;
                $junctureModel->{$this->getFirstRelationIdColumn()} = $selfRelatedIdToAdd;
                $junctureModel->{$this->getSecondRelationIdColumn()} = $this->owner->id;
                if(!$junctureModel->save()){
                    $this->owner->addError('*', 'A self-related object could not be added: '.$selfRelatedIdToAdd);
                    if($this->throwErrorOnFailedSave){
                        throw new ServerErrorHttpException('A self-related juncture model could not save: '.print_r($junctureModel,true));
                    } else {
                        Yii::error('Error saving juncture model: '.print_r($junctureModel,true));
                    }
                }
            }
        }
    }
}