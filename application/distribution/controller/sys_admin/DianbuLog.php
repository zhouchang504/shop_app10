<?php
namespace app\distribution\controller\sys_admin;
use app\AdminController;

use app\member\model\AccountLogModel;
use app\member\model\MemberModel;

//*------------------------------------------------------ */
//-- 佣金明细
/*------------------------------------------------------ */
class DianbuLog extends AdminController{
	//*------------------------------------------------------ */
	//-- 初始化
	/*------------------------------------------------------ */
    public function initialize(){	
   		parent::initialize();
		$this->Model = new AccountLogModel();
    }
	
	//*------------------------------------------------------ */
	//-- 首页
	/*------------------------------------------------------ */
    public function index(){
		$this->assign("start_date", date('Y/m/01',strtotime("-1 months")));
		$this->assign("end_date",date('Y/m/d'));
		$this->getList(true);
        return $this->fetch('index');
    }
    /*------------------------------------------------------ */
    //-- 获取列表
    //-- $runData boolean 是否返回模板
    /*------------------------------------------------------ */
    public function getList($runData = false) {
        $nowDate =date("Y-m-d");
        $where = [];
        $where[] = ['change_type','eq',20];
        $search['keyword'] = input('keyword', '', 'trim');
        $search['searchKey'] = input('searchKey', '', 'trim');
        $MemberModel = new MemberModel();
        $uids = array();
        if (empty($search['keyword']) == false && $search['searchKey']) {
            if(in_array($search['searchKey'],['tel','username'])){
                $uids = $MemberModel->where("tel LIKE '%".$search['keyword']."%' OR username LIKE '%".$search['keyword']."%'")->column('member_id');
                $uids[] = -1;//增加这个为了以上查询为空时，限制本次主查询失效
            }else if(in_array($search['searchKey'],['pid','spid'])){
                $uids = $MemberModel->where("pid = '".$search['keyword']."' OR spid = '".$search['keyword']."'")->column('member_id');
                $uids[] = -1;//增加这个为了以上查询为空时，限制本次主查询失效
            }else{
                $where[] = [$search['searchKey'], 'like',"%".$search['keyword']."%"];
            }
        }
        $search['role_id'] = input('role_id', '', 'trim');
        if ($search['role_id'] || $search['role_id'] === '0'){
            $roleuids = $MemberModel->where("role_id = '".$search['role_id']."'")->column('member_id');
            $roleuids[] = -1;//增加这个为了以上查询为空时，限制本次主查询失效
            $uids = array_merge($uids,$roleuids);
        }
        if($uids)$where[] = ['member_id','in',$uids];
        $reportrange = input('reportrange');
        if (empty($reportrange) == false){
            $dtime = explode('-',$reportrange);
            $where[] = ['change_time','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
        }else{
            $where[] = ['change_time','between',[strtotime("-1 months"),time()]];
        }
        $this->data = $this->getPageList($this->Model, $where);
        if($this->data['list']){
            $_list = array();
            foreach ($this->data['list'] as $item){
                $member = $MemberModel->where('member_id',$item['member_id'])->find();
                $item['username'] = $member['username'];
                $item['tel'] = $member['tel'];
                $item['role_id'] = $member['role_id'];
                $item['pid'] = $member['pid'];
                $item['spid'] = $member['spid'];
                $_list[] = $item;
            }
            $this->data['list'] = $_list;
        }
        $this->assign("data", $this->data);
        if ($runData == false){
            $this->data['content'] = $this->fetch('list')->getContent();
            unset($this->data['list']);
            return $this->success('','',$this->data);
        }
        return true;
    }

}
