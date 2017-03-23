<?php
namespace Server\CoreBase;

use Monolog\Logger;
use Server\SwooleMarco;
use Server\SwooleServer;
use Server\Cache\ICache;

/**
 * Controller 控制器
 * 对象池模式，实例会被反复使用，成员变量缓存数据记得在销毁时清理
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 16-7-15
 * Time: 上午11:59
 */
class Controller extends CoreBase
{
    /**
     * @var HttpInPut
     */
    public $http_input;
    /**
     * @var HttpOutPut
     */
    public $http_output;
    /**
     * 是否来自http的请求不是就是来自tcp
     * @var string
     */
    public $request_type;
    /**
     * fd
     * @var int
     */
    protected $fd;
    /**
     * uid
     * @var int
     */
    protected $uid;
    /**
     * 用户数据
     * @var
     */
    protected $client_data;
    /**
     * http response
     * @var \swoole_http_request
     */
    protected $request;
    /**
     * http response
     * @var \swoole_http_response
     */
    protected $response;

    /**
     * 用于单元测试模拟捕获服务器发出的消息
     * @var array
     */
    protected $testUnitSendStack = [];

    /**
     * 判断是不是RPC
     * @var bool
     */
    protected $isRPC;

    /**
     * rpc的token
     * @var string
     */
    protected $rpc_token;

    /**
     * @var string
     */
    protected $rpc_request_id;
    /**
     * 缓存
     * @var ICache
     */
    protected $cache;
    /**
     * session handler
     * @var ICache
     */
    public $session_handler;

    /**
     * Controller constructor.
     */
    final public function __construct()
    {
        parent::__construct();
        $this->http_input = new HttpInput();
        $this->http_output = new HttpOutput($this);
        $this->cache = get_instance()->cache;
        $this->session_handler = get_instance()->session_handler;
    }

    /**
     * 来自Tcp
     * 设置客户端协议数据
     * @param $uid
     * @param $fd
     * @param $client_data
     * @param $controller_name
     * @param $method_name
     */
    public function setClientData($uid, $fd, $client_data, $controller_name, $method_name)
    {
        $this->uid = $uid;
        $this->fd = $fd;
        $this->client_data = $client_data;
        if (isset($client_data->rpc_request_id)) {
            $this->isRPC = true;
            $this->rpc_token = $client_data->rpc_token??'';
            $this->rpc_request_id = $client_data->rpc_request_id??'';
        } else {
            $this->isRPC = false;
        }
        $this->request_type = SwooleMarco::TCP_REQUEST;
        $this->initialization($controller_name, $method_name);
    }

    /**
     * 初始化每次执行方法之前都会执行initialization
     * @param string $controller_name 准备执行的controller名称
     * @param string $method_name 准备执行的method名称
     * @throws \Exception
     */
    protected function initialization($controller_name, $method_name)
    {
        if ($this->isRPC && !empty($this->rpc_request_id)) {
            //全链路监控保证调用的request_id唯一
            $context = ['request_id' => $this->rpc_request_id];
        } else {
            $context = ['request_id' => time() . crc32($controller_name . $method_name . getTickTime() . rand(1, 10000000))];
        }
        $this->setContext($context);
    }

    /**
     * 来自Http
     * set http Request Response
     * @param $request
     * @param $response
     * @param $controller_name
     * @param $method_name
     */
    public function setRequestResponse($request, $response, $controller_name, $method_name)
    {
        $this->request = $request;
        $this->response = $response;
        $this->http_input->set($request);
        $this->http_output->set($request, $response);
        $this->rpc_request_id = $this->http_input->header('rpc_request_id');
        $this->isRPC = empty($this->rpc_request_id) ? false : true;
        $this->request_type = SwooleMarco::HTTP_REQUEST;
        $this->initialization($controller_name, $method_name);
    }

    /**
     * 异常的回调
     * @param \Exception $e
     */
    public function onExceptionHandle(\Exception $e)
    {
        $this->log($e->getMessage() . "\n" . $e->getTraceAsString(), Logger::ERROR);
        if ($e instanceof SwooleException) {
            if ($e->others != null) {
                $this->log($e->others, Logger::NOTICE);
            }
        }
        $msg = $this->config->get('server.debug') ? $e->getMessage() : 'error';
        switch ($this->request_type) {
            case SwooleMarco::HTTP_REQUEST:
                $this->http_output->end($msg);
                break;
            case SwooleMarco::TCP_REQUEST:
                $this->send($msg);
                break;
        }
    }

