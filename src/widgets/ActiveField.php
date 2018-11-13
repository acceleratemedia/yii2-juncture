<?php
namespace bvb\juncture\widgets;

/**
 * Widget uses the Select2 plugin to implement a UI for juncture relationships
 */
class ActiveField extends \yii\widgets\ActiveField
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
