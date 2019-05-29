<?php
/*------------------------------------------------------ */
//-- 天亿项目提成处理
//-- Author: iqgmy
/*------------------------------------------------------ */

namespace distribution\sdtydw;

use app\BaseModel;
use think\Db;

use app\member\model\UsersModel;
use app\shop\model\OrderModel;
use app\shop\model\OrderGoodsModel;
use app\member\model\AccountLogModel;
use app\distribution\model\DividendAwardModel;
use app\distribution\model\DividendRoleModel;

class Dividend extends BaseModel
{

    public $UsersModel;

    public function __construct($Model)
    {
        $this->exists = false;
        parent::__construct();
        $this->UsersModel = new UsersModel();
        $this->Model = $Model;
    }


    /*------------------------------------------------------ */
    //-- 计算提成并记录或更新
    /*------------------------------------------------------ */
    public function _eval(&$orderInfo, $type = '')
    {
        if ($orderInfo['is_split'] > 0) return true;//需要拆单的不执行
        $goodsList = [];
        //身份订单处理
        if ($orderInfo['d_type'] == 'role_order') {
            $status = 3;
            Db::startTrans();//启动事务
            $logArr = $this->saveLog($orderInfo, $goodsList, $status);//佣金计算
            if (is_array($logArr) == false) {
                Db::rollback();// 回滚事务
                return false;
            }
            Db::commit();// 提交事务
            $logArr['is_dividend'] = 1;
            $this->evalArrival($orderInfo['order_id']);//身份订单直接执行分佣
            $res = $this->evalLevelUp($orderInfo, $goodsList, $orderInfo['user_id']);//会员升级
            if ($res == true) {
                $logArr['is_up_role'] = 1;
            }
            return $logArr;
        }//end

        if ($orderInfo['d_type'] == 'order') {//普通订单，执行
            //获取订单中的分销商品
            $where[] = ['order_id', '=', $orderInfo['order_id']];
            $where[] = ['is_dividend', '=', 1];
            $goodsList = (new OrderGoodsModel)->where($where)->select();
            if (empty($goodsList)) return true;//没有分销商品不执行
        }
        $DividendInfo = settings('DividendInfo');
        $upData = [];//更新分佣记录状态
        $OrderModel = new OrderModel();

        //先计算佣金再执行升级处理
        if ($type == 'add') {//写入分佣，普通订单下单时执行
            $status = 0;
            $logArr = $this->saveLog($orderInfo, $goodsList, $status);//佣金计算
            if (is_array($logArr) == false) return false;
            return $logArr;
        } elseif ($type == 'pay') {//订单支付成功
            if ($DividendInfo['bind_type'] == 1) {//支付成功时绑定关系
                $this->UsersModel->regUserBind($orderInfo['user_id']);
            }
            $res = $this->evalLevelUp($orderInfo, $goodsList, $orderInfo['user_id']);
            if ($res == false) return false;
            $upData['status'] = $OrderModel->config['DD_PAYED'];
        } elseif ($type == 'cancel') {//订单取消
            if ($orderInfo['pay_status'] == 1) $send_msg = true;
            $upData['status'] = $OrderModel->config['DD_CANCELED'];
        } elseif ($type == 'unpayed') {//未付款
            if ($orderInfo['order_status'] == $OrderModel->config['OS_CANCELED']) {
                $upData['status'] = $OrderModel->config['DD_CANCELED'];
            } elseif ($orderInfo['order_status'] == $OrderModel->config['OS_RETURNED']) {
                $upData['status'] = $OrderModel->config['DD_RETURNED'];
            } else {
                $upData['status'] = $OrderModel->config['DD_UNCONFIRMED'];
            }
        } elseif ($type == 'shipping') {//发货
            $upData['status'] = $OrderModel->config['DD_SHIPPED'];
        } elseif ($type == 'unshipping') {//未发货
            $upData['status'] = $OrderModel->config['DD_PAYED'];
        } elseif ($type == 'sign') {//签收
            $upData['status'] = $OrderModel->config['DD_SIGN'];
        } elseif ($type == 'unsign') {//撤销签收
            return $this->returnArrival($orderInfo['order_id'], 'unsign', $orderInfo['user_id']);
        } elseif ($type == 'returned') {//退货
            return $this->returnArrival($orderInfo['order_id'], 'returned', $orderInfo['user_id']);
        }

        if (empty($upData) == false) {//更新分佣状态
            $count = $this->Model->where('order_id', $orderInfo['order_id'])->count();
            if ($count < 1) return true;//如果没有佣金记录不执行
            $upData['update_time'] = time();
            $res = $this->Model->where('order_id', $orderInfo['order_id'])->update($upData);
            if ($res < 1) return false;
        }

        if ($type == 'sign') {//签收
            $shop_after_sale_limit = settings('shop_after_sale_limit');
            if ($shop_after_sale_limit == 0) {
                return $this->evalArrival($orderInfo['order_id']);
            }
        }
        return true;
    }
    /*------------------------------------------------------ */
    //-- 计算提成并记录或更新
    /*------------------------------------------------------ */
    public function saveLog(&$orderInfo, &$goodsList, $status = 0)
    {
        $awardList = (new DividendAwardModel)->order('award_type DESC')->select();//获取全部奖项目,按类型倒序，先处理管理奖
        if (empty($awardList)) return false;
        $dividend_amount = 0;//共分出去的佣金

        $nowLevel = 0;//当前处理级别
        $nowLevelOrdinary = [];//普通分销当前处理级别，普通分销有逐级计算和无限级计算，如果无限级，不满条件将一直最后的上级
        $assignAwardNum = [];//记录已分出去的管理奖金额
        $assignAwardUser = [];//记录已领管理奖的用户


        $delWhere[] = ['order_id', '=', $orderInfo['order_id']];
        $delWhere[] = ['order_type', '=', $orderInfo['d_type']];
        $this->Model->where($delWhere)->delete();//清理旧的提成记录，重新计算
        $buyUserInfo = $this->UsersModel->info($orderInfo['user_id']);//获取购买会员信息

        //身份佣金处理
        if ($orderInfo['d_type'] == 'role_order') {
            foreach ($awardList as $key => $award) {
                $parentId = $buyUserInfo['pid'];//获取购买会员直属上级ID
                if ($award['goods_limit'] != 4 || $award['award_type'] != 4) {//非指定身份商品的奖项跳出
                    continue;
                }
                if (isset($nowLevelOrdinary[$key]) == false) {
                    $nowLevelOrdinary[$key] = 0;
                }
                $nowLevelOrdinary[$key] += 1;
                //判断身份是否满足条件
                $limit_role = explode(',', $award['limit_role']);
                if (in_array($buyUserInfo['role_id'], $limit_role) == false) {
                    continue;
                }
                //判断购买身份商品
                if ($award['role_goods'] != $orderInfo['rg_id']) {
                    continue;
                }
                if ($award['buy_user_award'] > 0) {//购买者自返
                    $inArr['status'] = $status;
                    $inArr['order_type'] = $orderInfo['d_type'];
                    $inArr['order_id'] = $orderInfo['order_id'];
                    $inArr['order_sn'] = $orderInfo['order_sn'];
                    $inArr['buy_uid'] = $orderInfo['user_id'];
                    $inArr['order_amount'] = $orderInfo['order_amount'];
                    $inArr['dividend_uid'] = $buyUserInfo['user_id'];
                    $inArr['role_id'] = $buyUserInfo['role_id'] * 1;
                    $inArr['role_name'] = $buyUserInfo['role_id'] > 0 ? $buyUserInfo['role']['role_name'] : '粉丝';
                    $inArr['level'] = $nowLevel;
                    $inArr['award_id'] = $award['award_id'];
                    $inArr['award_name'] = $award['award_name'];
                    $inArr['level_award_name'] = '自购返佣';
                    if ($award['buy_user_award_type'] == 'money') {//固定金额
                        $inArr['dividend_amount'] = $award['buy_user_award'];
                    } else {//订单百分比，扣除运费后计算
                        $amount = $orderInfo['order_amount'] - $orderInfo['shipping_fee'];
                        $inArr['dividend_amount'] = $amount / 100 * $award['buy_user_award'];
                    }
                    $dividend_amount += $inArr['dividend_amount'];
                    $inArr['add_time'] = $inArr['update_time'] = time();
                    $res = $this->Model->create($inArr);
                    if ($res->log_id < 1) return false;
                }
                $awardValue = json_decode($award['award_value'], true);//奖项内容
                foreach ($awardValue as $value) {
                    $userInfo = $this->UsersModel->info($parentId);//获取会员信息
                    $parentId = $userInfo['pid'];//优先记录下次循环用户ID
                    $role_id = $userInfo['role_id'] * 1;
                    $num = $value['num'][$role_id];
                    if ($num <= 0){
                        continue;
                    }
                    $inArr['status'] = $status;
                    $inArr['order_id'] = $orderInfo['order_id'];
                    $inArr['order_sn'] = $orderInfo['order_sn'];
                    $inArr['buy_uid'] = $orderInfo['user_id'];
                    $inArr['order_amount'] = $orderInfo['order_amount'];
                    $inArr['dividend_uid'] = $userInfo['user_id'];
                    $inArr['role_id'] = $role_id;
                    $inArr['role_name'] = $userInfo['role_id'] > 0 ? $userInfo['role']['role_name'] : '粉丝';
                    $inArr['level'] = $value['level'];
                    $inArr['award_id'] = $award['award_id'];
                    $inArr['award_name'] = $award['award_name'];
                    $inArr['level_award_name'] = $value['name'];
                    $num_type = $value['num_type'][$role_id];
                    if ($num_type == 'money') {//固定金额
                        $inArr['dividend_amount'] = $num;
                    } else {//订单百分比，扣除运费后计算
                        $amount = $orderInfo['order_amount'] - $orderInfo['shipping_fee'];
                        $inArr['dividend_amount'] = $amount / 100 * $num;
                    }
                    $dividend_amount += $inArr['dividend_amount'];
                    $inArr['add_time'] = $inArr['update_time'] = time();
                    $res = $this->Model->create($inArr);
                    if ($res->log_id < 1) return false;
                    if ($parentId < 1) break;//没有找到上级终止
                }
            }
            return ['dividend_amount' => $dividend_amount];
        }//end

        $parentId = $buyUserInfo['pid'];//获取购买会员直属上级ID
        //普通订单奖项处理
        $roleList = (new DividendRoleModel)->getRows();
        $lastRole = $roleList[$orderInfo['dividend_role_id']]['level'];//下单会员下单时身份级别
        if ($parentId < 1) return ['dividend_amount' => $dividend_amount];
        $order_goods_ids = [];
        $order_goods_num = 0;
        $goods_buy_num = [];//购买商品的数量
        $buy_goods_name = [];
        foreach ($goodsList as $goods) {
            $order_goods_ids[] = $goods['goods_id'];
            $order_goods_num += $goods['goods_number'];
            $goods_buy_num[$goods['goods_id']] = $goods['goods_number'];
            $buy_goods_name[] = $goods['goods_name'];
        }
        do {
            $nowLevel += 1;
            $userInfo = $this->UsersModel->info($parentId);//获取会员信息
            $parentId = $userInfo['pid'];//优先记录下次循环用户ID

            foreach ($awardList as $key => $award) {

                if ($award['goods_limit'] == 4){//身份分销的跳过
                    continue;
                }

                if (isset($nowLevelOrdinary[$key]) == false) {
                    $nowLevelOrdinary[$key] = 0;
                }
                $nowLevelOrdinary[$key] += 1;
                $awardValue = json_decode($award['award_value'], true);    //奖项内容
                //$awardBuyNum = 0;//订购限制商品数量

                if ($award['goods_limit'] == 2) {//购买全部指定分销商品
                    $award_limit_buy_goods = explode(',', $award['buy_goods_id']);
                    $isOk = true;
                    foreach ($award_limit_buy_goods as $goods_id) {
                        if (in_array($goods_id, $order_goods_ids) == false) {//限制商品不存在购买中，失败跳过
                            $isOk = false;
                            continue;
                        }
                        //$awardBuyNum += $goods_buy_num[$goods_id];
                    }
                    if ($isOk == false) {//不满足购买限制，跳出
                        continue;
                    }
                    $goods_num = count($order_goods_ids) * $award['goods_limit_num'];
                    if ($order_goods_num < $goods_num) {
                        continue;
                    }
                } elseif ($award['goods_limit'] == 3) {//购买任意指定分销商品
                    $award_limit_buy_goods = explode(',', $award['buy_goods_id']);
                    $isOk = false;
                    foreach ($award_limit_buy_goods as $goods_id) {
                        if (in_array($goods_id, $order_goods_ids) == true) {//限制商品存在购买中，成功跳出
                            $isOk = true;
                            //$awardBuyNum += $goods_buy_num[$goods_id];
                        }
                    }
                    if ($isOk == false) {//不满足购买限制，跳出
                        continue;
                    }
                    if ($order_goods_num < $award['goods_limit_num']) {
                        continue;
                    }
                } else {//购买任意分销商品
                    //$awardBuyNum = $order_goods_num;
                }

                //判断身份是否满足条件
                $limit_role = explode(',', $award['limit_role']);
                if (in_array($userInfo['role_id'], $limit_role) == false) {
                    if ($award['award_type'] == 1 && $award['ordinary_type'] == 1) {//普通分销奖，无限级计算时执行
                        $nowLevelOrdinary[$key] -= 1;
                    }
                    continue;
                }
                if ($award['award_type'] == 3) {//判断管理奖是否享受
                    if ($userInfo['role']['level'] <= $lastRole) {//上级身份低于下级身份或平级时跳出
                        continue;
                    }
                    if (empty($awardValue[$userInfo['role_id']])) {//没有找到相应奖项级别跳出
                        continue;
                    }
                    if (isset($assignAwardNum[$award['award_id']]) == false) {//未定义附值为0
                        $assignAwardNum[$award['award_id']] = 0;
                    }
                    $endAward = end($awardValue);//获取最后奖项
                    if ($assignAwardNum[$award['award_id']] >= $endAward['num']) {
                        unset($awardList[$key]);//管理奖已达最大分配值，终止，跳出
                        continue;
                    }
                    $awardVal = $awardValue[$userInfo['role_id']];//获取对应角色奖项
                    $lastRole = $userInfo['role']['level'];
                    $award_num = $awardVal['num'] - $assignAwardNum[$award['award_id']];//计算当前可分值
                    if ($award_num <= 0) {//已分完终止
                        unset($awardList[$key]);//移除已结束的奖项
                        continue;
                    }
                } else {
                    if ($award['award_type'] == 1 && $award['ordinary_type'] == 1) {//普通分销，无限级计算时，会员判断级别方式不一样
                        if (empty($awardValue[$nowLevelOrdinary[$key]])) {//没有找到相应奖项级别跳出，并移除奖项
                            unset($awardList[$key]);//移除奖项
                            continue;
                        }
                        $awardVal = $awardValue[$nowLevelOrdinary[$key]];
                    } else {
                        if (empty($awardValue[$nowLevel])) {//没有找到相应奖项级别跳出，并移除奖项
                            unset($awardList[$key]);//移除奖项
                            continue;
                        }
                        $awardVal = $awardValue[$nowLevel];
                    }
                }

                //执行奖项处理
                $inArr = [];
                if ($award['award_type'] == 1) {//普通分销奖
                    if ($awardVal['num_type'] == 'money') {//固定金额
                        $inArr['dividend_amount'] = $awardVal['num'];
                    } else {//订单百分比，扣除运费后计算
                        $amount = $orderInfo['order_amount'] - $orderInfo['shipping_fee'];
                        $inArr['dividend_amount'] = $amount / 100 * $awardVal['num'];
                    }
                } elseif ($award['award_type'] == 3) {//管理奖
                    $assignAwardUser[] = $userInfo['user_id'];
                    $assignAwardNum[$award['award_id']] += $award_num;
                    if ($awardVal['num_type'] == 'money') {//固定金额
                        $inArr['dividend_amount'] = $award_num;
                    } else {//订单百分比，扣除运费后计算
                        $amount = $orderInfo['order_amount'] - $orderInfo['shipping_fee'];
                        $inArr['dividend_amount'] = $amount / 100 * $awardVal['num'];
                    }
                }
                $dividend_amount += $inArr['dividend_amount'];//计算总佣金
                $inArr['order_type'] = $orderInfo['d_type'];
                $inArr['status'] = $status;
                $inArr['order_id'] = $orderInfo['order_id'];
                $inArr['order_sn'] = $orderInfo['order_sn'];
                $inArr['buy_uid'] = $orderInfo['user_id'];
                $inArr['order_amount'] = $orderInfo['order_amount'];
                $inArr['dividend_uid'] = $userInfo['user_id'];
                $inArr['role_id'] = $userInfo['role_id'];
                $inArr['role_name'] = $userInfo['role']['role_name'];
                $inArr['level'] = $nowLevel;
                $inArr['award_id'] = $award['award_id'];
                $inArr['award_name'] = $award['award_name'];
                $inArr['level_award_name'] = $awardVal['name'];
                $inArr['add_time'] = $inArr['update_time'] = time();
                $res = $this->Model->create($inArr);
                if ($res->log_id < 1) return false;
            }

            if (empty($awardList) == true) {//没有奖项可分了，终止
                $parentId = 0;
            }
        } while ($parentId > 0);
        return ['dividend_amount' => $dividend_amount];
    }
    /*------------------------------------------------------ */
    //-- 执行升级方案
    /*------------------------------------------------------ */
    public function evalLevelUp(&$orderInfo, &$goodsList, $user_id = 0)
    {
        //执行分销身份升级处理
        $roleList = (new DividendRoleModel)->getRows();
        $LogSysModel = new \app\member\model\LogSysModel();
        $oldFun = '';
        $DividendInfo = settings('DividendInfo');
        $userRoleLevel = 0;//初始会员身份等级

        $usersInfo = $this->UsersModel->info($user_id);//获取会员信息
        if ($usersInfo['role_id'] > 0) {
            $userRoleLevel = $roleList[$usersInfo['role_id']]['level'];//获取当前会员身份等级
        }
        $upRole = [];
        foreach ($roleList as $role) {
            if ($DividendInfo['level_up_type'] == 0) {//逐级升时调用
                if ($role['level'] != $userRoleLevel + 1) {//身份层级不等于下级级别时，跳过
                    continue;
                }
            } elseif ($role['level'] <= $userRoleLevel) {//当前分销身份低于等于用户现身份，跳过
                continue;
            }
            $fun = str_replace('/', '\\', '/distribution/sdtydw/' . $role['upleve_function']);
            if ($oldFun != $fun) {
                $oldFun = $fun;
                $Class = new $fun();
            }
            $res = $Class->judgeIsUp($user_id, $role, $orderInfo, $goodsList);//判断是否能升级
            if ($res == false) {//当前会员不执行升级，终止
                continue;//可跨级升级时调用
            }
            $upRole = $role;
            if ($DividendInfo['level_up_type'] == 0) {//逐级升时调用
                break;//跳出循环进行升级操作
            }

        }
        if (empty($upRole) == true) {
            return true;//没有找到可升级的身份终止
        }
        $upData['last_up_role_time'] = time();
        $upData['role_id'] = $upRole['role_id'];
        $res = $this->UsersModel->upInfo($user_id, $upData);
        if ($res < 1) {
            return false;
        }
        $inData['edit_id'] = $user_id;
        $inData['log_info'] = '';
        if ($orderInfo['d_type'] == 'role_order') {
            $inData['log_info'] = '购买身份商品，';
        }
        $inData['log_info'] .= '【' . ($usersInfo['role_id'] == 0 ? '粉丝' : $roleList[$usersInfo['role_id']]['role_name']) . '】升级为【' . $upRole['role_name'] . '】';
        $inData['module'] = request()->path();
        $inData['log_ip'] = request()->ip();
        $inData['log_time'] = time();
        $inData['user_id'] = 0;
        $LogSysModel->save($inData);
        return true;
    }

