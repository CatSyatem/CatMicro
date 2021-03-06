<?php
/**
 * Created by PhpStorm.
 * User: lidanyang
 * Date: 16/4/8
 * Time: 下午5:43
 */

namespace base\server;

use core\common\Globals;
use core\component\cache\CacheLoader;
use core\component\config\Config;
use base\port\PortFactory;
use core\component\task\TaskRoute;

abstract class BaseCallback
{
    /**
     * @var \swoole_server
     */
    protected $server;

    protected $project_name;

    protected $pid_path;

    public function __construct()
    {
        $this->project_name = Config::getField('project', 'project_name');
        $this->pid_path   = Config::getField('project', 'pid_path');
    }

    public function onStart($server)
    {
        Globals::setProcessName($this->project_name . " server running master:" . $server->master_pid);
        if (!empty($this->pid_path)) {
            file_put_contents($this->pid_path . DIRECTORY_SEPARATOR . $this->project_name . '_master.pid', $server->master_pid);
        }
    }

    /**
     * @throws \Exception
     */
    public function onShutDown()
    {
        if (!empty($this->pid_path)) {
            $filename = $this->pid_path . DIRECTORY_SEPARATOR . $this->project_name . '_master.pid';
            if (is_file($filename)) {
                unlink($filename);
            }
            $filename = $this->pid_path . DIRECTORY_SEPARATOR . $this->project_name . '_manager.pid';
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    /**
     * @param $server
     * @throws \Exception
     * @desc 服务启动，设置进程名
     */
    public function onManagerStart($server)
    {
        Globals::setProcessName($this->project_name .' server manager:' . $server->manager_pid);
        if (!empty($this->pid_path)) {
            file_put_contents($this->pid_path . DIRECTORY_SEPARATOR . $this->project_name . '_manager.pid', $server->manager_pid);
        }
    }

    public function onManagerStop()
    {
        if (!empty($this->pid_path)) {
            $filename = $this->pid_path . DIRECTORY_SEPARATOR . $this->project_name . '_manager.pid';
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    public function doWorkerStart($server, $workerId)
    {
        $workNum = Config::getField('base', 'worker_num');
        if ($workerId >= $workNum) {
            Globals::setProcessName($this->project_name . " server tasker  num: ".($server->worker_id - $workNum)." pid " . $server->worker_pid);
        } else {
            Globals::setProcessName($this->project_name . " server worker  num: {$server->worker_id} pid " . $server->worker_pid);
        }
        Globals::$server = $server;

        $this->onWorkerStart($server, $workerId);
    }

    public function setServer(\swoole_server $server)
    {
        $this->server = $server;
    }

    /**
     * @return \swoole_server
     */
    public function getServer()
    {
        return $this->server;
    }

    public function onTask(\swoole_server $server, $task_id, $from_id, $data)
    {
        $task_config = Config::getField('component', 'task');
        TaskRoute::onTask($task_config['task_path'], $data);
    }

    public function onFinish(\swoole_server $serv, $task_id, $data)
    {
        TaskRoute::onFinish();
    }

    public function onPipeMessage(\swoole_server $server, $from_worker_id, $message)
    {
        CacheLoader::onPipeMessage($message);
    }

    public function _beforeStart()
    {
        $service_list = Config::get('service');
        foreach ($service_list as $service)
        {
            $switch = 'open_' . $service['port_type'];

            if( !Config::get($switch, false) ) {
                continue;
            }
            $port = PortFactory::getInstance($service['port_type']);
            $port->init($this->server, $service);
            $port->run();
        }

        $this->beforeStart();
    }

    /**
     * 打开内存Cache进程
     * @param $init_callback callable 回调函数, 执行进程的初始化代码
     */
    protected function openCacheProcess($init_callback)
    {
        $process = CacheLoader::openCacheProcess($init_callback);
        if( empty($process) )
        {
            return;
        }
        $this->server->addProcess($process);
    }

    /**
     * 服务启动前执行该回调, 用于添加额外监听端口, 添加额外Process
     */
    abstract public function beforeStart();

    /**
     * Admin 管理接口, 可自定义管理接口行为
     * @param \swoole_http_request $request
     * @param \swoole_http_response $response
     */
    abstract public function onRequest(\swoole_http_request $request, \swoole_http_response $response);


    /**
     * 进程初始化回调, 用于初始化全局变量
     * @param \swoole_websocket_server $server
     * @param $workerId
     */
    abstract public function onWorkerStart($server, $workerId);


    /**
     * WebSocket Receive
     * @param \swoole_websocket_server $server
     * @param \swoole_websocket_frame $frame
     */
    public function onMessage(\swoole_websocket_server $server, \swoole_websocket_frame $frame)
    {
        // DO NOTHING
    }

}
