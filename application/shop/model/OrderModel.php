<?php

namespace app\shop\model;

use app\BaseModel;
use think\facade\Cache;
use think\Db;

use app\member\model\AccountLogModel;
use app\member\model\AccountModel;
use app\member\model\UsersModel;

//*------------------------------------------------------ */
//-- 订单表
/*------------------------------------------------------ */

class OrderModel extends BaseModel
{
    protected $table = 'shop_order_info';
    public $pk = 'order_id';
    protected $mkey = 'shop_order_mkey_';
    public $config = [];

    public function initialize()
    {
        parent::initialize();
        $this->config = config('config.');
    }
    /*------------------------------------------------------ */
    //-- 清除缓存
    /*------------------------------------------------------ */
    public function cleanMemcache($order_id = 0)
    {
        Cache::rm($this->mkey . $order_id);
        Cache::rm($this->mkey . '_goods_' . $order_id);
        if ($this->userInfo['user_id'] > 0) {
            Cache::rm($this->mkey . '_user_stat_' . $this->userInfo['user_id']);
        }
    }

    /*------------------------------------------------------ */
    //-- 获取会员订单信息汇总
    /*------------------------------------------------------ */
    public function userOrderStats($user_id = 0)
    {
        $user_id = $user_id * 1;
        $mkey = $this->mkey . '_user_stat_' . $user_id;
        $info = Cache::get($mkey);
        if (empty($info) == false) return $info;
        $where[] = ['order_type', '=', 0];
        $where[] = ['user_id', '=', $user_id];
        $where[] = ['order_status', 'in', [0, 1]];
        $info['all_num'] = $this->where($where)->count('order_id');//全部非取消订单
        unset($where);
        $where[] = ['order_type', '=', 0];
        $where[] = ['user_id', '=', $user_id];
        $where[] = ['is_pay', '=', 1];
        $where[] = ['order_status', '=', '0'];
        $where[] = ['pay_status', '=', '0'];
        $info['wait_pay_num'] = $this->where($where)->count('order_id');//待支付
        unset($where);
        $where[] = ['order_type', '=', 0];
        $where[] = ['user_id', '=', $user_id];
        $where[] = ['order_status', '=', '1'];
        $where[] = ['shipping_status', '=', '0'];
        $info['wait_shipping_num'] = $this->where($where)->where("pay_status = 1 OR is_pay = 0")->count('order_id');//待发货
        unset($where);
        $where[] = ['order_type', '=', 0];
        $where[] = ['user_id', '=', $user_id];
        $where[] = ['order_status', '=', '1'];
        $where[] = ['shipping_status', '=', '1'];
        $info['wait_sign_num'] = $this->where($where)->count('order_id');//待签收
        Cache::set($mkey, $info, 30);
        return $info;
    }
    /*------------------------------------------------------ */
    //-- 生成订单编号
    /*------------------------------------------------------ */
    public function getOrderSn()
    {
        /* 选择一个随机的方案 */
        mt_srand((double)microtime() * 1000000);
        $date = date('Ymd');
        $order_sn = $date . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $where[] = ['order_sn', '=', $order_sn];
        $where[] = ['add_time', '>', strtotime($date)];
        $count = $this->where($where)->count('order_id');
        if ($count > 0) return $this->getOrderSn();
        return $order_sn;
    }
    /*------------------------------------------------------ */
    //-- 获取订单信息
    /*------------------------------------------------------ */
    function info($order_id, $iscache = true)
    {
        if ($iscache == true) {
            $info = Cache::get($this->mkey . $order_id);
        }
        if ($info['order_id'] < 1) {
            $info = $this->where('order_id', $order_id)->find();
            if (empty($info)) return array();
            $info = $info->toArray();
            try {
                //未记录提成，并且不需要拆单
                if ($info['is_dividend'] == 0 && $info['is_split'] == 0) {
                    Db::startTrans();//启动事务
                    $status = 0;
                    if ($info['shipping_status'] == 2){//订单已签收，重新计算时直接设为已签收待分佣金
                        $status = $this->config['DD_SIGN'];
                    }elseif($info['pay_status'] == 1){//订单已支付
                        $status = $this->config['DD_PAYED'];
                    }
                    $upData = $this->distribution($info, 'add',$status);
                    $res = 0;
                    if (is_array($upData) == true) {
                        $upData['is_dividend'] = 1;
                        $res = $this->where('order_id', $order_id)->update($upData);
                    }
                    if ($res > 0) {
                        Db::commit();// 提交事务
                        $info = $this->where('order_id', $order_id)->find()->toArray();
                    } else {
                        Db::rollback();// 回滚事务
                    }
                }//end

                //执行支付成功后的相关处理
                if ($info['is_pay_eval'] == 1) {
                    $this->paySuccessEval($info);
                }//end
            } catch (Exception $e) {
            }

            $orderGoods = $this->orderGoods($order_id);
            $info['goodsList'] = $orderGoods['goodsList'];
            $info['allNum'] = $orderGoods['allNum'];
            $info['isReview'] = $orderGoods['isReview'];
            //end
            Cache::set($this->mkey . $order_id, $info, 30);
        }

        $info['exp_price'] = explode('.', $info['order_amount']);

        $time = time();
        $info['isCancel'] = 0;
        $info['isPay'] = 0;
        $info['isDel'] = 0;
        $info['isSign'] = 0;
        $info['isRefund'] = 0;
        $info['isAfterSale'] = 0;

        if ($info['is_split'] == 2) {//订单已拆分后，终止
            $info['ostatus'] = '已拆分';
            return $info;
        }

        if ($info['order_status'] == $this->config['OS_UNCONFIRMED']) {//订单未确定
            if ($info['is_pay'] > 0 && $info['pay_status'] == $this->config['PS_UNPAYED']) {
                $info['isCancel'] = 1;//可操作：取消
                $info['isPay'] = 1;//可操作：支付
                $info['ostatus'] = '待付款';
                $shop_order_auto_cancel = settings('shop_order_auto_cancel');

                if ($info['order_type'] == 0 && $shop_order_auto_cancel > 0) {//下单时间，超过未付款的自动取消订单
                    $info['countdown'] = 1;
                    $if_time = $info['cancel_time'] > $info['add_time'] ? $info['update_time'] : $info['add_time'];
                    $info['last_time'] = $if_time + ($shop_order_auto_cancel * 60) - $time;
                    if ($if_time < $time - $shop_order_auto_cancel * 60) {
                        $upData['order_id'] = $order_id;
                        $upData['order_status'] = $this->config['OS_CANCELED'];
                        $upData['cancel_time'] = $time;
                        $res = $this->upInfo($upData, 'sys');
                        if ($res == true) {
                            $info['ostatus'] = '已取消';
                        }
                    }
                }
            } else {
                $info['ostatus'] = '待确认';
                $info['isCancel'] = 1;//可操作：取消
            }
        } elseif ($info['order_status'] == $this->config['OS_CONFIRMED']) {
            if ($info['shipping_status'] == $this->config['SS_UNSHIPPED']) {
                $info['ostatus'] = '待发货';
                if ($info['order_type'] == 2) {//拼团订单
                    if ($info['is_success'] == 0) {
                        $info['ostatus'] = '拼团中';
                    }
                }
                $info['isRefund'] = 1;
            } elseif ($info['shipping_status'] == $this->config['SS_SHIPPED']) {
                $info['ostatus'] = '已发货';
                $info['isSign'] = 1;
            } elseif ($info['shipping_status'] == $this->config['SS_SIGN']) {
                $info['ostatus'] = '已完成';
                $shop_after_sale_limit = settings('shop_after_sale_limit');
                if ($shop_after_sale_limit > 0 && $info['back_dividend_amount'] == 0){//开启售后,back_dividend_amount>0时不能售后
                    if ($info['sign_time'] > time() - $shop_after_sale_limit * 86400){
                        $info['isAfterSale'] = 1;//可操作：申请售后
                    }
                }
            }

            if ($info['is_pay'] > 0 && $info['pay_status'] == $this->config['PS_UNPAYED']) {
                $info['isCancel'] = 1;//可操作：取消
                $info['isPay'] = 1;//可操作：支付
                $info['ostatus'] = '待付款';
            }
        } elseif ($info['order_status'] == $this->config['OS_RETURNED']) {
            $info['ostatus'] = '退货';
        } else {
            if ($info['is_del'] == 0) {
                if ($info['pay_status'] == 1) {
                    $info['ostatus'] = '取消待退款';
                } else {
                    $info['isDel'] = 1;
                    $info['ostatus'] = '已取消';
                    if ($info['pay_status'] == 2) {
                        $info['ostatus'] = '已取消，已退款';
                    }
                }
            } else {
                $info['ostatus'] = '已删除';
            }
        }
        return $info;
    }
    /*------------------------------------------------------ */
    //-- 获取订单商品
    //-- $order_id int 订单ID
    /*------------------------------------------------------ */
    function orderGoods($order_id)
    {
        static $OrderGoodsModel;
        if (empty($OrderGoodsModel)) {
            $OrderGoodsModel = new OrderGoodsModel();
        }
        $rows = $OrderGoodsModel->order('rec_id ASC')->where('order_id', $order_id)->select()->toArray();
        if (empty($rows)) return array();
        $allNum = 0;
        $isReview = 0;//能否评论
        foreach ($rows as $key => $row) {
            $row['exp_price'] = explode('.', $row['sale_price']);
            $goodsList[] = $row;
            $allNum += $row['goods_number'];
            if ($row['is_evaluate'] == 1 && $isReview == 0) {
                $isReview = 1;
            }
        }
        return ['goodsList'=>$goodsList, 'allNum'=>$allNum, 'isReview'=>$isReview];
    }

