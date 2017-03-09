<?php
/**
 * charset:utf-8
 * Yaf 入口
 */
# 网站基础 URL，适配根目录或子目录
define('URL_BASE', strtr($_SERVER['SCRIPT_NAME'], array("\\" => '/', 'index.php' => '')));
# 网站入口 URL，适配是否开启伪静态
define('URL_ENTRANCE', rtrim(URL_BASE . 'index.php', '/'));
# 网站入口所在的根目录，适配生成路径
define('PATH_BASE', dirname(__FILE__));
# 网站应用所有的目录，适配配置文件
define('PATH_APP', dirname(dirname(__FILE__)));
# 目录分割符
define("DS", '/');
define('UPLOAD_PATH', PATH_BASE + '../uploads');
define('LOGS_PATH', PATH_BASE + '../logs');


//error_reporting(E_ALL);
$env = 'development';
#$env = 'product';

$app = new Yaf_Application(PATH_APP . "/conf/app.ini");
$app->bootstrap()->run();
