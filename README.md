# MultiWorker PHP多进程管理器

Multiworker是纯PHP实现的多进程管理器，使用master-worker进程模型，适用于命令行下的多进程调度、并发处理、工作进程崩溃自动恢复、单实例限制。

项目主页 https://github.com/beyosoft/multiworker

bug及使用反馈：zhangxugg@163.com

## 一、特点：
1.  使用master-worker进程模型，稳定可靠。 Multiworker运行实例由一个master进程（称为主进程）、多个Worker进程（称为工作进程或子进程）组成，主进程主要工作进程的生成、和退出状态监测。一旦有工作进程异常退出，主进程就会立即再生成一个工作进程，接替其继续工作。因为主进程不参与具体业务逻辑，几乎没有异常退出的可能。

2.  轻量级、无外部依赖。只有一个类文件、可适用用于任何项目和框架。

3.  支持单实例功能，配合crontab可实现高可靠性的后台任务。 实际业务中，我们往往希望一个任务由单实例运行，只有前一个实例异常退出时，新的实例才能成功运行，结合crontab和Multiworker的单实例功能，可以很容易实现一个高可靠的后台任务。

4.  并发任务调度处理。不同的工作进程可负责不同的任务处理，相比单进程可极大提高整体任务处理效率。

5. 工作进程状态监测。当工作进程以指定的正常状态退出后，主进程不会再生产新的子进程，当所有工作进程以指定的正常状态退出时，主进程认为任务处理完毕，自己同时退出。

6.  信号控制和进程运行控制。实例在运行中，向主进程发送SIGTERM信号时，主进程会向每个子进程发送信号，告知其及时退出，当所有工作进程退出后，主进程也退出。

## 二、环境要求
1.  因为使用Linux信号控制，需要posix扩展，只支持Linux类系统，不支持windows。
2.  需要php 5.3+。

## 三、注意事项
1. 工作进程可以共用一个数据库连接资源吗？
    
    绝对不能，每个工作进程必须重新建立一个数据库连接，否则会引发不可意料的结果。可以在onWorkerStart回调中关闭主进程已经建立的数据库连接，再重新打开即可。
2. 如何实现不同的工作进程执行不同的任务？
    
    onWorkerStart回调的参数，就是进程的PIN(process index number), 它从0开始编号，可以通过判断PIN从而让子进程完成不同的任务。

3. 可靠性如何？

    久经生产环境实际长久运行，请放心使用。

## 四、范例：
1.  通用范例
```php
$mp = new MulitWorker();
$mp->workerNum = 6;     //6个工作进程
$mp->normalExitCode = 0; //工作进程以0状态码退出时 认定为正常状态 不再生成新的工作进程
$mp->debug = false;
# onWorkerStart回调被子进程运行
$mp->onWorkerStart = function($pin) use($mp) {
    //$pin是指工作进程的编号(process index number), 从0开始。通过$pin我们可以安排工作进程处理不同的任务
    $mp->Log("$pin started .\n");
    sleep(rand(5, 20));
    exit(41);   //模拟工作进程异常退出  主进程会重新生成一个子进程接替它的工作
};

$mp->run();
```
以上实例会在syslog中记录日志：
```bash
[root@bogon ~]# tailf /var/log/messages

Aug 21 15:37:00 bogon multiworker[6689]: 5 started .
Aug 21 15:37:00 bogon multiworker[6687]: 3 started .
Aug 21 15:37:00 bogon multiworker[6685]: 1 started .
Aug 21 15:37:00 bogon multiworker[6690]: 0 started .
Aug 21 15:37:00 bogon multiworker[6688]: 4 started .
Aug 21 15:37:00 bogon multiworker[6686]: 2 started .
Aug 21 15:37:07 bogon multiworker[6684]: worker 6688(4) exit(41)
Aug 21 15:37:07 bogon multiworker[6684]: for worker for PIN: 4
Aug 21 15:37:07 bogon multiworker[6691]: 4 started .
Aug 21 15:37:08 bogon multiworker[6684]: worker 6689(5) exit(41)
Aug 21 15:37:08 bogon multiworker[6684]: for worker for PIN: 5
Aug 21 15:37:08 bogon multiworker[6692]: 5 started .
Aug 21 15:37:14 bogon multiworker[6684]: worker 6692(5) exit(41)
Aug 21 15:37:14 bogon multiworker[6684]: for worker for PIN: 5
Aug 21 15:37:14 bogon multiworker[6693]: 5 started .
Aug 21 15:37:16 bogon multiworker[6684]: worker 6687(3) exit(41)
Aug 21 15:37:16 bogon multiworker[6684]: for worker for PIN: 3
Aug 21 15:37:16 bogon multiworker[6694]: 3 started .
Aug 21 15:37:18 bogon multiworker[6684]: worker 6685(1) exit(41)
Aug 21 15:37:18 bogon multiworker[6684]: for worker for PIN: 1
Aug 21 15:37:18 bogon multiworker[6695]: 1 started .

```
可发现，当工作进程异常退出时，multiworker会重新生成子进程接替其工作，从而实现高可靠性。

