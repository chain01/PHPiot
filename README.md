# PHPiot
使用PHP的物联网服务器
## 概述
PHPiot是一个高性能的PHP socket 服务器框架。基于PHP多进程以及libevent事件轮询库。
目标是让物联网开发者更容易的开发出基于socket的高性能的应用服务，而不用去了解PHP socket以及PHP多进程细节。
PHPiot本身是一个PHP多进程服务器框架，具有PHP进程管理以及socket通信的模块，所以不依赖php-fpm、nginx或者apache等这些容器便可以独立运行。应用在多终端的物联网领域可以更好的节省服务器资源消耗。

## 环境要求
(php>=5.3.3)