    /*------------------------------------------------------ */
    //-- 执行分佣到帐
    //-- order_id int 订单ID
    /*------------------------------------------------------ */
    public function evalArrival($order_id = 0, $limit_id = 0)
    {
        $time = time();
        $OrderModel = new OrderModel();
        if ($order_id > 0) {
            $where[] = ['order_id', '=', $order_id];
            $where[] = ['status', '=', $OrderModel->config['DD_SIGN']];
            $rows = $this->Model->where($where)->select()->toArray();
        } else {
            $settings = settings();
            $limit_time = $settings['shop_after_sale_limit'] * 86400;
            $where[] = ['status', '=', $OrderModel->config['DD_SIGN']];
            $where[] = ['update_time', '<', $time - $limit_time];
            $rows = $this->Model->where($where)->select()->toArray();
        }

        if (empty($rows)) return true;//没有找到相关佣金记录

        $AccountLogModel = new AccountLogModel();
        $log_id = [];
        foreach ($rows as $row) {
            $changedata['change_desc'] = '订单佣金到帐';
            $changedata['change_type'] = 4;
            $changedata['by_id'] = $row['order_id'];
            $changedata['balance_money'] = $row['dividend_amount'];
            $changedata['bean_value'] = $row['dividend_bean'];
            $changedata['total_dividend'] = ($row['dividend_amount'] + $row['dividend_bean']);
            $res = $AccountLogModel->change($changedata, $row['dividend_uid'], false);
            if ($res !== true) {
                return false;
            }
            $upDate['status'] = $OrderModel->config['DD_DIVVIDEND'];
            $upDate['limit_id'] = $limit_id;
            $upDate['update_time'] = $time;
            $res = $this->Model->where('log_id', $row['log_id'])->update($upDate);
            if ($res < 1) {
                return false;
            }
            $log_id[] = $row['log_id'];
        }
        return $log_id;
    }
    /*------------------------------------------------------ */
    //-- 退回分佣到帐,只有普通订单可操作
    //-- order_id int 订单ID
    /*------------------------------------------------------ */
    public function returnArrival($order_id = 0, $type = '')
    {
        $time = time();
        $OrderModel = new OrderModel();
        $where[] = ['order_id', '=', $order_id];
        $rows = $this->Model->where($where)->select()->toArray();
        if (empty($rows)) return true;//没有找到相关佣金记录

        $AccountLogModel = new AccountLogModel();
        foreach ($rows as $row) {
            $upDate['status'] = $type == 'unsign' ? $OrderModel->config['DD_SHIPPED'] : $OrderModel->config['DD_RETURNED'];
            $upDate['limit_id'] = 0;
            $upDate['update_time'] = $time;
            $res = $this->Model->where('log_id', $row['log_id'])->update($upDate);
            if ($res < 1) {
                return false;
            }
            if ($row['status'] == $OrderModel->config['DD_DIVVIDEND']) {
                $changedata['change_desc'] = $type == 'unsign' ? '订单撤销签收-退回佣金' : '订单退货-退回佣金';
                $changedata['change_type'] = 4;
                $changedata['by_id'] = $row['order_id'];
                $changedata['balance_money'] = $row['dividend_amount'] * -1;
                $changedata['bean_value'] = $row['dividend_bean'] * -1;
                $changedata['total_dividend'] = ($row['dividend_amount'] + $row['dividend_bean']) * -1;
                $res = $AccountLogModel->change($changedata, $row['dividend_uid'], false);
                if ($res !== true) {
                    return false;
                }
            }
        }
        return true;
    }
}