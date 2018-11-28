<?php

namespace apps\console\commands;

use mix\client\PDOPersistent;
use mix\console\ExitCode;
use mix\facades\Input;
use mix\facades\Output;

/**
 * 单进程范例
 * @author 刘健 <coder.liu@qq.com>
 */
class ClearCommand extends BaseCommand
{

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 获取程序名称
        $this->programName = Input::getCommandName();
    }

    // 执行任务
    public function actionExec()
    {
        // 预处理
        parent::actionExec();

        // 使用长连接客户端，这样会自动帮你维护连接不断线
        $pdo = PDOPersistent::newInstanceByConfig('libraries.[persistent.pdo]');

        // 执行业务代码
        // ...

        // 响应
        Output::writeln('SUCCESS');
        // 返回退出码
        return ExitCode::OK;
    }

}