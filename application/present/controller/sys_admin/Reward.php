<?php

namespace app\present\controller\sys_admin;

use app\member\model\MemberAccountLogModel;
use app\member\model\MemberOrderModel;
use think\Db;
use app\AdminController;
use app\member\model\MemberModel;
use app\distribution\model\DividendRoleModel;

/**
 * 奖励
 * Class Index
 * @package app\store\controller
 */
class Reward extends AdminController
{
    //*------------------------------------------------------ */
    //-- 初始化
    /*------------------------------------------------------ */
    public function initialize($isretrun = true)
    {
        parent::initialize();
        $this->Model = new MemberAccountLogModel();
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
        $nowDate =date("Y-m-d");
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
            }else if(in_array($search['searchKey'],['user_id'])){
                $uids = $MemberModel->where("user_id = '".$search['keyword']."'")->column('member_id');
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
        if($uids)$where[] = ['member_id','in',$uids];
        $reportrange = input('reportrange');
        if (empty($reportrange) == false){
            $dtime = explode('-',$reportrange);
            $where[] = ['change_time','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
        }else{
            $where[] = ['change_time','between',[strtotime("-6 months"),time()]];
        }
        $export = input('export', 0, 'intval');
        if ($export > 0) {
            return $this->exportList($where);
        }
        $this->data = $this->getPageList($this->Model, $where);
        $this->data['balance_money'] = $this->Model->where($where)->sum('balance_money');
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
    /*------------------------------------------------------ */
    //-- 导出数据
    /*------------------------------------------------------ */
    public function exportList($where)
    {
        $count = $this->Model->where($where)->order('log_id DESC')->count('log_id');
        if ($count < 1) return $this->error('没有找到可导出的日志资料！');
        $filename = '奖励列表资料_' . date("YmdHis") . '.xls';
        $export_arr['日志ID'] = 'log_id';
        $export_arr['奖励金额'] = 'balance_money';
        $export_arr['奖励描述'] = 'change_desc';
        $export_arr['奖励时间'] = 'change_time';
        $export_arr['会员ID'] = 'member_id';
        $export_arr['昵称'] = 'username';
        $export_arr['手机号'] = 'tel';
        $export_arr['会员等级'] = 'role_id';
        $export_arr['推荐上级'] = 'pid';
        $export_arr['服务上级'] = 'spid';
        $export_arr['银行卡号'] = 'banknumber';
        $export_arr['开户行地址'] = 'bankaddress';

        $page = 0;
        $page_size = 500;
        $page_count = 200;

        $title = join("\t", array_keys($export_arr)) . "\t";

        $MemberModel = new MemberModel();
        $DividendRoleModel = new DividendRoleModel();
        $roleList = $DividendRoleModel->getRows();
        $data = '';
        do {
            $rows = $this->Model->where($where)->order('log_id DESC')->limit($page * $page_size, $page_size)->select();

            if (empty($rows))return;
            foreach ($rows as $row) {
                foreach ($export_arr as $val) {
                    if (strstr($val, '_time')) {
                        $data .= dateTpl($row[$val]) . "\t";
                    }elseif(in_array($val, ['username','tel','role_id','pid','spid','banknumber','bankaddress'])){
                        $member = $MemberModel->where('member_id',$row['member_id'])->find();
                        if($val == 'role_id'){
                            $rode_name = $member['role_id'] == 0 ? '粉丝' : $roleList[$member['role_id']]['role_name'];
                            $data .= $rode_name  . "\t";
                        }else{
                            $data .= $member[$val] . "\t";
                        }
                    } else {
                        $data .= str_replace(array("\r\n", "\n", "\r"), '', strip_tags($row[$val])) . "\t";
                    }
                }
                $data .= "\n";
            }
            $page++;
        } while ($page <= $page_count);

        $filename = iconv('utf-8', 'GBK//IGNORE', $filename);
        header("Content-type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=$filename");
        echo iconv('utf-8', 'GBK//IGNORE', $title . "\n" . $data) . "\t";
        exit;
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
        $row['change_time'] = time();
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
        if($row['status'] == 2)$row['invalidtime'] = time();
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