<?php
namespace bvb\juncture\widgets;

/**
 * Custom implementation of ActiveField so that we can assign a validation id
 * to separately validate the same field on different models on the same ui
 */
class ActiveField extends \yii\bootstrap4\ActiveField
{
    /**
     * {@inheritdoc}
     */
    protected function getClientOptions()
    {
        $client_options = parent::getClientOptions();
        if(isset($this->options['validation_id'])){
            $client_options['id'] = $this->options['validation_id'];
        }
        return $client_options;
    }
}
