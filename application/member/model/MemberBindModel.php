<?php
namespace app\member\model;
use app\BaseModel;
//*------------------------------------------------------ */
//-- 会员关系表
/*------------------------------------------------------ */
class MemberBindModel extends BaseModel
{
	protected $table = 'member_bind';
    public $pk = 'bid';
    function a(){
        echo $this->where(join(' AND ', 1))->order('member_id DESC')->fetchSql()->select()->fetchSQL()->select();die;
    }
}
