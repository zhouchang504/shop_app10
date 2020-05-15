<?php

namespace app\member\model;

use app\BaseModel;
use think\facade\Cache;

//*------------------------------------------------------ */
//-- 虚拟会员表
/*------------------------------------------------------ */

class MemberModel extends BaseModel
{
    protected $table = 'member';
    protected $mkey = 'member_info_mkey_';
    public  $pk = 'member_id';
    protected $MemberOrderModel;//报单模型
    protected $MemberAccountLogModel;//日志模型

    protected $orderAmoutArr;     //团队实时业绩(计算奖励1)
    protected $orderMaxAmoutArr;  //团队实时业绩(最多时)
    protected $orderLupAmoutArr;  //团队实时业绩(升初级经理时)
    protected $orderDisAmoutArr;  //团队实时业绩(计算分销)
    protected $orderOldAmoutArr;  //团队本人业绩
    protected $memberLevelArr;    //会员实时等级
    protected $memberOldLevelArr; //会员原本等级
    protected $updateMemberArr;   //各等级会员
    protected $allAmout;  //全部业绩
    /*------------------------------------------------------ */
    //-- 优先自动执行
    /*------------------------------------------------------ */
    public function initialize(){
        $this->MemberOrderModel = new MemberOrderModel();
        $this->MemberAccountLogModel = new MemberAccountLogModel();
        $this->orderAmoutArr = array();
        $this->memberLevelArr = array();
        $this->orderDisAmoutArr = array();
        $this->orderLupAmoutArr = array();
        $this->updateMemberArr = array();
        $this->allAmout = 0;
    }
    /*------------------------------------------------------ */
    //-- 获取用户信息
    //-- val 查询值
    //-- type 查询类型
    //-- isCache 是否调用缓存
    /*------------------------------------------------------ */
    function info($val, $type = 'user_id', $isCache = true)
    {
        if (empty($val)) return false;
        if ($isCache == true) $info = Cache::get($this->mkey . $val);
        if (empty($info) == false) return $info;
        $info = $this->where($type, $val)->find();
        if (empty($info)){
            return [];
        }
        $info = $info->toArray();
        Cache::set($this->mkey . $val, $info, 30);
        return $info;
    }
    /*------------------------------------------------------ */
    //-- 升级与奖励
    /*------------------------------------------------------ */
    function reward()
    {
        $theday = settings('reward_day');//结算日(每月10号)
        $thismonth = date('m');
        $thisyear = date('Y');
        if ($thismonth == 1) {
            $lastmonth = 12;
            $lastyear = $thisyear - 1;
        } else {
            $lastmonth = $thismonth - 1;
            $lastyear = $thisyear;
        }
        $startRewardtime = strtotime($lastyear . '-' . $lastmonth . '-' . $theday . ' 23:59:59')+1;//奖励结算时间戳起始
        $stopRewardtime  = strtotime($thisyear . '-' . $thismonth . '-' . $theday . ' 23:59:59');  //奖励结算时间戳终止
        $whereOrder = array();
        $whereOrder[] = ['createtime','between',[$startRewardtime,$stopRewardtime]];
        $whereOrder[] = ['status','=',1];

        $UsersModel = new UsersModel();
        $user_list = $UsersModel->where('is_ban','0')->order('user_id asc')->select()->toArray();
        if(!$user_list){
            echo "奖励终止:没有专卖店";
            return false;
        }
        $this->move_order_amount($startRewardtime,$stopRewardtime);///移动业绩与临时等级///
        $this->reward1();            //奖励1
        $this->member_level();       //升级
        $this->base_salary_reward(); //底薪奖
        $this->distribution_reward();//分销奖&层级配对奖
        $this->dividend_reward();//加权分红奖
        $this->samelevel_reward();//平级奖
        $this->shop_reward($startRewardtime,$stopRewardtime);//店补奖
    }
    /*------------------------------------------------------ */
    //-- 移动业绩&临时等级
    //-- $startRewardtime 奖励结算时间戳起始
    //-- $stopRewardtime  奖励结算时间戳终止
    /*------------------------------------------------------ */
    function move_order_amount($startRewardtime='',$stopRewardtime='')
    {
        if (!$startRewardtime) $startRewardtime = strtotime(date("Y-m-1 00:00:00"));
        if (!$stopRewardtime) $stopRewardtime = strtotime(date("Y-m-t 23:59:59"));
        $leveup_1 = settings('leveup_1');//合格经理业绩门槛
        $leveup_2_team_amount = settings('leveup_2_team_amount');//初级经理团队内合格经理业绩门槛
        $distribution_max = settings('distribution_max');//分销奖业绩移动标准
        $order_amount_list = $this->MemberOrderModel->field('member_id,sum(order_amount) order_amounts')->where('status', '=', '1')->where('createtime', 'between', [$startRewardtime, $stopRewardtime])->group('member_id')->select();//团队报单数据
        if ($order_amount_list)foreach ($order_amount_list as $item) {//循环存储团队内每个人当月报单数据
            if ($this->orderOldAmoutArr[$item['member_id']] === null) {
                $this->orderOldAmoutArr[$item['member_id']] = (float)$item['order_amounts'];
            }
            $this->allAmout += (float)$item['order_amounts'];
        }
        $this->orderDisAmoutArr = $this->orderOldAmoutArr;
        $member_list = $this->field('member_id,role_id,spid')->select();//团队报单数据
        //保存本人等级
        if($member_list)foreach ($member_list as $item) {
            $this->memberLevelArr[$item['member_id']] = $item['role_id'] ? 1 : 0;//记录等级(只保留合格经理不降级)
        }
        $this->memberOldLevelArr = $this->memberLevelArr;//原本等级(注册会员或合格经理)
        //保存本人业绩
        if($member_list)foreach ($member_list as $item) {
            $pinfo = $item;
            $is_dis = true;
            $is_re1 = true;
            $parr = array();
            do {//从自己循环找上级
                if($is_re1 && $this->memberOldLevelArr[$pinfo['member_id']]){
                    $is_re1 = false;//如果自己是合格经理则不再给上级累加业绩
                }
                if($is_re1 && $this->orderOldAmoutArr[$pinfo['member_id']] >= $leveup_1 && $pinfo != $item){
                    $is_re1 = false;
                    $this->orderAmoutArr[$pinfo['member_id']] += $this->orderOldAmoutArr[$item['member_id']];//累加奖励1业绩
                }
                if($is_dis && $this->orderLupAmoutArr[$pinfo['member_id']] < $leveup_2_team_amount){
                    $this->orderLupAmoutArr[$pinfo['member_id']] += $this->orderOldAmoutArr[$item['member_id']];//累加升级业绩
                }else{
                    $is_dis = false;
                }
                $this->orderMaxAmoutArr[$pinfo['member_id']] += $this->orderOldAmoutArr[$item['member_id']];//累加最多业绩
                if($this->orderOldAmoutArr[$item['member_id']] >= $distribution_max)$parr[] = $pinfo['spid'];//记录本人的上级们,本人满了才能移
                $pinfo = $this->field('member_id,spid')->where('member_id', $pinfo['spid'])->find();//查询上级
            } while ($pinfo);
            //计算分销奖基数(移动业绩,层级从高到低)
            if($parr)foreach (array_reverse($parr,true) as $pkey=>$pitem){
                //业绩已经被移动过就停
                if($this->orderDisAmoutArr[$pitem] != $this->orderOldAmoutArr[$pitem]){
                    break;
                }
                //分销业绩不够并且报单业绩不够,就得找人移动业绩给自己
                if($pitem && $this->orderDisAmoutArr[$pitem] < $distribution_max && $this->orderOldAmoutArr[$pitem] < $distribution_max){
                    $diff = $distribution_max - $this->orderDisAmoutArr[$pitem];
                    $this->orderDisAmoutArr[$pitem] += $diff;
                    $this->orderDisAmoutArr[$item['member_id']] -= $diff;
                    break;
                }
            }
        }
//        dump($this->orderDisAmoutArr);die;
//        dump($this->orderAmoutArr);
//        dump($this->orderAmoutArr);
//        $this->orderMaxAmoutArr = $this->orderAmoutArr;//最多业绩
        //计算奖励1业绩和升级状态
        if($this->memberLevelArr)foreach($this->memberLevelArr as $key=>$value){
            if($value < 1){//注册会员想拿奖励1
                if($this->orderMaxAmoutArr[$key] >= $leveup_1){//即将升经理以上的注册会员才有资格拿奖励1
                    $this->memberLevelArr[$key] = 1;//临时升级
                }else{
                    $this->orderAmoutArr[$key] = 0;
                }
            }else{//经理以上直接拿奖励1(并且包含自己的报单业绩)
                $this->orderAmoutArr[$key] += $this->orderOldAmoutArr[$key];
            }
            if($this->orderOldAmoutArr[$key] < $leveup_1){//当月报单9000才有资格拿
                $this->orderAmoutArr[$key] = 0;
            }
        }
//        dump($this->orderLupAmoutArr);
//        dump($this->orderAmoutArr);die;
//        dump($this->memberLevelArr);
    }
    /*------------------------------------------------------ */
    //-- 判断升级(不包含判断合格经理)
    /*------------------------------------------------------ */
    function member_level()
    {
        if(!$this->memberLevelArr)return false;
        $theMemberLevelArr = $this->memberLevelArr;
        $settings = settings(); //获取设置信息
        if($this->memberLevelArr)foreach($this->memberLevelArr as $key=>$value){
            $son_5 = $this->get_role_sonnum($key,5);//团队内总监数量
            //高级总监
            //个人完成200元,团队培养3个总监
            if($this->orderOldAmoutArr[$key] >= $settings['leveup_6'] && $son_5 >= $settings['leveup_6_team']){
                $this->memberLevelArr[$key] = 6;
                continue;
            }
            $son_list = $this->field('member_id')->where('spid', $key)->select();//查询下级
            if($son_list)
            {
                ################################################↓总监↓################################################
                $son_4 = 0;
                $son_line_1 = 0;
                foreach ($son_list as $son){
                    if($this->get_role_sonnum($son['member_id'],4)){
                        $son_4++;
                    }
                    if($this->get_role_sonnum($son['member_id'],1) && $this->orderMaxAmoutArr[$son['member_id']] >= $settings['leveup_5_team_amount']){
                        $son_line_1++;
                    }
                }
                //个人完成200元,团队培养7条线合格经理,每条线团队业绩满9000,或者培养3条线高级经理
                if($this->orderOldAmoutArr[$key] >= $settings['leveup_5'] && ($son_4 >= $settings['leveup_5_team_order'] || $son_line_1 >= $settings['leveup_5_team'])){
                    $this->memberLevelArr[$key] = 5;
                    continue;
                }
                ##############################################↓高级经理↓##############################################
                $son_3 = 0;
                $son_line_1 = 0;
                foreach ($son_list as $son){
                    if($this->get_role_sonnum($son['member_id'],3)){
                        $son_3++;
                    }
                    if($this->get_role_sonnum($son['member_id'],1) && $this->orderMaxAmoutArr[$son['member_id']] >= $settings['leveup_4_team_amount']){
                        $son_line_1++;
                    }
                }
                //个人完成200元,团队培养4条线合格经理,每条线团队业绩满9000,或者培养3条线中级经理
                if($this->orderOldAmoutArr[$key] >= $settings['leveup_4'] && ($son_3 >= $settings['leveup_4_team_order'] || $son_line_1 >= $settings['leveup_4_team'])){
                    $this->memberLevelArr[$key] = 4;
                    continue;
                }
                ##############################################↓中级经理↓##############################################
                $son_line_1 = 0;
                foreach ($son_list as $son){
                    if($this->get_role_sonnum($son['member_id'],1) && $this->orderMaxAmoutArr[$son['member_id']] >= $settings['leveup_3_team_amount']){
                        $son_line_1++;
                    }
                }
                //个人完成200元,团队培养3条线合格经理,每条线团队业绩满9000
                if($this->orderOldAmoutArr[$key] >= $settings['leveup_3'] && $son_line_1 >= $settings['leveup_3_team']){
                    $this->memberLevelArr[$key] = 3;
                    continue;
                }
                ##############################################↓初级经理↓##############################################
                $son_line_1 = 0;
                $son_list[] = ['member_id'=>$key];
                foreach ($son_list as $son){
                    if($this->get_role_sonnum($son['member_id'],1) && $this->orderLupAmoutArr[$son['member_id']] >= $settings['leveup_2_team_amount']){
                        $son_line_1++;
                    }
                }
                //个人完成200元,团队培养2名合格经理各自团队业绩满9000
                if($this->orderOldAmoutArr[$key] >= $settings['leveup_2'] && $son_line_1 >= $settings['leveup_2_team']){
                    $this->memberLevelArr[$key] = 2;
                    continue;
                }
            }
        }
        if($theMemberLevelArr == $this->memberLevelArr){
            if($theMemberLevelArr){
                foreach ($theMemberLevelArr as $mid=>$role){
                    $this->updateMemberArr[$role][] = $mid;
                }
                if($this->updateMemberArr){
                    for($i=1;$i<=6;$i++){
                        if(!empty($this->updateMemberArr[$i]))$this->where('member_id','in',$this->updateMemberArr[$i])->update(['role_id'=>$i]);
                    }
                }
            }
            return true;
        }else{
            $this->member_level();
        }
    }
    /*------------------------------------------------------ */
    //-- 查出团队业绩,包括自己
    //-- $member_id 会员ID
    //-- $role_id   等级门槛
    //-- $onlyone   是否只查本人业绩
    /*------------------------------------------------------ */
    function get_son_amount($member_id=0,$startRewardtime='',$stopRewardtime='',$onlyone=false)
    {
        if(!$member_id)return false;
        if(!$startRewardtime)$startRewardtime = strtotime(date("Y-m-1 00:00:00"));
        if(!$stopRewardtime)$stopRewardtime = strtotime(date("Y-m-t 23:59:59"));
        if(!$onlyone)$son_ids = $this->get_role_son($member_id);//查出所有下级ID
        $son_ids[] = $member_id;
        $order_amount_list = $this->MemberOrderModel->field('*,sum(order_amount) order_amounts')->where('member_id','in',$son_ids)->where('status','=','1')->where('createtime','between',[$startRewardtime,$stopRewardtime])->group('member_id')->select();//团队报单数据
        $order_amount = 0;//团队总业绩
        if($order_amount_list)foreach ($order_amount_list as $item){
            if($this->orderAmoutArr[$item['member_id']] > 0 && $this->orderAmoutArr[$item['member_id']] === 0){
                $order_amount += $this->orderAmoutArr[$item['member_id']];
            }else{
                $this->orderAmoutArr[$item['member_id']] = $item['order_amounts'];
                $order_amount += $item['order_amounts'];
            }
        }
        return $order_amount;
    }