    /**
     * 向当前客户端发送消息
     * @param $data
     * @param $destroy
     * @throws SwooleException
     */
    protected function send($data, $destroy = true)
    {
        if ($this->is_destroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if ($this->isRPC && !empty($this->rpc_token)) {
            $rpc_data['rpc_token'] = $this->rpc_token;
            $rpc_data['rpc_result'] = $data;
            $data = $rpc_data;
        }
        $data = get_instance()->encode($this->pack->pack($data));
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'send', 'fd' => $this->fd, 'data' => $data];
        } else {
            get_instance()->send($this->fd, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * 销毁
     */
    public function destroy()
    {
        parent::destroy();
        unset($this->fd);
        unset($this->uid);
        unset($this->client_data);
        unset($this->request);
        unset($this->response);
        $this->http_input->reset();
        $this->http_output->reset();
        ControllerFactory::getInstance()->revertController($this);
    }

    /**
     * 获取单元测试捕获的数据
     * @return array
     */
    public function getTestUnitResult()
    {
        $stack = $this->testUnitSendStack;
        $this->testUnitSendStack = [];
        return $stack;
    }

    /**
     * 当控制器方法不存在的时候的默认方法
     */
    public function defaultMethod()
    {
        if ($this->request_type == SwooleMarco::HTTP_REQUEST) {
            $this->http_output->setHeader('HTTP/1.1', '404 Not Found');
            $template = $this->loader->view('server::error_404');
            $this->http_output->end($template->render());
        } else {
            throw new SwooleException('method not exist');
        }
    }

    /**
     * sendToUid
     * @param $uid
     * @param $data
     * @throws SwooleException
     */
    protected function sendToUid($uid, $data, $destroy = true)
    {
        if ($this->is_destroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToUid', 'uid' => $this->uid, 'data' => $data];
        } else {
            get_instance()->sendToUid($uid, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * sendToUids
     * @param $uids
     * @param $data
     * @param $destroy
     * @throws SwooleException
     */
    protected function sendToUids($uids, $data, $destroy = true)
    {
        if ($this->is_destroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToUids', 'uids' => $uids, 'data' => $data];
        } else {
            get_instance()->sendToUids($uids, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * sendToAll
     * @param $data
     * @param $destroy
     * @throws SwooleException
     */
    protected function sendToAll($data, $destroy = true)
    {
        if ($this->is_destroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToAll', 'data' => $data];
        } else {
            get_instance()->sendToAll($data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * 发送给群
     * @param $groupId
     * @param $data
     * @param bool $destroy
     * @throws SwooleException
     */
    protected function sendToGroup($groupId, $data, $destroy = true)
    {
        if ($this->is_destroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToGroup', 'groupId' => $groupId, 'data' => $data];
        } else {
            get_instance()->sendToGroup($groupId, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * 踢用户
     * @param $uid
     */
    protected function kickUid($uid)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'kickUid', 'uid' => $uid];
        } else {
            get_instance()->kickUid($uid);
        }
    }

    /**
     * bindUid
     * @param $fd
     * @param $uid
     * @param bool $isKick
     */
    protected function bindUid($fd, $uid, $isKick = true)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'bindUid', 'fd' => $fd, 'uid' => $uid];
        } else {
            get_instance()->bindUid($fd, $uid, $isKick);
        }
    }

    /**
     * unBindUid
     * @param $uid
     */
    protected function unBindUid($uid)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'unBindUid', 'uid' => $uid];
        } else {
            get_instance()->unBindUid($uid);
        }
    }

    /**
     * 断开链接
     * @param $fd
     * @param bool $autoDestroy
     */
    protected function close($fd, $autoDestroy = true)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'close', 'fd' => $fd];
        } else {
            get_instance()->close($fd);
        }
        if ($autoDestroy) {
            $this->destroy();
        }
    }

    /**
     * @param $uid
     * @param $groupID
     */
    protected function addToGroup($uid, $groupID)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'addToGroup', '$uid' => $uid, 'groupId' => $groupID];
        } else {
            get_instance()->addToGroup($uid, $groupID);
        }
    }

    /**
     * @param $uid
     * @param $groupID
     */
    protected function removeFromGroup($uid, $groupID)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'removeFromGroup', '$uid' => $uid, 'groupId' => $groupID];
        } else {
            get_instance()->removeFromGroup($uid, $groupID);
        }
    }
}