<?php

class SampleModel extends Orm_Base
{
    public $tablename = 'Sample';
    public $pk = 'itemid';
    public $field = array(
        'itemid' => array('type' => 'int', 'comment' => '自增主键')
    );
}
