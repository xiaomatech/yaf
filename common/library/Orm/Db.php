<?php

class Orm_Db
{
    public static function getInstance($tablename, $config = 'default')
    {
        $clsname = $tablename . 'Model';
        if (file_exists(PATH_APP . '/app/models/' . $tablename . '.php'))
            return new $clsname($config);
        if (file_exists(PATH_APP . '/app/models/' . strtr($tablename, array('_' => '/')) . '.php'))
            return new $clsname($config);
        return null;
    }
}
