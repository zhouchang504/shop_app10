<?php

namespace app\shop\model;

use app\BaseModel;
use think\facade\Cache;
use think\facade\Env;
use think\Db;
//*------------------------------------------------------ */
//-- 订单表
/*------------------------------------------------------ */

class OrderModel extends BaseModel
{
    protected $table = 'shop_order_info';
    public $pk = 'order_id';
    protected $mkey = 'shop_order_mkey_';
    public $config = [];

    public function initialize(){
        parent::initialize();
        $this->config = (include Env::get('app_path') . "shop/config/config.php");
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
    function userOrderStats($user_id = 0)
    {
        $user_id = $user_id * 1;
        $mkey = $this->mkey . '_user_stat_' . $user_id;
        $info = Cache::get($mkey);
        //if (empty($info)) return $info;
        $where[] = ['user_id', '=', $user_id];
        $where[] = ['order_status', 'in', [0, 1]];
        $info['all_num'] = $this->where($where)->count('order_id');//全部非取消订单
        unset($where);
        $where[] = ['user_id', '=', $user_id];
        $where[] = ['is_pay', '=', 1];
        $where[] = ['order_status', '=', '0'];
        $where[] = ['pay_status', '=', '0'];
        $info['wait_pay_num'] = $this->where($where)->count('order_id');//待支付
        unset($where);
        $where[] = ['user_id', '=', $user_id];
        $where[] = ['order_status', '=', '1'];
        $where[] = ['shipping_status', '=', '0'];
        $info['wait_shipping_num'] = $this->where($where)->where("pay_status = 1 OR is_pay = 0")->count('order_id');//待发货
        unset($where);
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
        if ($info['order_id'] < 1){
            $info = $this->where('order_id', $order_id)->find();
            if (empty($info)) return array();
            $info = $info->toArray();
            list($info['goodsList'], $info['allNum'],$info['isReview']) = $this->orderGoods($order_id);
            Cache::set($this->mkey . $order_id, $info, 30);
        }

        $time = time();
        $info['isCancel'] = 0;
        $info['isPay'] = 0;
        $info['isDel'] = 0;
        $info['isSign'] = 0;
        if ($info['order_status'] == $this->config['OS_UNCONFIRMED']) {
            if ($info['is_pay'] > 0  && $info['pay_status'] == $this->config['PS_UNPAYED']) {
                $info['isCancel'] = 1;
                $info['isPay'] = 1;
                $info['ostatus'] = '待付款';
                $shop_order_auto_cancel = settings('shop_order_auto_cancel');
                if ($shop_order_auto_cancel > 0 ) {//下单时间，超过未付款的自动取消订单
                    $info['countdown'] = 1;
                    $info['last_time'] =  $info['add_time'] + ($shop_order_auto_cancel * 60) - $time;
                    if ($info['add_time'] < $time - $shop_order_auto_cancel * 60){
                        $data['order_id'] = $order_id;
                        $data['order_status'] =$this->config['OS_CANCELED'];
                        $data['cancel_time'] = $time;
                        $res = $this->upInfo($data);
                        if ($res > 0) {
                            $info['ostatus'] = '已取消';
                        }
                    }
                }
            } else {
                $info['ostatus'] = '待确认';
                $info['isCancel'] = 1;
            }
        } elseif ($info['order_status'] == $this->config['OS_CONFIRMED']) {
            if ($info['shipping_status'] == $this->config['SS_UNSHIPPED']) {
                $info['ostatus'] = '待发货';
            } elseif ($info['shipping_status'] == $this->config['SS_SHIPPED']) {
                $info['ostatus'] = '已发货';
                $info['isSign'] = 1;
            } elseif ($info['shipping_status'] == $this->config['SS_SIGN']) {
                $info['ostatus'] = '已完成';
            }
            if ($info['pay_status'] == $this->config['PS_PAYED']) {
                $info['isCancel'] = 0;
            }
        } elseif ($info['order_status'] == $this->config['OS_RETURNED']) {
            $info['ostatus'] = '退货';
        } else {            
			if ($info['is_del'] == 0){
				 $info['isDel'] = 1;     
				 $info['ostatus'] = '已取消'; 
			}else{
				$info['ostatus'] = '已删除';
			}
        }
        $info['exp_price'] = explode('.', $info['order_amount']);
        return $info;
    }
    /*------------------------------------------------------ */
    //-- 获取订单商品
    /*------------------------------------------------------ */
    function orderGoods($order_id)
    {
        $OrderGoodsModel = new OrderGoodsModel();
        $rows = $OrderGoodsModel->order('rec_id ASC')->where('order_id', $order_id)->select()->toArray();
        if (empty($rows)) return array();
        $allNum = 0;
        $isReview = 0;//能否评论
        foreach ($rows as $key => $row) {
            $row['exp_price'] = explode('.', $row['goods_price']);
            $goodsList[$row['goods_id'] . '_' . $row['sku_val']] = $row;
            $allNum += $row['goods_number'];
            if ($row['is_evaluate'] == 1 && $isReview == 0){
                $isReview = 1;
            }
        }
        return [$goodsList, $allNum,$isReview];
    }

    /*------------------------------------------------------ */
    //-- 写入订单日志
    /*------------------------------------------------------ */
    function _log(&$order,$logInfo='')
    {
        return OrderLogModel::_log($order,$logInfo);
    }
    /*------------------------------------------------------ */
    //-- 更新订单信息
    /*------------------------------------------------------ */
    function upInfo($upData,$extType = '')
    {
        $order_id = $upData['order_id'];
        unset($upData['order_id']);
        $orderInfo = $this->where('order_id',$order_id)->find()->toArray();

        if (empty($orderInfo)) return '订单不存在.';
        if (defined('AUID') == false){
            if($this->userInfo['user_id'] != $orderInfo['user_id']){
                return '无权操作';
            }
        }

        if ($upData['is_del'] == 1 && $orderInfo['order_status'] != $this->config['OS_CANCELED'] ){
            return '订单未取消不能删除.';
        }

        Db::startTrans();//启动事务
        $GoodsModel = new GoodsModel();
        $OrderGoodsModel = new OrderGoodsModel();
        $AccountLogModel = new \app\member\model\AccountLogModel();

        if ($upData['order_status'] == $this->config['OS_CONFIRMED']) {//确认订单
            //提成处理

            //end
            //订单支付成功
            if ($upData['pay_status'] == $this->config['PS_PAYED']){
                if ($upData['is_stock'] == 1) {//没有执行扣库存执行库存扣除
                    $goodsList = $this->orderGoods($order_id);
                    $res = $GoodsModel->evalGoodsStore($goodsList['goodsList']);
                    if ($res !== true) {
                        Db::rollback();// 回滚事务
                        return '支付扣库存失败.';
                    }
                }
            }
        } elseif ($upData['order_status'] == $this->config['OS_CANCELED'] ) {//取消订单
            //提成处理

            //end
            //执行商品库存和销量处理
            if ($orderInfo['is_stock'] == 1) {
                $res = $GoodsModel->evalGoodsStore($orderInfo['goodsList'], 'cancel');
                if ($res != true) {
                    Db::rollback();//回滚
                    return '取消订单退库存失败.';
                }
                $upData['is_stock'] = 0;
            }
        } elseif ($upData['pay_status'] == $this->config['PS_RUNPAYED']   ){//退款

        } elseif ($upData['shipping_status'] == $this->config['SS_SHIPPED']) {//发货
            //提成处理
            //end

        } elseif ($upData['shipping_status'] == $this->config['SS_UNSHIPPED']) {//未发货
            //提成处理
            //end

        } elseif ($upData['shipping_status'] == $this->config['SS_SIGN']) {//签收
            //积分赠送
            $inData['total_integral'] = intval($orderInfo['total_amount']);
            $inData['use_integral'] = $inData['total_integral'];
            if ($inData['total_integral'] > 0) {
                $inData['change_type'] = 2;
                $inData['by_id'] = $orderInfo['order_id'];
                $inData['change_desc'] = '签收订单获取积分:' . $orderInfo['order_sn'];
                $res = $AccountLogModel->change($inData, $orderInfo['user_id']);
                if ($res != true) {
                    Db::rollback();//回滚
                    return '签收赠送积分失败.';
                }
            }
            unset($inData);
            //提成处理
            //end
            //修改订单商品为待评价
            $where['order_id'] = ['order_id', '=', $orderInfo['order_id']];
            $res = $OrderGoodsModel->where('order_id', $order_id)->update(['is_evaluate' => 1]);
            if ($res < 1) {
                Db::rollback();//回滚
                return '修改订单商品为待评价失败.';
            }
        } elseif ($upData['order_status'] == $this->config['OS_RETURNED']) {//退货
            //提成处理
            //end
            //修改订单商品未评价的设为不需要评价

           $res = $OrderGoodsModel->where('order_id', $order_id)->update(['is_evaluate' => 0]);
            if ($res < 1) {
                Db::rollback();//回滚
                return '取消订单商品评价失败.';
            }
        }

        if ($extType == 'changePrice' || $extType == 'editGoods' ) {//改价或修改商品



        } elseif ($extType == 'unsign' ) {//撤销签收
            unset($where);
            //查询通过订单获取的积分,扣除
            $where['by_id'] = $orderInfo['order_id'];
            $where['change_type'] = 2;
            $log = $AccountLogModel->where($where)->find();
            if ($log['use_integral'] > 0) {
                unset($inData);
                $inData['total_integral'] = intval($orderInfo['total_amount']) * -1;
                $inData['use_integral'] = $inData['total_integral'];
                $inData['change_type'] = 2;
                $inData['by_id'] = $orderInfo['order_id'];
                if ($extType == 'unsign'){
                    $inData['change_desc'] = '撤销签收退还积分:' . $orderInfo['order_sn'];
                }else{
                    $inData['change_desc'] = '退货退还积分:' . $orderInfo['order_sn'];
                }
                $res = $AccountLogModel->change($inData, $orderInfo['user_id']);
                if ($res != true) {
                    Db::rollback();//回滚
                    return '退还积分失败.';
                }
            }
        }
        $upData['update_time'] = time();
        $res = $this->where('order_id', $order_id)->update($upData);
        if ($res < 1) {
            Db::rollback();
            return '订单更新失败.';
        }
        Db::commit();// 提交事务
        $this->cleanMemcache($order_id);
        return true;
    }
    /*------------------------------------------------------ */
    //-- 订单后台操作权限
    /*------------------------------------------------------ */
    public function operating(&$order)
    {
        $os = $order['order_status'];
        $ss = $order['shipping_status'];
        $ps = $order['pay_status'];
        if ($os == $this->config['OS_UNCONFIRMED']){//未确认
            $operating['cancel'] = true;//取消
            if ($order['pay_id'] == 1) $operating['confirmed'] = true;//确认
            elseif ($order['pay_id'] == 99 && $ps == $this->config['PS_UNPAYED']) $operating['cfmCodPay'] = true;//设为已付款
            $operating['changePrice'] = true; //改价
            $operating['editGoods'] = true; //修改商品
        }elseif ($os == $this->config['OS_CONFIRMED']){ //已确认
            if ($ss == $this->config['SS_UNSHIPPED'])
            {
                $operating['cancel'] = true;
                $operating['shipping'] = true;
                $operating['changePrice'] = true;//改价
                $operating['editGoods'] = true; //修改商品
                if ($order['pay_id'] == 99 && $ps == $this->config['PS_UNPAYED']) $operating['cfmCodPay'] = true;//设为已付款
            }elseif ($ss == $this->config['SS_SHIPPED']){
                $operating['sign'] = true;
                $operating['unshipping'] = true;//设为未发货
                $operating['returned'] = true;//设为退货
                unset($operating['unconfirmed']);
            }elseif ($ss == $this->config['SS_SIGN']){
                if (($order['sign_time'] > time() - 604800)){
                    $operating['returned'] = true;//设为退货
                    $operating['unsign'] = true;//设为未签收
                }
                unset($operating['unconfirmed']);
                if ($order['pay_id'] == 1 ){
                    if ($ps == $this->config['PS_UNPAYED'])$operating['cfmCodPay'] = true;//设为已付款
                    else unset($operating['unsign']);
                }
            }else{
                $operating['cancel'] = true;
                $operating['changePrice'] = true;
            }
            if ($ps == $this->config['PS_PAYED']){
                if ($ss == $this->config['SS_UNSHIPPED']){
                    $operating['setUnPay'] = true;
                }else{
                    $operating['returnPay'] = true;
                }
                unset($operating['changePrice'],$operating['editGoods']);//已支付不能改价
            }
        }elseif($os == $this->config['OS_CANCELED']){ //已关闭
            if ($order['cancel_time'] > time() - 604800) $operating['confirmed'] = true;//确认
        }else{
            $operating['confirmed'] = true;//确认
        }
        return $operating;
    }
}
