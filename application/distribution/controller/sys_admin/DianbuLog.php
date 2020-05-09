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
        $keyword = input('keyword', '', 'trim');
        if($keyword)$where[] = ['user_id','eq',$keyword];
        $reportrange = input('reportrange');
        if (empty($reportrange) == false){
            $dtime = explode('-',$reportrange);
            $where[] = ['change_time','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
        }else{
            $where[] = ['change_time','between',[strtotime("-1 months"),time()]];
        }
        $this->data = $this->getPageList($this->Model, $where);
        $this->assign("data", $this->data);
        if ($runData == false){
            $this->data['content'] = $this->fetch('list')->getContent();
            unset($this->data['list']);
            return $this->success('','',$this->data);
        }
        return true;
    }

}
