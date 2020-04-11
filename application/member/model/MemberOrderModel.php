<?php

namespace app\member\model;

use app\BaseModel;

//*------------------------------------------------------ */
//-- 虚拟会员表
/*------------------------------------------------------ */

class MemberOrderModel extends BaseModel
{
    protected $table = 'member_order';
    protected $mkey = 'user_info_mkey_';
    public $pk = 'order_id';
}
