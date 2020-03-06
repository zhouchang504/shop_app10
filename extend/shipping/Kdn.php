<?php
/*------------------------------------------------------ */
//-- 快递100接口
//-- @author iqgmy
/*------------------------------------------------------ */
namespace shipping;
$i = (isset($modules)) ? count($modules) : 0;
$modules[$i]["name"] = "快递鸟";
class Kdn{
    public function __construct()
    {
        $setting = settings();
        //电商ID
        $this->EBusinessID=settings('kdn_appid');
        //电商加密私钥，快递鸟提供，注意保管，不要泄漏
//        defined('AppKey','5f321675-6e87-4f17-9573-000c34f3a755');
        $this->AppKey=settings('kdn_apikey');
        //请求url
        $this->ReqURL=settings('kdn_apiurl');
    }
    /*------------------------------------------------------ */
    //--  获取物流信息
    /*------------------------------------------------------ */
    public  function getLog($shipping_code,$invoice_no,$mobile=''){
        $express = array('YT'=>'yuantong','ST'=>'shentong','ZJS'=>'zhaijisong','EMS'=>'ems','ZT'=>'zhongtong','YD'=>'yunda','SF'=>'shunfeng','DBL'=>'debangwuliu','DBLKY'=>'debangwuliu','ZTO'=>'zhongtong','STO'=>'shentong');
        $return['code'] = 0;

        if (empty($express[$shipping_code])==true){
            $return['Reason'] = '暂不支持当前快递物流查询，请前往官网查询！';
            return $return;
        }

        $requestData= "{'OrderCode':'','ShipperCode':'$shipping_code','LogisticCode':'$invoice_no'}";
        $datas = array(
            'EBusinessID' => $this->EBusinessID,
            'RequestType' => '1002',
            'RequestData' => urlencode($requestData) ,
            'DataType' => '2',
        );

        $datas['DataSign'] = $this->encrypt($requestData, $this->AppKey);
        $result=self::sendPost($this->ReqURL, $datas);
        //根据公司业务处理返回的信息......
        return json_decode( $result, $assoc = true, $depth = 512, $options = 0 );
    }
    /**
     * Json方式 查询订单物流轨迹
     */
    function getOrderTracesByJson(){
        $requestData= "{'OrderCode':'','ShipperCode':'YTO','LogisticCode':'12345678'}";

        $datas = array(
            'EBusinessID' => EBusinessID,
            'RequestType' => '1002',
            'RequestData' => urlencode($requestData) ,
            'DataType' => '2',
        );
        $datas['DataSign'] = encrypt($requestData, AppKey);
        $result=sendPost(ReqURL, $datas);

        //根据公司业务处理返回的信息......

        return $result;
    }

    /**
     *  post提交数据
     * @param  string $url 请求Url
     * @param  array $datas 提交的数据
     * @return url响应返回的html
     */
    function sendPost($url, $datas) {
        $temps = array();
        foreach ($datas as $key => $value) {
            $temps[] = sprintf('%s=%s', $key, $value);
        }
        $post_data = implode('&', $temps);
        $url_info = parse_url($url);
        if(empty($url_info['port']))
        {
            $url_info['port']=80;
        }
        $httpheader = "POST " . $url_info['path'] . " HTTP/1.0\r\n";
        $httpheader.= "Host:" . $url_info['host'] . "\r\n";
        $httpheader.= "Content-Type:application/x-www-form-urlencoded;charset=utf-8\r\n";
        $httpheader.= "Content-Length:" . strlen($post_data) . "\r\n";
        $httpheader.= "Connection:close\r\n\r\n";
        $httpheader.= $post_data;
        $fd = fsockopen($url_info['host'], $url_info['port']);
        fwrite($fd, $httpheader);
        $gets = "";
        $headerFlag = true;
        while (!feof($fd)) {
            if (($header = @fgets($fd)) && ($header == "\r\n" || $header == "\n")) {
                break;
            }
        }
        while (!feof($fd)) {
            $gets.= fread($fd, 128);
        }
        fclose($fd);

        return $gets;
    }

    /**
     * 电商Sign签名生成
     * @param data 内容
     * @param appkey Appkey
     * @return DataSign签名
     */
    function encrypt($data, $appkey) {
        return urlencode(base64_encode(md5($data.$appkey)));
    }

}


?>