    /*------------------------------------------------------ */
    //-- 写入订单日志
    /*------------------------------------------------------ */
    function _log(&$order, $logInfo = '')
    {
        return OrderLogModel::_log($order, $logInfo);
    }

    /**更新订单信息
     * @param $upData
     * @param string $extType 扩展执行内容
     * @param bool $isTrans 是否启动事务
     * @return bool|string
     */
    function upInfo($upData, $extType = '')
    {
        $order_id = $upData['order_id'];
        unset($upData['order_id']);
        $orderInfo = $this->where('order_id', $order_id)->find();
        if (empty($orderInfo)) return '订单不存在.';
        $orderInfo = $orderInfo->toArray();

        $lockWhere = [];    //乐观锁
        $is_admin = true;   //后台管理操作

        if ($extType != 'sys' && defined('AUID') == false) {
            $is_admin = false;
            if ($this->userInfo['user_id'] != $orderInfo['user_id']) {
                return '无权操作';
            }
        }
        if ($upData['is_del'] == 1 && $orderInfo['order_status'] != $this->config['OS_CANCELED']) {
            return '订单未取消不能删除.';
        }
        if ($orderInfo['is_split'] == 2) {
            return '此订单已拆分，不能进行操作.';
        }


        Db::startTrans();//启动事务
        $GoodsModel = new GoodsModel();
        $OrderGoodsModel = new OrderGoodsModel();
        $AccountLogModel = new AccountLogModel();

        $time = time();
        if ($upData['order_status'] == $this->config['OS_CONFIRMED']) {//确认订单
            if ($upData['pay_status'] == $this->config['PS_PAYED']) {//订单支付成功
                if ($orderInfo['pay_code'] == 'balance') {//使用余额支付扣减用户余额
                    $upData['money_paid'] = $orderInfo['order_amount'];
                    $upData['pay_time'] = time();
                    $changedata['change_desc'] = '订单余额支付';
                    $changedata['change_type'] = 3;
                    $changedata['by_id'] = $order_id;
                    $changedata['balance_money'] = $orderInfo['order_amount'] * -1;
                    $res = $AccountLogModel->change($changedata, $orderInfo['user_id'], false);
                    if ($res !== true) {
                        Db::rollback();// 回滚事务
                        return '支付失败，扣减余额失败.';
                    }
                    $balance_money = (new AccountModel)->where('user_id',$orderInfo['user_id'])->value('balance_money');
                    if ($balance_money < 0){
                        Db::rollback();// 回滚事务
                        return '支付失败，扣减余额失败.';
                    }
                }elseif($upData['pay_code'] == 'balance') {//使用余额支付扣减用户余额
                    $upData['money_paid'] = $orderInfo['order_amount'];
                    $upData['pay_time'] = time();
                    $changedata['change_desc'] = '订单余额支付';
                    $changedata['change_type'] = 3;
                    $changedata['by_id'] = $order_id;
                    $changedata['balance_money'] = $orderInfo['order_amount'] * -1;
                    $res = $AccountLogModel->change($changedata, $orderInfo['user_id'], false);
                    if ($res !== true) {
                        Db::rollback();// 回滚事务
                        return '支付失败，扣减余额失败.';
                    }
                    $balance_money = (new AccountModel)->where('user_id',$orderInfo['user_id'])->value('balance_money');
                    if ($balance_money < 0){
                        Db::rollback();// 回滚事务
                        return '支付失败，扣减余额失败.';
                    }
                }
                //未支付状态的订单才进行支付成功的操作,否则失败回滚
                $lockWhere['pay_status'] = $this->config['PS_UNPAYED'];
            }
            $upData['confirm_time'] = $time;
        } elseif ($upData['order_status'] == $this->config['OS_CANCELED']) {//取消订单
            //非后台操作的订单，只有未取消过的才能取消
            if(!$is_admin){
                $lockWhere[] = ['cancel_time','eq',0];
            }
            $res = $this->distribution($orderInfo, 'cancel');//提成处理
            if ($res != true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }
            //退还订单积分
            if ($orderInfo['use_integral'] > 0) {
                $inData['use_integral'] = $orderInfo['use_integral'];
                $inData['change_type'] = 3;
                $inData['by_id'] = $orderInfo['order_id'];
                $inData['change_desc'] = '订单退还积分:' . $orderInfo['use_integral'];
                $res = $AccountLogModel->change($inData, $orderInfo['user_id']);
                if ($res != true) {
                    Db::rollback();//回滚
                    return '订单退还积分失败.';
                }
            }//end
            if ($orderInfo['is_stock'] == 1) {//执行商品库存和销量处理
                $orderGoods = $this->orderGoods($order_id);
                if ($orderInfo['order_type'] == 1) {//积分订单
                    $res = (new \app\integral\model\IntegralGoodsListModel)->evalGoodsStore($orderGoods['goodsList'], 'cancel');
                    if ($res != true) {
                        Db::rollback();//回滚
                        return '取消积分订单退库存失败.';
                    }
                } elseif ($orderInfo['order_type'] == 2) {//拼团订单
                    $goods = $orderGoods['goodsList'][0];
                    $res = (new \app\fightgroup\model\FightGoodsModel)->evalGoodsStore($orderInfo['by_id'], $goods['goods_id'], $goods['sku_id'], $goods['goods_number'], 'cancel');
                    if ($res != true) {
                        Db::rollback();//回滚
                        return '取消拼团订单退库存失败.';
                    }
                    if ($orderInfo['is_initiate'] == 1) {//取消团长订单，修改拼团失效时间为当前
                        $res = (new \app\fightgroup\model\FightGroupListModel)->where('gid', $orderInfo['pid'])->update(['fail_time' => time()]);
                        if ($res != true) {
                            Db::rollback();//回滚
                            return '取消拼团订单退库存失败.';
                        }
                    }
                } else {
                    $res = $GoodsModel->evalGoodsStore($orderGoods['goodsList'], 'cancel');
                    if ($res != true) {
                        Db::rollback();//回滚
                        return '取消订单退库存失败.';
                    }
                }
                $upData['is_stock'] = 0;
            }
        } elseif ($upData['pay_status'] === $this->config['PS_UNPAYED']) {//未付款,不执行退款操作，只更新
            $res = $this->distribution($orderInfo, 'unpayed');//提成处理
            if ($res != true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }
        } elseif ($upData['pay_status'] == $this->config['PS_RUNPAYED']) {//退款，退回帐户余额
            if ($orderInfo['money_paid'] > 0) {
                $refund_amount = $orderInfo['money_paid'] - $orderInfo['tuikuan_money'];//实付金额减去已退金额
                if (in_array($orderInfo['pay_code'] ,['balance','offline'])) {//线下打款和余额支付，退回余额
                    $inData['balance_money'] = $refund_amount;
                    $inData['change_type'] = 3;
                    $inData['by_id'] = $orderInfo['order_id'];
                    $inData['change_desc'] = '订单退款到余额:' . $orderInfo['money_paid'];
                    $res = $AccountLogModel->change($inData, $orderInfo['user_id']);
                    if ($res != true) {
                        Db::rollback();//回滚
                        return '订单退款到余额失败.';
                    }
                } else {//在线退款
                    $code = str_replace('/', '\\', "/payment/" . $orderInfo['pay_code'] . "/" . $orderInfo['pay_code']);
                    $payment = new $code();
                    $orderInfo['refund_amount'] = $refund_amount;//实付金额减去已退金额
                    $res = $payment->refund($orderInfo);
                    if ($res !== true) {
                        Db::rollback();//回滚
                        return '请求退款接口失败：' . $res;
                    }
                }
                //身份商品取消降级
                $this->demoteLevel($order_id);
            }

        } elseif ($upData['shipping_status'] == $this->config['SS_SHIPPED'] && $orderInfo['shipping_status'] == $this->config['SS_UNSHIPPED']) {//发货
            $res = $this->distribution($orderInfo, 'shipping');//提成处理
            if ($res != true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }
        } elseif ($upData['shipping_status'] === $this->config['SS_UNSHIPPED']) {//未发货
            $res = $this->distribution($orderInfo, 'unshipping');//提成处理
            if ($res !== true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }

        } elseif ($upData['shipping_status'] == $this->config['SS_SIGN']) {//签收
            //已发货状态下才能进行签收，二次签收会回滚
            $lockWhere[] = ['shipping_status','eq',$this->config['SS_SHIPPED']];
            //积分赠送
            $inData['total_integral'] = $orderInfo['give_integral'];
            $inData['use_integral'] = $inData['total_integral'];
            if ($inData['total_integral'] > 0) {
                $inData['change_type'] = 2;
                $inData['by_id'] = $orderInfo['order_id'];
                $inData['change_desc'] = '签收订单获取积分:' . $orderInfo['order_sn'];
//                $res = $AccountLogModel->change($inData, $orderInfo['user_id']);
//                if ($res != true) {
//                    Db::rollback();//回滚
//                    return '签收赠送积分失败.';
//                }
            }
            unset($inData);
            $res = $this->distribution($orderInfo, 'sign');    //提成处理
            if ($res != true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }
            //修改订单商品为待评价
            $OrderGoodsModel->where('order_id', $order_id)->update(['is_evaluate' => 1]);
            $shop_after_sale_limit = settings('shop_after_sale_limit');
            $upData['settlement_time'] = $time + $shop_after_sale_limit * 86400;
        } elseif ($upData['order_status'] == $this->config['OS_RETURNED']) {//退货
            $res = $this->distribution($orderInfo, 'returned');//提成处理
            if ($res != true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }
            if ($orderInfo['is_evaluate'] == 1) {
                //修改订单商品未评价的设为不需要评价
                $res = $OrderGoodsModel->where('order_id', $order_id)->update(['is_evaluate' => 0]);
                if ($res < 1) {
                    Db::rollback();//回滚
                    return '取消订单商品评价失败.';
                }
            }
        }

        if ($extType == 'changePrice' || $extType == 'editGoods') {//改价或修改商品
            $res = $this->distribution($orderInfo, 'change');//提成处理
            if ($res != true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }
        } elseif ($extType == 'unsign') {//撤销签收
            unset($where);
            //查询通过订单获取的积分,扣除
            $where[] = ['by_id', '=', $orderInfo['order_id']];
            $where[] = ['change_type', '=', 2];
            $log = $AccountLogModel->where($where)->find();
            if ($log['use_integral'] > 0) {
                unset($inData);
                $inData['total_integral'] = intval($orderInfo['total_amount']) * -1;
                $inData['use_integral'] = $inData['total_integral'];
                $inData['change_type'] = 2;
                $inData['by_id'] = $orderInfo['order_id'];
                if ($extType == 'unsign') {
                    $inData['change_desc'] = '撤销签收退还积分:' . $orderInfo['order_sn'];
                } else {
                    $inData['change_desc'] = '退货退还积分:' . $orderInfo['order_sn'];
                }
//                $res = $AccountLogModel->change($inData, $orderInfo['user_id']);
//                if ($res != true) {
//                    Db::rollback();//回滚
//                    return '退还积分失败.';
//                }
            }
            //修改订单商品为不能评价
            $OrderGoodsModel->where('order_id', $order_id)->update(['is_evaluate' => 0]);

            $res = $this->distribution($orderInfo, 'unsign');//提成处理
            if ($res != true) {
                Db::rollback();// 回滚事务
                return '佣金处理失败.';
            }
        }elseif ($extType == 'recover') {//恢复订单
            $orderGoods = $this->orderGoods($orderInfo['order_id']);
            if ($orderInfo['order_type'] == 1) {//积分订单
                $res = (new \app\integral\model\IntegralGoodsListModel)->evalGoodsStore($orderGoods['goodsList']);
            } elseif ($orderInfo['order_type'] == 2) {//拼团订单
                $goods = $orderGoods['goodsList'][0];
                $res = (new \app\fightgroup\model\FightGoodsModel)->evalGoodsStore($orderInfo['by_id'], $goods['goods_id'], $goods['sku_id'], $goods['goods_number']);
            } else {
                $res = (new GoodsModel)->evalGoodsStore($orderGoods['goodsList']);
            }
            if ($res !== true) {//扣库存失败，终止
                Db::rollback();// 回滚事务
                return '恢复订单，扣库存失败.';
            }
        }
        $upData['update_time'] = $time;
        if(!empty($lockWhere)){
            $res = $this->where('order_id', $order_id)->where($lockWhere)->update($upData);
        }else{
            $res = $this->where('order_id', $order_id)->update($upData);
        }
        if ($res < 1) {
            Db::rollback();
            return '订单更新失败.';
        }
        Db::commit();// 提交事务

        if ($upData['order_status'] == $this->config['OS_CANCELED'] || $upData['shipping_status'] == $this->config['SS_SHIPPED']) {
                //发送模板消息，给买家
                $WeiXinMsgTplModel = new \app\weixin\model\WeiXinMsgTplModel();
                $WeiXinUsersModel = new \app\weixin\model\WeiXinUsersModel();
                if ($upData['order_status'] == $this->config['OS_CANCELED']) {
                    $sendData['send_scene'] = 'order_cancel_msg';//订单取消通知
                } else {
                    $sendData['send_scene'] = 'order_shipping_msg';//订单发货通知
                    $sendData['shipping_name'] = $upData['shipping_name'];
                    $sendData['invoice_no'] = $upData['invoice_no'];
                }
                $sendData['openid'] = $WeiXinUsersModel->where('user_id', $orderInfo['user_id'])->value('wx_openid');
                $sendData['order_id'] = $orderInfo['order_id'];
                $sendData['order_sn'] = $orderInfo['order_sn'];
                $sendData['consignee'] = $orderInfo['consignee'];
                $sendData['order_amount'] = $orderInfo['order_amount'];
                $sendData['add_time'] = $orderInfo['add_time'];
                $WeiXinMsgTplModel->send($sendData);
        }
        $this->cleanMemcache($order_id);
        return true;
    }

