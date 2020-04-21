<?php

namespace app\present\controller\sys_admin;

use think\Db;
use app\AdminController;
use app\member\model\MemberModel;
use app\distribution\model\DividendRoleModel;

/**
 * 报单虚拟会员
 * Class Index
 * @package app\store\controller
 */
class Users extends AdminController
{
    //*------------------------------------------------------ */
    //-- 初始化
    /*------------------------------------------------------ */
    public function initialize($isretrun = true)
    {
        parent::initialize();
        $this->Model = new MemberModel();
        $this->roleList = (new DividendRoleModel)->getRows();
        $this->assign("roleList", $this->roleList);
    }
    //*------------------------------------------------------ */
    //-- 首页
    /*------------------------------------------------------ */
    public function index()
    {
        $reportrange = input('reportrange', '', 'trim');
        if (empty($reportrange) == false) {
            $reportrange = str_replace('_', '/', $reportrange);
            $dtime = explode('-', $reportrange);
            $this->assign("start_date", $dtime[0]);
            $this->assign("end_date", $dtime[1]);
        } else {
            $this->assign("start_date", date('Y/m/01', strtotime("-1 months")));
            $this->assign("end_date", date('Y/m/d'));
        }
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
        $search['keyword'] = input('keyword', '', 'trim');
        $search['searchKey'] = input('searchKey', '', 'trim');

        if (empty($search['keyword']) == false && $search['searchKey']) {
            $where[] = [$search['searchKey'], 'like',"%".$search['keyword']."%"];
        }
        $search['role_id'] = input('role_id', '', 'trim');
        if ($search['role_id']) $where[] = ['role_id','=',$search['role_id']];
        
        $reportrange = input('reportrange');
        if (empty($reportrange) == false){
            $dtime = explode('-',$reportrange);
            $where[] = ['regtime','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
        }else{
            $where[] = ['regtime','between',[strtotime("-1 months"),time()]];
        }
        $this->data = $this->getPageList($this->Model, $where);
        foreach ($this->data['list'] as $key=>$row){
        }
        $this->assign("data", $this->data);
        if ($runData == false){
            $this->data['content'] = $this->fetch('list')->getContent();
            unset($this->data['list']);
            return $this->success('','',$this->data);
        }
        return true;
    }
    /*------------------------------------------------------ */
    //-- 信息页调用
    //-- $data array 自动读取对应的数据
    /*------------------------------------------------------ */
    public function asInfo($data)
    {
        return $data;
    }
    /*------------------------------------------------------ */
    //-- 添加前调用
    /*------------------------------------------------------ */
    public function beforeAdd($row)
    {
        Db::startTrans();//启动事务
        $row['regtime'] = time();
        return $row;
    }
    /*------------------------------------------------------ */
    //-- 添加后调用
    /*------------------------------------------------------ */
    public function afterAdd($row)
    {
        $inData['member_id'] = $row['member_id'];
        Db::commit();// 提交事务
        return $this->success('添加成功', url('index'));
    }
    /*------------------------------------------------------ */
    //-- 修改前处理
    /*------------------------------------------------------ */
    public function beforeEdit($row)
    {
        # 修改推荐上级 是否头咬尾
        # 修改服务上级 是否头咬尾

        Db::startTrans();//启动事务
        return $row;
    }
    /*------------------------------------------------------ */
    //-- 修改后调用
    /*------------------------------------------------------ */
    public function afterEdit($row)
    {
        Db::commit();// 提交事务
        return $this->success('修改成功', url('index'));
    }
}