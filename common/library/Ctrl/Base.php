<?php

/**
 * @name IndexController
 * @author root
 * @desc 控制器基类
 * charset:utf-8
 */
abstract class Ctrl_Base extends Yaf_Controller_Abstract
{
    protected $layout = 'default';
    protected $_config;
    protected $session;

    public function init()
    {
        $this->session = Yaf_Session::getInstance();
        $this->getView()->assign('session', $this->session);
        $module = $this->getRequest()->getModuleName();
        $controller = $this->getRequest()->getControllerName();
        $action = $this->getRequest()->getActionName();
        $this->getView()->assign('module', $module);
        $this->getView()->assign('controller', $controller);
        $this->getView()->assign('action', $action);
        $current_ctrl = URL_ENTRANCE . '/' . $module . '/' . $controller;
        $this->getView()->assign('current_ctrl', $current_ctrl);
        $idprefix = $module . '-' . $controller . '-' . $action;
        $this->getView()->assign('idprefix', $idprefix);
        if (!Yaf_Dispatcher::getInstance()->getRequest()->isXmlHttpRequest()) {
            $this->getView()->setLayout($this->layout);
            $this->_config = Yaf_Application::app()->getConfig();
        } else {
            # ajax 请求，禁止自动渲染
            Yaf_Dispatcher::getInstance()->autoRender(false);
        }
    }

    /**
     * 设置网页SEO信息
     * @param array $pSeo [t,k,d]
     */
    public function seo($pSeo)
    {
        foreach (array('t', 'k', 'd') as $v1) {
            array_key_exists($v1, $pSeo) && $this->_view->assign('seo' . $v1, $pSeo[$v1]);
        }
    }

    /**
     * 注册变量到模板
     * @param str|array $pKey
     * @param mixed $pVal
     */
    public function assign($pKey, $pVal = '')
    {
        if (is_array($pKey)) {
            return $this->_view->assign($pKey);
        }
        $this->_view->assign($pKey, $pVal);
    }

    /**
     * 提示信息
     * @param string $pMsg
     * @param bool|string $pUrl
     */
    public function showMsg($pMsg, $pUrl = false)
    {
        header('Content-Type:text/html; charset=utf-8');
        is_array($pMsg) && $pMsg = join('\n', $pMsg);
        echo '<script type="text/javascript">';
        if ($pMsg) echo "alert('$pMsg')";
        if ($pUrl) echo "self.location = '{$pUrl}';";
        elseif (empty($_SERVER['HTTP_REFERER'])) echo 'window.history.back(-1);';
        else echo "self.location = '{$_SERVER['HTTP_REFERER']}';";
        exit('</script>');
    }

    /**
     * ajax 返回
     * @param string $pMsg 提示信息
     * @param int $pStatus 返回状态
     * @param mixed $pData 要返回的数据
     * @param string $pType ajax 返回类型(json|xml|eval)
     */
    public function ajax($pMsg = '', $pStatus = 0, $pData = '', $pType = 'json')
    {
        $ct = ($pType == 'json') ? 'application/json' : 'text/html';
        header("Content-Type:" . $ct . "; charset=utf-8");
        $tResult = array('status' => $pStatus, 'msg' => $pMsg, 'data' => $pData);
        'json' == $pType && exit(json_encode($tResult));
        'xml' == $pType && exit(xml_encode($tResult));
        'eval' == $pType && exit($tResult);
    }

}