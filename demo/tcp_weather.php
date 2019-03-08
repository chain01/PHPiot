<?php
use PHPiot\Worker;							//使用声明 可以省略
require  '../Autoloader.php';

// 创建一个Worker监听2347端口，不使用任何应用层协议
$tcp_worker = new Worker("tcp://0.0.0.0:2348");			//创建一个进程  利用TCP协议  0.0.0.0  表示自动获取本地IP

// 启动4个进程对外提供服务
$tcp_worker->count = 4;

// 当客户端发来数据时

//Worker对象的onMessage最终也赋值给了它所使用的$connection的onMessage

$tcp_worker->onMessage = function($connection, $data) // onMessage：当监听到客户端发来的数据时触发回调函数，使用匿名函数作为回调；data：客户端发送的数据
{
	$host = "http://saweather.market.alicloudapi.com";	//API接口调用地址 全国天气预报查询_免费版_易源数据
    $path = "/hour24";									//获取当天 24 小时的天气
    $method = "GET";									//获取方式： get
    $appcode = "cb5c9185cf624378b1e80fabb88b52d8";		//账户ID 
    $headers = array();
    array_push($headers, "Authorization:APPCODE " . $appcode);
    $querys = "area=%E4%B8%B4%E6%B2%82";				//临沂市
    $bodys = "";
    $url = $host . $path . "?" . $querys;

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_FAILONERROR, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    if (1 == strpos("$".$host, "https://"))
    {
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    }
	$response =curl_exec($curl);


/*
	【id或名称->查询24小时预报】返回参数
	名称 	字段描述
	area 	查到的地区名
	areaid 	查到的地区id
	hourList 	24小时预报列表
	- weather_code 	天气编码
	- wind_direction 	风向
	- wind_power 	风力
	- weather 	天气名称
	- temperature 	温度
	- time 	预报时间
*/

	//基于header和body是通过两个回车换行来分割的,所以可以通过如下代码实现:
	if (curl_getinfo($curl, CURLINFO_HTTP_CODE) == '200') {				//返回 200 说明读取成功
    list($header, $body) = explode("\r\n\r\n", $response, 2);			//提取出返回信息的 header 和 body
}
    
	//var_dump(json_decode($body, true));
	$arrr=json_decode($body,true);						//将body中的信息存到数组中
	$arrw=$arrr["showapi_res_body"]["hourList"];		//将数组中的显示API 以及 24小时预报列表  放到数组 arrw 中 
	$num=$data;											//保存客户端发送的数据
	$arrh=$arrw[$num]["weather_code"];					//根据客户端发送的数据检索指定时间的天气信息（代号）
	$arrt=$arrw[$num]["temperature"];					//根据客户端发送的数据检索指定时间的气温信息
    $connection->send($arrh.",".$arrt.",".date("Y:m:d:H:i:s"));		//向客户端发送天气信息（代号）、气温值、当前实时时间；
	echo "write success\n";							//收到数据 返回write success

	
};


// 运行worker
Worker::runAll();

