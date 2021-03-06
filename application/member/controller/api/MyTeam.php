<?php

namespace app\member\controller\api;

use app\ApiController;

use app\distribution\model\DividendRoleModel;
use app\member\model\MemberModel;

/*------------------------------------------------------ */
//-- 我的团队相关API
/*------------------------------------------------------ */

class MyTeam extends ApiController
{
    /*------------------------------------------------------ */
    //-- 优先执行
    /*------------------------------------------------------ */
    public function initialize()
    {
        parent::initialize();
		$this->checkLogin();//验证登陆
        $this->Model = new MemberModel();
    }
    /*------------------------------------------------------ */
    //-- 获取列表
    /*------------------------------------------------------ */
     public function getList(){
        $where = ['user_id = ' . $this->userInfo['user_id']];
        $user_id = input('user_id','','trim');
        if (empty($user_id) == false) {
            $where = ['member_id = ' . $user_id . ' or tel = ' . $user_id . ' or idcard = ' . $user_id];
        }
        $this->sqlOrder = 'member_id DESC';
        $data = $this->getPageList($this->Model, join(' AND ', $where),'*',10);
        $roleList = (new DividendRoleModel)->getRows();
        if($data['list'])foreach ($data['list'] as $item){
            $item['rolename'] = $roleList[$item['role_id']]['role_name'];
            $item['comingrolename'] = $roleList[$item['coming_role_id']]['role_name'];
            $_list[] = $item;
        }
        $data['list'] = $_list;
        $return['list'] = $data['list'];
        $return['page_count'] = $data['page_count'];
        $return['code'] = 1;
        return $this->ajaxReturn($return);
    }
    /*------------------------------------------------------ */
	//-- 获取列表
	/*------------------------------------------------------
 	public function getList(){
        $where[] = ['pid','=',$this->userInfo['user_id']];
		$level = input('level',0,'intval');
		if ($level > 0){
        	$where[] = ['level','=',$level];
		}		
		$user_id = input('user_id','','trim');
		if (empty($user_id) == false){
		    if (is_numeric($user_id) == true){
                $where[] = ['user_id','=',$user_id];
            }else{
		        $uwhere[] = ['nick_name','like','%'.$user_id.'%'];
                $user_ids = (new UsersModel)->where($uwhere)->column('user_id');
                if (empty($user_ids)) $user_ids = 0;
                $where[] = ['user_id','in',$user_ids];
            }

		}
		
		$this->sqlOrder = 'user_id DESC';
        $data = $this->getPageList($this->Model, $where,'user_id',10);
		$UsersModel = new UsersModel();   
		$DividendRoleModel = new DividendRoleModel();
        foreach ($data['list'] as $key=>$user){
            $user = $UsersModel->info($user['user_id']);
			$_user['user_id'] = $user['user_id'];
			$_user['nick_name'] = $user['nick_name'];
			$_user['headimgurl'] = $user['headimgurl'];
			$_user['account'] = $user['account'];
			$_user['role_name'] = '';
			if ($user['role_id'] > 0){
				$_user['role_name'] = $DividendRoleModel->info($user['role_id'],true);
			}
            $return['list'][] = $_user;
        }
		//执行统计
		$is_stat = input('is_stat',0,'intval');
		if ($is_stat == 1){
			$return['stat'] = $UsersModel->userShareStats($this->userInfo['user_id']);
		}
		//end
        $return['page_count'] = $data['page_count'];
		$return['code'] = 1;
		return $this->ajaxReturn($return);
	}
	*/
}
