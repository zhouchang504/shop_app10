<?php

namespace app\member\command;

use app\member\model\MemberModel;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 每月市场津贴(已做防重复,计划任务设置每月1日执行)
 *
 */

class Reward extends Command
{
    protected function configure()
    {
        $this->setName('Reward')->setDescription('每月计算奖励');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln("\r");
        $output->writeln(date("Y-m-d H:i:s")." ：每月计算奖励 Begin");
        (new MemberModel)->reward();
        $output->writeln("\r");
        $output->writeln(date("Y-m-d H:i:s")." ：每月计算奖励 End");
    }
}