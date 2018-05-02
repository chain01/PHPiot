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
	$myfile = fopen("newfile.txt", "a+") or die("Unable to open file!");
	//第一个参数是文件路径和文件名称，第二个参数是打开方式用法如下：
	/*	
	‘r' 只读方式打开，将文件指针指向文件头。
　　‘r+' 读写方式打开，将文件指针指向文件头。
　　‘w' 写入方式打开，将文件指针指向文件头并将文件大小截为零。如果文件不存在则尝试创建之。
　　‘w+' 读写方式打开，将文件指针指向文件头并将文件大小截为零。如果文件不存在则尝试创建之。
　　‘a' 写入方式打开，将文件指针指向文件末尾。如果文件不存在则尝试创建之。
　　‘a+' 读写方式打开，将文件指针指向文件末尾。如果文件不存在则尝试创建之。
　　‘x' 创建并以写入方式打开，将文件指针指向文件头。如果文件已存在，则 fopen() 调用失败并返回 FALSE
　　‘x+' 创建并以读写方式打开，将文件指针指向文件头。如果文件已存在，则 fopen() 调用失败并返回 FALSE
	*/
	fwrite($myfile, $data);
	echo "write success\n";
	fclose($myfile);
	//$read=fread($myfile,filesize("newfile.txt"));
	//$read=file('newfile.txt');
	
};

// 运行worker
Worker::runAll();