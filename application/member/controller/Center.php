<?php
/*------------------------------------------------------ */
//-- 会员主页
//-- Author: iqgmy
/*------------------------------------------------------ */
namespace app\member\controller;
use app\ClientbaseController;
use app\member\model\UsersModel;

class Center  extends ClientbaseController{
  
	/*------------------------------------------------------ */
	//-- 首页
	/*------------------------------------------------------ */
	public function index(){
        $this->assign('title', '会员中心');
        $this->assign('isUserIndex', 1);
        $this->assign('not_top_nav', true);
        $this->assign('user_center_nav_tpl', settings('user_center_nav_tpl'));
        $this->assign('navMenuList', (new \app\shop\model\NavMenuModel)->getRows(3));//获取导航菜单

		return $this->fetch('index');
	}
	/*------------------------------------------------------ */
    //-- 我的分享二维码
    /*------------------------------------------------------ */
    public function myCode(){
        $DividendShareByRole = settings('DividendShareByRole');
        if ($DividendShareByRole == 1 && $this->userInfo['role_id'] < 1){
            return $this->error('请升级身份后再操作.');
        }
        $default_img = settings('GoodsImages');
        $arr = explode(',', $default_img);
        $default_img = $arr[0]?$arr[0]:'';
        $this->assign('default_img',$default_img);
        $this->assign('title', '我的二维码');
        return $this->fetch('my_code');
    }
    /*------------------------------------------------------ */
    //-- 会员收货地址页
    /*------------------------------------------------------ */
    public function address(){
        $this->assign('title', '收货地址');
        return $this->fetch('address/index');
    }
    /*------------------------------------------------------ */
    //-- 会员优惠券页
    /*------------------------------------------------------ */
    public function bonus(){
        $this->assign('title', '优惠券');
        return $this->fetch('shop@bonus/index');
    }
    /*------------------------------------------------------ */
    //-- 会员设置页
    /*------------------------------------------------------ */
    public function setting(){
        $this->assign('title', '设置');
        return $this->fetch('setting');
    }
    /*------------------------------------------------------ */
    //-- 修改密码
    /*------------------------------------------------------ */
    public function editPwd(){
        $this->assign('title', '修改密码');
        return $this->fetch('edit_pwd');
    }
    /*------------------------------------------------------ */
    //-- 修改支付密码
    /*------------------------------------------------------ */
    public function editPayPwd(){
        $this->assign('sms_fun', settings('sms_fun'));//获取短信配置
        $this->assign('title', '修改支付密码');
        return $this->fetch('edit_pay_pwd');
    }
    /*------------------------------------------------------ */
    //-- 个人资料
    /*------------------------------------------------------ */
    public function userInfo(){
        $this->assign('title', '个人资料');	
		$superior = (new UsersModel)->getSuperior($this->userInfo['pid']);
		$this->assign('superior', $superior);
        return $this->fetch('user_info');
    }
    /*------------------------------------------------------ */
    //-- 我的钱包
    /*------------------------------------------------------ */
    public function wallet(){
        $this->assign('title', '我的钱包');
        return $this->fetch('wallet');
    }
    /*------------------------------------------------------ */
    //-- 提现
    /*------------------------------------------------------ */
    public function withdraw(){
        $this->assign('title', '提现');
        return $this->fetch('withdraw');
    }
    /*------------------------------------------------------ */
    //-- 添加银行卡
    /*------------------------------------------------------ */
    public function addBankCard(){
        $this->assign('title', '添加银行卡');
        return $this->fetch('add_bank_card');
    }
    /*------------------------------------------------------ */
    //-- 添加虚拟会员
    /*------------------------------------------------------ */
    public function addMember(){
        $this->assign('title', '录入会员');
        return $this->fetch('add_member');
    }
    /*------------------------------------------------------ */
    //-- 会员报单
    /*------------------------------------------------------ */
    public function addMemberOrder(){
        $this->assign('title', '会员报单');
        $this->assign('member_id', input('member_id'));
        $this->assign('pv', $this->userInfo['account']['use_integral']);
        return $this->fetch('add_member_order');
    }
    /*------------------------------------------------------ */
    //-- 报单明细
    /*------------------------------------------------------ */
    public function memberOrderLog(){
        $this->assign('title', '报单明细');
        $this->assign('member_id', input('member_id'));
        return $this->fetch('member_order_log');
    }
    /*------------------------------------------------------ */
    //-- 奖励与升级
    /*------------------------------------------------------ */
    public function reward(){
        (new \app\member\model\MemberModel)->reward();
    }
    /*------------------------------------------------------ */
    //-- 奖励与升级(预览)
    /*------------------------------------------------------ */
    public function reward_coming(){
        (new \app\member\model\MemberModel)->reward(false);
    }
}?>