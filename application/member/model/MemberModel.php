<?php

namespace app\member\model;

use app\BaseModel;

//*------------------------------------------------------ */
//-- 虚拟会员表
/*------------------------------------------------------ */

class MemberModel extends BaseModel
{
    protected $table = 'member';
    protected $mkey = 'user_info_mkey_';
    public $pk = 'member_id';
    /*------------------------------------------------------ */
    //-- 获取用户信息
    //-- val 查询值
    //-- type 查询类型
    //-- isCache 是否调用缓存
    /*------------------------------------------------------ */
    function info($val, $type = 'member_id', $isCache = true)
    {
        if (empty($val)) return false;
        if ($isCache == true) $info = Cache::get($this->mkey . $val);
        if (empty($info) == false) return $info;
        $info = $this->where('user_id', $val)->find();
        if (empty($info)){
            return [];
        }
        $info = $info->toArray();
        Cache::set($this->mkey . $val, $info, 30);
        return $info;
    }
}