    /*------------------------------------------------------ */
    //-- 查出下级数量
    //-- $member_id 会员ID
    //-- $role_id   等级门槛
    //-- $myself    是否包括自己
    /*------------------------------------------------------ */
    function get_role_sonnum($member_id=0,$role_id=0,$myself=true)
    {
        if(!$member_id)return false;
        $son_num = 0;
        $_son_ids = [$member_id];
        do{
            $list = $this->where('spid','in',$_son_ids)->select();
            $_son_ids = array();
            if($list){
                foreach ($list as $item){
                    if($this->memberLevelArr[$item['member_id']] < $role_id){
                        $_son_ids[] = $item['member_id'];
                    }
                    if($this->memberLevelArr[$item['member_id']] >= $role_id){
                        $son_num++;
                    }
//                    echo $item['member_id'];echo ":";echo $son_num;echo "<br>";
                }
            }
        }while($_son_ids);
        if($myself){
            if($this->memberLevelArr[$member_id] >= $role_id){
                $son_num++;
            }
        }
        return $son_num;
    }
    /*------------------------------------------------------ */
    //-- 查出所有下级,不包含自己
    //-- $member_id 会员ID
    //-- $role_id   等级门槛
    /*------------------------------------------------------ */
    function get_role_son($member_id=0,$role_id=0)
    {
        if(!$member_id)return false;
        $son_arr = array();
        $_son_ids = [$member_id];
        do{
            $list = $this->where('spid','in',$_son_ids)->select();
            $_son_ids = array();
            if($list){
                foreach ($list as $item){
                    $_son_ids[] = $item['member_id'];
                    if($item['role_id'] >= $role_id){
                        $son_arr[] = $item['member_id'];
                    }
                }
            }
        }while($_son_ids);
        return $son_arr;
    }
    /*------------------------------------------------------ */
    //-- 奖励1
    /*------------------------------------------------------ */
    function reward1()
    {
        $distribution_pv = settings('distribution_pv');
        if($this->orderAmoutArr) foreach ($this->orderAmoutArr as $key=>$item) {
            if($item){
                $reward1Num = round($item * $distribution_pv / 100 , 2);
                $data = array();
                $data['member_id'] = $key;
                $data['balance_money'] = $reward1Num;
                $data['change_type'] = 4;
                $data['change_desc'] = "奖励1";
                $this->MemberAccountLogModel->change($data);
                echo "用户ID".$key."获得奖励1:".$reward1Num."元<br>";
            }

        }
    }
    /*------------------------------------------------------ */
    //-- 底薪奖
    /*------------------------------------------------------ */
    function base_salary_reward(){
        $base_salary = settings('base_salary');
        $leveup_1 = settings('leveup_1');//合格经理团队业绩门槛(也是拿底薪奖门槛)
        if($this->memberLevelArr)foreach($this->memberLevelArr as $key=>$value){
            if($value == 1 && $this->orderOldAmoutArr[$key] >= $leveup_1){
                $data = array();
                $data['member_id'] = $key;
                $data['balance_money'] = $base_salary;
                $data['change_type'] = 3;
                $data['change_desc'] = "底薪奖";
                $this->MemberAccountLogModel->change($data);
                echo "--用户ID".$key."获得底薪奖:".$base_salary."元<br>";
            }
        }
    }
    /*------------------------------------------------------ */
    //-- 分销奖&层级配对奖
    /*------------------------------------------------------ */
    function distribution_reward(){
        $settings = settings();
//        $settings['distribution_1'];dump($this->memberLevelArr);dump($this->orderLupAmoutArr);
        if($this->orderDisAmoutArr) foreach ($this->orderDisAmoutArr as $key=>$item) {
            if($this->memberLevelArr[$key] < 2)continue;//初级经理才有资格分销奖
            $thisDisNum = 0;//当前分销层数
            $maxDisNum = $this->memberLevelArr[$key] + 1;//最多分销层数
            if($this->memberLevelArr[$key] >= 4)$maxDisNum++;//高级总监以上分销奖层数+1
            $is_Dis = false;//是否能分销(将本层作为当次)
            $son_arr = array();
            //////////////////////查询出层级配对奖获得人//////////////////////
            $_member_info = $this->field('member_id,pid,spid')->where('member_id', $key)->find();//查询会员本人信息
            $member_info = $this->field('member_id')->where('member_id', $_member_info['pid'])->find();//查询推荐上级
            $pinfo = array();//能拿层级配对奖的上级
            if($this->memberLevelArr[$member_info['member_id']] < 4){
                $member_info = $_member_info;
                do{
                    $member_info = $this->field('member_id,spid')->where('member_id', $member_info['spid'])->find();//查询服务上级
                    if($this->memberLevelArr[$member_info['member_id']] >= 4){
                        $pinfo = $member_info;
                        break;
                    }
                }while(!empty($member_info));
            }else{
                $pinfo = $member_info;
            }
            //////////////////////查询出层级配对奖获得人//////////////////////
            $_son_ids = [$key];
            do{
                $list = $this->where('spid','in',$_son_ids)->select();
                $_son_ids = array();
                if(count($list)){
                    foreach ($list as $item){//找出所有下级存起来
                        $_son_ids[] = $item['member_id'];//服务关系本层
                        $son_arr[] = $item['member_id'];//分销关系本层
                        if($this->orderOldAmoutArr[$item['member_id']] >= $settings['leveup_2_team_amount']){
                            $is_Dis = true;
                        }
                    }
                }
                //本层有人报单金额满9000或者所有层都没人满9000
                if($is_Dis || (!empty($son_arr) && empty($_son_ids))){
                    $is_Dis = false;
                    $thisDisNum++;
                    //分销奖
                    $disAmout = 0;
                    if($son_arr)foreach ($son_arr as $son_item){
                        $disAmout += $this->orderDisAmoutArr[$son_item];
                    }
                    $son_arr = array();
                    $disAmoutprice = $disAmout * $settings['distribution_'.$thisDisNum] / 100;
                    $data = array();
                    $data['member_id'] = $key;
                    $data['balance_money'] = $disAmoutprice;
                    $data['change_type'] = 5;
                    $data['change_desc'] = $thisDisNum."层分销奖";
                    $this->MemberAccountLogModel->change($data);
                    echo "-- --用户ID".$key."获得".$thisDisNum."层分销奖:".$disAmoutprice."元<br>";
                    //层级配对奖
                    if(!empty($pinfo)){
                        $paiAmoutprice = $disAmoutprice * $settings['pair_'.($this->memberLevelArr[$pinfo['member_id']] - 3)] / 100;
                        $data = array();
                        $data['member_id'] = $pinfo['member_id'];
                        $data['balance_money'] = $paiAmoutprice;
                        $data['change_type'] = 6;
                        $data['change_desc'] = "用户".$key."的".$thisDisNum."层层级配对奖";
                        $data['by_id'] = $key;
                        $this->MemberAccountLogModel->change($data);
                        echo "-- --用户ID".$pinfo['member_id']."获得用户".$key."的".$thisDisNum."层层级配对奖:".$paiAmoutprice."元<br>";
                    }
                }
                if($thisDisNum >= $maxDisNum)break;//达到最大分销层数后结束
            }while($_son_ids);
        }
    }
    /*------------------------------------------------------ */
    //-- 加权分红奖
    /*------------------------------------------------------ */
    function dividend_reward(){
        $settings = settings();
        echo "本次全部报单金额:".$this->allAmout." <br>";
        $dividendAmout_1 = 0;//高级经理以上总业绩
        $dividendAmout_2 = 0;//总监以上总业绩
        $dividendAmout_3 = 0;//高级总监以上总业绩
        if($this->updateMemberArr){
            for($i=4;$i<=6;$i++){
                if(!empty($this->updateMemberArr[$i]))foreach ($this->updateMemberArr[$i] as $mid){
                    $dividendAmout_1 += $this->orderMaxAmoutArr[$mid];
                    if($i >= 5)$dividendAmout_2 += $this->orderMaxAmoutArr[$mid];
                    if($i >= 6)$dividendAmout_3 += $this->orderMaxAmoutArr[$mid];
                }
            }
            echo "高级经理以上总业绩:".$dividendAmout_1." <br>";
            echo "总监以上总业绩:".$dividendAmout_2." <br>";
            echo "高级总监以上总业绩:".$dividendAmout_3." <br>";
            for($i=4;$i<=6;$i++){
                if(!empty($this->updateMemberArr[$i]))foreach ($this->updateMemberArr[$i] as $mid){
                    $data = array();
                    $data['member_id'] = $mid;
                    $data['balance_money'] = round(($this->orderMaxAmoutArr[$mid]/$dividendAmout_1)*$this->allAmout*$settings['dividend_1']/100,2);
                    $data['change_type'] = 7;
                    $data['change_desc'] = "加权分红(高级经理)";
                    $this->MemberAccountLogModel->change($data);
                    echo "-- -- --用户ID".$mid."获得(高级经理)加权分红奖:".round(($this->orderMaxAmoutArr[$mid]/$dividendAmout_1)*$this->allAmout*$settings['dividend_1']/100,2)."元<br>";
                    if($i >= 5){
                        $data = array();
                        $data['member_id'] = $mid;
                        $data['balance_money'] = round(($this->orderMaxAmoutArr[$mid]/$dividendAmout_2)*$this->allAmout*$settings['dividend_2']/100,2);
                        $data['change_type'] = 7;
                        $data['change_desc'] = "加权分红(总监)";
                        $this->MemberAccountLogModel->change($data);
                        echo "-- -- --用户ID".$mid."获得(总监)加权分红奖:".round(($this->orderMaxAmoutArr[$mid]/$dividendAmout_2)*$this->allAmout*$settings['dividend_2']/100,2)."元<br>";
                    }
                    if($i >= 6){
                        $data = array();
                        $data['member_id'] = $mid;
                        $data['balance_money'] = round(($this->orderMaxAmoutArr[$mid]/$dividendAmout_3)*$this->allAmout*$settings['dividend_3']/100,2);
                        $data['change_type'] = 7;
                        $data['change_desc'] = "加权分红(高级总监)";
                        $this->MemberAccountLogModel->change($data);
                        echo "-- -- --用户ID".$mid."获得(高级总监)加权分红奖:".round(($this->orderMaxAmoutArr[$mid]/$dividendAmout_3)*$this->allAmout*$settings['dividend_3']/100,2)."元<br>";
                    }
                }
            }
        }
    }
    /*------------------------------------------------------ */
    //-- 平级奖
    /*------------------------------------------------------ */
    function samelevel_reward(){

        $settings = settings();
        $same_num = 0;
        if(!empty($this->updateMemberArr[6]))foreach ($this->updateMemberArr[6] as $mid){
            $member_info = $this->field('member_id,spid')->where('member_id', $mid)->find();//查询会员本人
            do{
                $member_info = $this->field('member_id,spid')->where('member_id', $member_info['spid'])->find();//查询服务上级
                if($this->memberLevelArr[$member_info['member_id']] >= 6){
                    $same_num++;
                    $data = array();
                    $data['member_id'] = $member_info['member_id'];
                    $data['balance_money'] = round($this->orderMaxAmoutArr[$mid]*$settings['same_'.$same_num]/100,2);
                    $data['change_type'] = 8;
                    $data['change_desc'] = "用户".$mid."的".$same_num."层平级奖";
                    $data['by_id'] = $mid;
                    $this->MemberAccountLogModel->change($data);
                    echo "-- -- -- --用户ID".$member_info['member_id']."获得用户".$mid."的".$same_num."层平级奖:".round($this->orderMaxAmoutArr[$mid]*$settings['same_'.$same_num]/100,2)."元<br>";
                }
            }while(!empty($member_info) && $same_num < 3);
        }
    }
    /*------------------------------------------------------ */
    //-- 店补奖
    //-- $startRewardtime 奖励结算时间戳起始
    //-- $stopRewardtime  奖励结算时间戳终止
    /*------------------------------------------------------ */
    function shop_reward($startRewardtime='',$stopRewardtime='')
    {
        $subsidy = settings('subsidy');
        if (!$startRewardtime) $startRewardtime = strtotime(date("Y-m-1 00:00:00"));
        if (!$stopRewardtime) $stopRewardtime = strtotime(date("Y-m-t 23:59:59"));
        $AccountLogModel = new AccountLogModel();
        $user_list = (new UsersModel)->select();
        if($user_list)foreach ($user_list as $user_item){
            $order_amount = $this->MemberOrderModel->where('user_id','=',$user_item['user_id'])->where('status','=',1)->where('createtime', 'between', [$startRewardtime, $stopRewardtime])->sum('order_amount');
            if($order_amount > 0){
                $order_amount_pv = round($order_amount * $subsidy / 100 , 2);
                $changedata['change_desc'] = '店补奖';
                $changedata['change_type'] = 20;
                $changedata['balance_money'] = $order_amount_pv;
                $res = $AccountLogModel->change($changedata, $user_item['user_id']);
                echo "-- -- -- -- --专卖店ID".$user_item['user_id']."获得店补奖:".$order_amount_pv."元<br>";
            }
        }
    }
}
