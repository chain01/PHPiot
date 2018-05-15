<?php
use PHPiot\Worker;
require  '../Autoloader.php';

// 创建一个Worker监听2347端口，不使用任何应用层协议
$tcp_worker = new Worker("tcp://0.0.0.0:2347");

// 启动4个进程对外提供服务
$tcp_worker->count = 4;

// 当客户端发来数据时
$tcp_worker->onMessage = function($connection, $data)
{
    // 向客户端发送hello $data
    $connection->send('hello ' . $data);
	$hello = explode(',',$data); 
 	$weight=$hello[0];
	$jia=$hello[1];
	$name=$hello[2];
	$con=mysqli_connect('localhost','root','') or die(mysqli_error());
	mysqli_select_db($con,'test')or die('123');
	mysqli_query('set names utf8');
	$sql = "INSERT INTO `test` (`ID`, `weight`, `jia`, `name`) VALUES (NULL, '$weight','$jia','$name')";
	if(mysqli_query($con,$sql))
	{
		echo '123';
	}
	else
	{
		echo '456';
	}

};

// 运行worker
Worker::runAll();