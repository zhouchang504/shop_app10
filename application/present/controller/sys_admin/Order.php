<?php

namespace app\present\controller\sys_admin;

use app\member\model\AccountLogModel;
use app\member\model\AccountModel;
use app\member\model\MemberOrderModel;
use think\Db;
use app\AdminController;
use app\member\model\MemberModel;
use app\distribution\model\DividendRoleModel;

/**
 * 报单
 * Class Index
 * @package app\store\controller
 */
class Order extends AdminController
{
    //*------------------------------------------------------ */
    //-- 初始化
    /*------------------------------------------------------ */
    public function initialize($isretrun = true)
    {
        parent::initialize();
        $this->Model = new MemberOrderModel();
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
            $this->assign("start_date", date('Y/m/01', strtotime("-6 months")));
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
        $where = [];
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
            }else if(in_array($search['searchKey'],['puser_id'])){
                $son_uids = [$search['keyword']];
                $i = 0;
                do{
                    $i++;
                    $uids = array_merge($uids,$son_uids);
                    $son_uids = $MemberModel->where("spid" ,'in' ,$son_uids)->column('member_id');
                }while(!empty($son_uids) && $i < 100);
                $uids[] = -1;//增加这个为了以上查询为空时，限制本次主查询失效
            }else{
                $where[] = [$search['searchKey'], 'eq',"".$search['keyword'].""];
            }
        }
        $search['role_id'] = input('role_id', '', 'trim');
        if ($search['role_id'] || $search['role_id'] === '0'){
            $roleuids = $MemberModel->where("role_id = '".$search['role_id']."'")->column('member_id');
            $roleuids[] = -1;//增加这个为了以上查询为空时，限制本次主查询失效
            $uids = array_merge($uids,$roleuids);
        }
        $search['status'] = input('status', '', 'trim');
        if ($search['status']){
            $where[] = ['status', 'eq',"".$search['status'].""];
        }
        if($uids)$where[] = ['member_id','in',$uids];
        $reportrange = input('reportrange');
        if (empty($reportrange) == false){
            $dtime = explode('-',$reportrange);
            $where[] = ['createtime','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
        }else{
            $where[] = ['createtime','between',[strtotime("-6 months"),time()]];
        }
        $this->data = $this->getPageList($this->Model, $where);
        $this->data['order_amount'] = $this->Model->where($where)->sum('order_amount');
        if($this->data['list']){
            $_list = array();
            foreach ($this->data['list'] as $item){
                $member = $MemberModel->where('member_id',$item['member_id'])->find();
                $item['username'] = $member['username'];
                $item['tel'] = $member['tel'];
                $item['role_id'] = $member['role_id'];
                $item['pid'] = $member['pid'];
                $item['spid'] = $member['spid'];
                $item['member_user_id'] = $member['user_id'];
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
        if($row['status'] == '1'){
            $order_amount = $row['order_amount'];
            $AccountModel = new AccountModel();
            $use_integral = $AccountModel->where('user_id',$row['user_id'])->value('use_integral');
            if ($use_integral < $order_amount){
                return $this->error('PV不足');
            }
            $MemberModel = new MemberModel();
            $member_nums = $MemberModel->where('member_id',$row['member_id'])->count();
            if($member_nums == 0)
                return $this->error('ID' . $row['member_id'] . ' 会员不存在.');
            $AccountLogModel = new AccountLogModel();
            $inData['use_integral'] = $order_amount * -1;
            $inData['change_type'] = 11;
            $inData['change_desc'] = '报单';
            $res = $AccountLogModel->change($inData, $row['user_id']);
            if ($res != true) {
                return $this->error('报单失败');
            }
        }
        Db::startTrans();//启动事务
        $row['createtime'] = time();
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
        $order_id = input('order_id');
        $order_info = $this->Model->get($order_id);
        if(empty($order_info))return $this->error('参数错误');
        $reward_day = settings('reward_day');
        if(date('Y',$order_info['createtime']) != date('Y') ||
            date('m',$order_info['createtime']) != date('m') || 
            (date('d',$order_info['createtime']) < $reward_day && date('d') >= $reward_day))
            return $this->error('只能修改本期报单');
        $row['old_order_amount'] = $order_info['order_amount'];
        $row['old_status'] = $order_info['status'];
        if($row['status'] == 2)$row['invalidtime'] = time();
        $AccountLogModel = new AccountLogModel();
        if($row['status'] == '1'){
            if($row['old_status'] == 1){
                $order_amount = ($row['order_amount'] - $row['old_order_amount']);
            }else{
                $order_amount = $row['order_amount'];
            }
        }else{
            if($row['old_status'] == 1){
                $order_amount = $row['old_order_amount'] * -1;
            }else{
                $order_amount = 0;
            }
        }
        $MemberModel = new MemberModel();
        $member_nums = $MemberModel->where('member_id',$row['member_id'])->count();
        if($member_nums == 0)
            return $this->error('ID' . $row['member_id'] . ' 会员不存在.');
        if(abs($order_amount) > 0){
            $use_integral = (new AccountModel)->where('user_id',$row['user_id'])->value('use_integral');
            if ($use_integral < $order_amount){
                return $this->error('PV不足');
            }
            $inData['use_integral'] = $order_amount * -1;
            $inData['change_type'] = 11;
            $inData['change_desc'] = '后台修改报单差额';
            $res = $AccountLogModel->change($inData, $row['user_id']);
            if ($res != true) {
                return $this->error('修改报单失败');
            }
        }
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