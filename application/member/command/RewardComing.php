<?php

namespace app\member\command;

use app\member\model\MemberModel;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 每日预览计算奖励(计划任务设置每日执行)
 *
 */

class RewardComing extends Command
{
    protected function configure()
    {
        $this->setName('RewardComing')->setDescription('每日预览计算奖励');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln("\r");
        $output->writeln(date("Y-m-d H:i:s")." ：每日预览计算奖励 Begin");
        (new MemberModel)->reward(false);
        $output->writeln("\r");
        $output->writeln(date("Y-m-d H:i:s")." ：每日预览计算奖励 End");
    }
}