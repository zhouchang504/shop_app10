<?php

namespace app\member\model;

use app\BaseModel;
use think\Db;

//*------------------------------------------------------ */
//-- 虚拟会员资金日志表
/*------------------------------------------------------ */

class MemberAccountLogModel extends BaseModel
{
    protected $table = 'member_account_log';
    public  $pk = 'log_id';
    /*------------------------------------------------------ */
    //--  生成校验码
    /*------------------------------------------------------ */
    protected  function toKey($data){
        return md5('#r8%'.join(',',$data));
    }
    /*------------------------------------------------------ */
    //-- 记录明细,更新用户帐户，更新用户信息
    //-- $data array 更新的字段数据
    /*------------------------------------------------------ */
    public function change($data){
        if ($data['member_id'] < 1) return false;
        $data['change_time'] = time();
        $data['change_ip'] = request()->ip();
        $data['sign'] = $this->toKey($data);
        $res = $this->create($data);
        if ($res->log_id < 1){
            return false;
        }
        return true;

    }
}