    /**订单后台操作权限
     * @param array $order 订单详情
     * @param bool $by_supplyer 供应商管理
     * @return array
     */
    public function operating(&$order,$by_supplyer = false)
    {
        if ($order['is_split'] == 2){//已拆分订单主订单不能操作
            return [];
        }
        $os = $order['order_status'];
        $ss = $order['shipping_status'];
        $ps = $order['pay_status'];
        $time = time();
        if ($by_supplyer == true){
            if ($os == $this->config['OS_CONFIRMED']) { //已确认
                if ($ss == $this->config['SS_UNSHIPPED']) {//未发货
                    $operating['shipping'] = true;
                } elseif ($ss == $this->config['SS_SHIPPED']) {//已发货
                    $operating['sign'] = true;
                    $operating['unshipping'] = true;//设为未发货
                    $operating['returned'] = true;//设为退货
                }
            }
            return $operating;
        }
        if ($os == $this->config['OS_UNCONFIRMED']) {//未确认
            $operating['isCancel'] = true;//取消
            if ($order['pay_id'] == 1) $operating['confirmed'] = true;//确认
            $operating['changePrice'] = true; //改价
            $operating['editConsignee'] = true; //修改收货信息
            $operating['editGoods'] = true; //修改商品
            if ($order['is_pay'] == 2) $operating['cfmCodPay'] = true;//设为已付款
        } elseif ($os == $this->config['OS_CONFIRMED']) { //已确认
            if ($ss == $this->config['SS_UNSHIPPED']) {//未发货
                $operating['isCancel'] = true;
                if ($ps == $this->config['PS_UNPAYED']) {//未支付
                    $operating['changePrice'] = true;//改价
                    $operating['editGoods'] = true; //修改商品
                } elseif ($ps == $this->config['PS_PAYED']) {//已支付
                    if ($order['is_pay'] == 2) {
                        $operating['setUnPay'] = true;//设为未付款
                    }
                }
                $operating['shipping'] = true;
                $operating['editConsignee'] = true; //修改收货信息
            } elseif ($ss == $this->config['SS_SHIPPED']) {//已发货
                $operating['sign'] = true;
                $operating['unshipping'] = true;//设为未发货
                $operating['returned'] = true;//设为退货
                unset($operating['unconfirmed']);
            } elseif ($ss == $this->config['SS_SIGN']) {//已签收
                if (($order['sign_time'] > $time - 604800)) {
                   //使用售后 $operating['returned'] = true;//设为退货
                    $operating['unsign'] = true;//设为未签收
                }
                unset($operating['unconfirmed']);
            } else {
                $operating['isCancel'] = true;
                $operating['changePrice'] = true;
            }
        } elseif ($os == $this->config['OS_RETURNED']) { //已退货
            if ($ps == $this->config['PS_PAYED']) {//退货后可操作退款
                $operating['returnPay'] = true;
            }
        } elseif ($os == $this->config['OS_CANCELED']) { //已关闭
            if ($order['cancel_time'] > $time - 604800) $operating['recover'] = true;//恢复订单
            if ($ps == $this->config['PS_PAYED']) {//取消后可操作退款
                $operating['returnPay'] = true;
            }
        } else {
            $operating['confirmed'] = true;//确认
        }
        return $operating;
    }
    /*------------------------------------------------------ */
    //-- 自动收货
    /*------------------------------------------------------ */
    public function autoSign($uid = 0)
    {
        $where = [];
        $sign_limit = settings('shop_auto_sign_limit');
        $where[] = ['shipping_status', '=', $this->config['SS_SHIPPED']];
        $where[] = ['shipping_time', '<', time() - ($sign_limit * 86400)];
        if ($uid > 0) {
            $where[] = ['user_id', '=', $uid];
        }
        $time = time();
        $order_ids = $this->where($where)->column('order_id');
        foreach ($order_ids as $order_id) {
            $upData['order_id'] = $order_id;
            $upData['shipping_status'] = $this->config['SS_SIGN'];
            $upData['sign_time'] = $time;
            $res = $this->upInfo($upData);
            if ($res === true) {
                $orderInfo = $this->info($order_id);
                $this->_log($orderInfo, '自动签收');
            }
        }
        return true;
    }
    /*------------------------------------------------------ */
    //-- 提成处理&升级处理
    /*------------------------------------------------------ */
    public function distribution(&$orderInfo, $type = '',$status=0)
    {
        if (empty($orderInfo)) return false;
        if ($orderInfo['order_type'] == 1) {//积分商品不返利
            return true;
        }
        if ($orderInfo['is_split'] > 0){//拆分的主订单不执行
            return true;
        }
        $orderInfo['d_type'] = 'order';//普通订单
        return (new \app\distribution\model\DividendModel)->_eval($orderInfo, $type,$status);
    }