2. 信号捕获及控制。Multiworker使用信号机制，遇到业务循环时，会阻塞信号的捕获，从而可能导致工作进程不受信号控制。可以在业务循环中及时捕获信号使之受控。
```php
$mp = new MulitWorker();
$mp->workerNum = 6;     //6个工作进程
$mp->normalExitCode = 0; //工作进程以0状态码退出时 认定为正常状态 不再生成新的工作进程
$mp->debug = false;
$mp->onWorkerStart = function($pin) use($mp) {
    //$pin是指工作进程的编号(process index number), 从0开始。通过$pin我们可以安排工作进程处理不同的任务
    $mp->Log("$pin started .\n");
    for($i=0; $i <1000; $i++){
        $mp->signalDispatch(); //及时捕获信号使子进程受控
        // 业务逻辑
    }
    exit(0); //业务处理完毕 正常退出
};

$mp->run();
```

3. 信号及进程控制使用范例：
```php
$mp = new MulitWorker();
$mp->workerNum = 6;
$mp->normalExitCode = 0;
$mp->debug = false;
$mp->onWorkerStart = function($pin) use($mp) {
    $mp->Log("$pin started .\n");
    sleep(3600);
    exit(0);
};

$mp->run();
```
运行以上代码， 查看进程树，找出主进程并手工向它发送退出信号，则整个实例退出，无须给每个进程发送退出信号。

```bash
[root@bogon ~]# pstree -Ap
init(1)-+-auditd(1288)---{auditd}(1289)
        |-crond(1478)
        |-dhclient(1151)
        |-master(1464)-+-pickup(6132)
        |              `-qmgr(1477)
        |-mingetty(1526)
        |-mingetty(1528)
        |-mingetty(1530)
        |-mingetty(1532)
        |-mingetty(1534)
        |-mingetty(1536)
        |-nginx(1518)---nginx(1520)
        |-php(6684)-+-php(7086)
        |           |-php(7087)
        |           |-php(7088)
        |           |-php(7089)
        |           |-php(7090)
        |           `-php(7091)
        |-php-fpm(5787)-+-php-fpm(5788)
        |               `-php-fpm(5789)
        |-rsyslogd(1311)-+-{rsyslogd}(1312)
        |                |-{rsyslogd}(1313)
        |                `-{rsyslogd}(1314)
        |-sshd(1385)-+-sshd(1672)---bash(1676)
        |            |-sshd(1699)---bash(1703)---pstree(7092)
        |            `-sshd(5684)---bash(5688)
        `-udevd(516)-+-udevd(1543)
                     `-udevd(1544)
[root@bogon ~]# kill 6684  //向主进程发送退出信号
[root@bogon ~]# pstree -Ap //查看进程树，实例已经被终止
init(1)-+-auditd(1288)---{auditd}(1289)
        |-crond(1478)
        |-dhclient(1151)
        |-master(1464)-+-pickup(6132)
        |              `-qmgr(1477)
        |-mingetty(1526)
        |-mingetty(1528)
        |-mingetty(1530)
        |-mingetty(1532)
        |-mingetty(1534)
        |-mingetty(1536)
        |-nginx(1518)---nginx(1520)
        |-php-fpm(5787)-+-php-fpm(5788)
        |               `-php-fpm(5789)
        |-rsyslogd(1311)-+-{rsyslogd}(1312)
        |                |-{rsyslogd}(1313)
        |                `-{rsyslogd}(1314)
        |-sshd(1385)-+-sshd(1672)---bash(1676)
        |            |-sshd(1699)---bash(1703)---pstree(7120)
        |            `-sshd(5684)---bash(5688)
        `-udevd(516)-+-udevd(1543)
                     `-udevd(1544)

```

4.  单实例模式。配置crontab实现高可靠性的、单实例的后台任务。
```php
#/wwwroot/beyosoft/multiworker/test.php文件中的内容
<?php
use beyosoft\cli\MulitWorker;
require __DIR__.'/MulitWorker.php';

$mp = new MulitWorker();
$mp->lockFile = '/var/run/multiworker.lock'; //设置一个锁文件 文件不存在时会自动创建
$mp->workerNum = 6;
$mp->normalExitCode = 0;
$mp->onWorkerStart = function($pin) use($mp) {
    $mp->Log("$pin started .\n");
    sleep(3600);
    exit(0);
};

$mp->run();
```
crontab中添加任务：
```bash
*  *  *   *   *  /usr/local/php/bin/php /wwwroot/beyosoft/multiworker/test.php 2>&1 | /bin/logger
```
机器崩溃重启或是进程实例意外退出，还会有机会再次运行，但因为锁文件的存在，永远只会有一个实现运行。

