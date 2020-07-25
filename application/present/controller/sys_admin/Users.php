<?php

namespace app\present\controller\sys_admin;

use app\member\model\MemberAccountLogModel;
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

        if (empty($search['keyword']) == false && $search['searchKey']) {
            $where[] = [$search['searchKey'], 'eq',"".$search['keyword'].""];
        }
        $search['role_id'] = input('role_id', '', 'trim');
        if ($search['role_id']) $where[] = ['role_id','=',$search['role_id']];
        $time_type = input('time_type', 'reg_time', 'trim');
        $time_type = empty($time_type) ? 'reg_time' : $time_type;
        $reportrange = input('reportrange');
        if (empty($reportrange) == false && $time_type == 'reg_time'){
            $dtime = explode('-',$reportrange);
            $where[] = ['regtime','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
        }
        $export = input('export', 0, 'intval');
        if ($export > 0) {
            return $this->exportList($where);
        }
        $this->data = $this->getPageList($this->Model, $where);
        $MemberAccountLogModel = new MemberAccountLogModel();
        foreach ($this->data['list'] as $item){
            $whereAccountLog = [];
            $whereAccountLog[] = ['member_id', '=', $item['member_id']];
            if (empty($reportrange) == false && $time_type == 'reward_time'){
                $dtime = explode('-',$reportrange);
                $whereAccountLog[] = ['change_time','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
            }
            $balance_moneys = $MemberAccountLogModel->where($whereAccountLog)->sum('balance_money');
            $item['balance_moneys'] = $balance_moneys;
            $_list[] = $item;
        }
        $this->data['list'] = $_list;
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
        $time_type = input('time_type', 'reg_time', 'trim');
        $time_type = empty($time_type) ? 'reg_time' : $time_type;
        $reportrange = input('reportrange');
        $count = $this->Model->where($where)->order('member_id DESC')->count('member_id');
        if ($count < 1) return $this->error('没有找到可导出的日志资料！');
        $filename = '会员列表资料_' . date("YmdHis") . '.xls';
        $export_arr['会员ID'] = 'member_id';
        $export_arr['昵称'] = 'username';
        $export_arr['手机号'] = 'tel';
        $export_arr['会员等级'] = 'role_id';
        $export_arr['推荐上级'] = 'pid';
        $export_arr['服务上级'] = 'spid';
        $export_arr['所属专卖店'] = 'user_id';
        $export_arr['奖励总额'] = 'balance_moneys';
        $export_arr['注册时间'] = 'regtime';
        $export_arr['银行卡号'] = 'banknumber';
        $export_arr['开户行地址'] = 'bankaddress';

        $page = 0;
        $page_size = 500;
        $page_count = 200;

        $title = join("\t", array_keys($export_arr)) . "\t";

        $MemberAccountLogModel = new MemberAccountLogModel();
        $DividendRoleModel = new DividendRoleModel();
        $roleList = $DividendRoleModel->getRows();
        $data = '';
        do {
            $rows = $this->Model->where($where)->order('member_id DESC')->limit($page * $page_size, $page_size)->select();

            if (empty($rows))return;
            foreach ($rows as $row) {
                foreach ($export_arr as $val) {
                    if (strstr($val, '_time') || $val == 'regtime') {
                        $data .= dateTpl($row[$val]) . "\t";
                    }elseif($val == 'balance_moneys'){
                        $whereAccountLog = [];
                        $whereAccountLog[] = ['member_id', '=', $row['member_id']];
                        if (empty($reportrange) == false && $time_type == 'reward_time'){
                            $dtime = explode('-',$reportrange);
                            $whereAccountLog[] = ['change_time','between',[strtotime($dtime[0]),strtotime($dtime[1])+86399]];
                        }
                        $balance_moneys = $MemberAccountLogModel->where($whereAccountLog)->sum('balance_money');
                        $data .= $balance_moneys  . "\t";
                    }elseif($val == 'role_id'){
                        $rode_name = $row[$val] == 0 ? '粉丝' : $roleList[$row[$val]]['role_name'];
                        $data .= $rode_name  . "\t";
                    }elseif($val == 'banknumber'){
                        $data .= str_replace(array("\r\n", "\n", "\r"), '', '\''.strip_tags($row[$val])) . "\t";
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
        $member_nums = $this->Model->where('member_id',$row['pid'])->count();
        if($member_nums == 0)
            return $this->error('推荐人不存在');
        $member_nums = $this->Model->where('member_id',$row['spid'])->count();
        if($member_nums == 0)
            return $this->error('服务上级不存在');
        $member_nums = $this->Model->where('tel',$row['tel'])->count();
        if($member_nums > 0)
            return $this->error('手机号已存在');
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
        $member_id = input('member_id');
        $member_nums = $this->Model->where('member_id',$row['pid'])->count();
        if($member_nums == 0)
            return $this->error('推荐人不存在');
        $member_nums = $this->Model->where('member_id',$row['spid'])->count();
        if($member_nums == 0)
            return $this->error('服务上级不存在');
        $member_nums = $this->Model->where('tel',$row['tel'])->where('member_id','neq',$member_id)->count();
        if($member_nums > 0)
            return $this->error('手机号已存在');
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