    /*------------------------------------------------------ */
    //-- 订单支付时, 获取订单商品名称
    //-- $order_id int 订单ID
    /*------------------------------------------------------ */
    public function getPayBody($order_id)
    {
        if (empty($order_id)) return "订单ID参数错误";
        $goodsNames = (new OrderGoodsModel)->where('order_id', $order_id)->column('goods_name');
        $gns = implode($goodsNames, ',');
        $payBody = getSubstr($gns, 0, 18);
        return $payBody;
    }
    /*------------------------------------------------------ */
    //-- 拆分订单
    /*------------------------------------------------------ */
    public function splitOrder($order_id)
    {
        static $OrderGoodsModel;
        $orderInfo = $this->find($order_id);
        if (empty($orderInfo)){
            return false;
        }
        $orderInfo = $orderInfo->toArray();
        if ($orderInfo['is_split'] != 1) {
            return false;
        }
        if (empty($OrderGoodsModel)) {
            $OrderGoodsModel = new OrderGoodsModel();
        }

        $orderGoods = $OrderGoodsModel->order('rec_id ASC')->where('order_id', $orderInfo['order_id'])->select()->toArray();
        $oglist = [];

        foreach ($orderGoods as $og) {
            if (empty($oglist[$og['supplyer_id']])) {
                $oglist[$og['supplyer_id']]['settle_price'] = 0;
                $oglist[$og['supplyer_id']]['goods_amount'] = 0;
                $oglist[$og['supplyer_id']]['discount'] = 0;
                $oglist[$og['supplyer_id']]['goods_sn'] = [];
                $oglist[$og['supplyer_id']]['goods_list'] = [];
            }
            $oglist[$og['supplyer_id']]['give_integral'] += $og['give_integral'] * $og['goods_number'];
            $oglist[$og['supplyer_id']]['use_integral'] += $og['use_integral'] * $og['goods_number'];
            $oglist[$og['supplyer_id']]['settle_price'] += $og['settle_price'] * $og['goods_number'];
            $oglist[$og['supplyer_id']]['goods_amount'] += $og['sale_price'] * $og['goods_number'];
            if ($og['shop_price'] > $og['sale_price']) {
                $oglist[$og['supplyer_id']]['discount'] += ($og['shop_price'] - $og['sale_price']) * $og['goods_number'];
            }
            $oglist[$og['supplyer_id']]['buy_goods_sn'][] = $og['goods_sn'];
            $oglist[$og['supplyer_id']]['goods_ids'][] = $og['goods_id'];
            $oglist[$og['supplyer_id']]['goods_list'][] = $og;
        }
        $shipping_fee_detail = json_decode($orderInfo['shipping_fee_detail'],true);
        $i = 1;
        foreach ($oglist as $key => $sogl) {
            $inArr = $orderInfo;
            unset($inArr['order_id']);
            $inArr['order_sn'] .= '-' . $i;
            $inArr['is_split'] = 0;
            $inArr['supplyer_id'] = $key;
            $inArr['give_integral'] = $sogl['give_integral'];
            $inArr['use_integral'] = $sogl['use_integral'];
            $inArr['pid'] = $orderInfo['order_id'];
            $inArr['buy_goods_sn'] = join(',', $sogl['buy_goods_sn']);
            $inArr['buy_goods_id'] = join(',', $sogl['goods_ids']);
            $inArr['discount'] = $sogl['discount'];
            $inArr['goods_amount'] = $sogl['goods_amount'];
            //使用相关优惠处理
            $scale = $sogl['goods_amount'] / $orderInfo['goods_amount'];//对比总订单商品价格占比
            $inArr['use_bonus'] = 0;
            if ($orderInfo['use_bonus'] > 0) {
                $inArr['use_bonus'] = $orderInfo['use_bonus'] * $scale;
            }
            $inArr['shipping_fee'] = 0;
            if ($orderInfo['shipping_fee'] > 0) {
                if (empty($shipping_fee_detail[$key]) == false){
                    $inArr['shipping_fee'] = $shipping_fee_detail[$key];
                }else{
                    $inArr['shipping_fee'] = $orderInfo['shipping_fee'] * $scale;
                }
            }
            $inArr['settle_goods_price'] = $sogl['settle_price'];
            $inArr['settle_price'] = $sogl['settle_price'] + $inArr['shipping_fee'];
            $inArr['diy_discount'] = 0;
            if ($orderInfo['diy_discount'] > 0) {
                $inArr['diy_discount'] = $orderInfo['diy_discount'] * $scale;
            }
            $inArr['order_amount'] = $inArr['goods_amount'] - $inArr['use_bonus'] - $inArr['diy_discount'] + $inArr['shipping_fee'];
            $inArr['money_paid'] = $inArr['order_amount'];
            //end
            $res = $this->create($inArr);
            $order_id = $res->order_id;
            if ($order_id < 1) return false;
            foreach ($sogl['goods_list'] as $goods) {
                $goods['order_id'] = $order_id;
                unset($goods['rec_id']);
                $res = $OrderGoodsModel->create($goods);
                if ($res->rec_id < 1) return false;
            }
            $inArr['order_id'] = $order_id;
            $this->_log($inArr, '订单拆分：' . $orderInfo['order_sn'] . '，拆分生成子订单');
            $i++;
        }

        return true;
    }
    /*------------------------------------------------------ */
    //-- 订单支付成功处理
    /*------------------------------------------------------ */
    public function updatePay($upData = [], $_log = '支付成功')
    {
        unset($upData['order_amount']);
        $upData['pay_status'] = $this->config['PS_PAYED'];
        $upData['order_status'] = $this->config['OS_CONFIRMED'];
        $upData['pay_time'] = time();
        $upData['is_pay_eval'] = 1;//设为待执行支付成功后的相关处理
        $res = $this->upInfo($upData, 'sys');
        if ($res !== true) {
            return false;
        }
        $orderInfo = $this->find($upData['order_id'])->toArray();
        $this->_log($orderInfo,$_log);
        $this->paySuccessEval($orderInfo);
        return true;
    }
    /*------------------------------------------------------ */
    //-- 支付成功后执行
    /*------------------------------------------------------ */
    function paySuccessEval(&$orderInfo)
    {

        //执行库存扣除，下单时未扣库存，则支付成功后扣除
        //先扣库存才能拆分订单，拆分订单时不扣库存
        if ($orderInfo['is_stock'] == 0) {
            $orderGoods= $this->orderGoods($orderInfo['order_id']);
            Db::startTrans();//启动事务
            if ($orderInfo['order_type'] == 1) {//积分订单
                $res = (new \app\integral\model\IntegralGoodsListModel)->evalGoodsStore($orderGoods['goodsList']);
            } else {
                $res = (new GoodsModel)->evalGoodsStore($orderGoods['goodsList']);
            }
            if ($res !== true) {//扣库存失败，终止
                Db::rollback();// 回滚事务
                return false;
            }
            $upData['is_stock'] = 1;
            $res = $this->where('order_id',$orderInfo['order_id'])->update($upData);
            if ($res < 1){
                Db::rollback();// 回滚事务
                return false;
            }
            Db::commit();// 提交事务
            $orderInfo['is_stock'] = 1;
        }//end

        //确认订单，执行拆单处理，独立出来并外部使用事务
        if ($orderInfo['is_split'] == 1) {
            Db::startTrans();//启动事务
            $res = $this->splitOrder($orderInfo['order_id']);
            if ($res == true) {
                $upData['is_split'] = 2;
                $res = $this->where('order_id',$orderInfo['order_id'])->update($upData);
                if ($res > 0){
                    $this->_log($orderInfo,'拆分订单');
                    Db::commit();// 提交事务
                    return false;//订单被拆分后，终止，不执行下面的处理
                }
            }
            Db::rollback();// 回滚事务
        }//end
        $UsersModel =  new \app\member\model\UsersModel();
        //如果设置支付再绑定关系时执行
        $bind_pid_time = settings('bind_pid_time');
        if ($bind_pid_time == 1){//支付成功时绑定关系
            $UsersModel->regUserBind($orderInfo['user_id']);
        }//end
        $UsersModel->upInfo($orderInfo['user_id'],['last_buy_time'=>time()]);//更新会员最后购买时间
        Db::startTrans();//启动事务
        $res = $this->distribution($orderInfo, 'pay');//提成处理
        if ($res != true) {
            Db::rollback();// 回滚事务
            return '佣金处理失败.';
        }

        $upData['is_pay_eval'] = 2;
        $this->where('order_id',$orderInfo['order_id'])->update($upData);


        $AccountLogModel = new AccountLogModel();
        $inData['total_integral'] = $orderInfo['give_integral'];
        $inData['use_integral'] = $inData['total_integral'];
        if ($inData['total_integral'] > 0) {
            $inData['change_type'] = 2;
            $inData['by_id'] = $orderInfo['order_id'];
            $inData['change_desc'] = '下单获取积分:' . $orderInfo['order_sn'];
            $res = $AccountLogModel->change($inData, $orderInfo['user_id']);
            if ($res != true) {
                Db::rollback();//回滚
                return '下单获取积分失败.';
            }
        }
        unset($inData);
//        $UsersModel = new \app\member\model\UsersModel();
//        $user_role = $UsersModel->where('user_id',$orderInfo['user_id'])->value('role_id');
//        if($user_role <= 0){
//            $role_cid = settings('role_cid');
//            $OrderGoods = (new OrderGoodsModel)->where('order_id',$orderInfo['order_id'])->select()->toArray();
//            if($OrderGoods)foreach ($OrderGoods as $v){
//                $category_pid_arr = (new \app\shop\model\CategoryModel)->getParentCateIds($v['cid']);
//                if(in_array($role_cid,$category_pid_arr)){//判断是否在身份专区分类下
//                    $UsersModel->where('user_id',$orderInfo['user_id'])->update(['role_id'=>1]);
//                }
//            }
//        }
        Db::commit();// 提交事务
        return true;
    }
    /*------------------------------------------------------ */
    //-- 计算某会员购买某分类商品数量
    /*------------------------------------------------------ */
    function buyGoodsNum($user_id,$cids){
        if($user_id <= 0 || count($cids) <= 0)return false;
        return $this->alias('i')
            ->join('shop_order_goods g','i.order_id=g.order_id')
            ->where(['i.user_id'=>$user_id,'pay_status'=>1,'i.is_after_sale'=>0])
            ->where('g.cid',['in',$cids])
            ->count();
    }
    /*------------------------------------------------------ */
    //-- 判断是否降级，退货退款时触发
    /*------------------------------------------------------ */
    function demoteLevel($order_id){
        $orderInfo = $this->where('order_id', $order_id)->find();
        $role_num = false;
        $role_cid = settings('role_cid');
        $Category = new CategoryModel();
        $role_cids = $Category->getSonCateIds($role_cid);
        $role_cids[] = $role_cid;
        $OrderGoodsModel = new OrderGoodsModel();
        $orderGoodsInfo = $OrderGoodsModel->where('order_id', $order_id)->select();
        if($orderGoodsInfo)foreach ($orderGoodsInfo as $k=>$v){
        if(in_array($v['cid'],$role_cids))$role_num = true;
        }
        if($role_num) {
            $UsersModel = new UsersModel();
            $UsersModel->where('user_id',$orderInfo['user_id'])->update(['role_id'=>'0']);
        }
    }
}
