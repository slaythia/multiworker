<?php
namespace beyosoft\cli;

/**
 * multi worker process manager for php.
 *
 * @author zhangxu <zhangxugg@163.com>
 * @link http://www.beyo.io/
 * @copyright Copyright (c) 2017 Beyo.IO Software Team.
 */

class MulitWorker {
    
    const STATUS_RUNNING=1;
    const STATUS_STOPPING=2;
    
    protected $masterPid = 0;
    protected $workers = [];
    protected $status = 0;
    
    /**
     * @var int process index number of the current worker.
     */
    protected $PIN = -1;
    
    /**
     * @var int  The number of worker process 
     */
    public $workerNum = 1;
    
    /**
     * @var boolean Whether run as a daemonize service. A deamon service is detached from the shell terminal and run in background.
     */
    public $deamon = true;
    
    /**
     * @var callable The callback when the worker process started. The callback's argument is the worker's PIN(process index number). 
     */
    public $onWorkerStart = null;
    
    /**
     * @var boolean whether enable debug mode. In debug mode, workers run in foreground. 
     */
    public $debug = false;
    
    /**
     * @var int The worker's normal exit code. when a worker's exit code is this value, the master will not fork it again. 
     * when all worker exits by the specifed status code, it means the task is completed, and the master will exit normal.
     */
    public $normalExitCode = 0;    
    
    /**
     * @var string The lockfile for current instance. Single Instance  limited by the lock file. 
     */
    public $lockFile = null;    
    
    /**
     * start multi worker instance.
     */
    
    public function run(){        
        $this->prepare();        
        $this->daemonize();    
        $this->masterPid = getmypid();
        $this->status = self::STATUS_RUNNING;
        $this->forkWorkers();
        $this->waitWorkers();
    }
    
    protected function getMasterPid(){
        return $this->masterPid;
    }
    
    
    /**
     * Exit master and worker process.
     * @param number $status
     * @param string $signal
     */
    
    public function exitAll($status=254, $signal=SIGTERM){
        static $counter=0;
        
        if($this->masterPid) {
            posix_kill($this->masterPid, $signal);
        }
        
        //wait master send signal to me
        while(true){
            $counter++;
            if($counter >=5) {
                if($this->masterPid) {
                    foreach($this->workers as $pid){
                        posix_kill($pid, SIGKILL);
                    }
                    posix_kill($this->masterPid, SIGKILL);
                }
                exit($status);
            }
            sleep(1);
            pcntl_signal_dispatch();
        }
    }
    
    public function catpchSignal(){
        pcntl_signal_dispatch();
    }
    
    public function signalDispatch(){
        pcntl_signal_dispatch();
    }
    
    protected function prepare(){
        static $handler = null;
        $this->workerNum = max(1, $this->workerNum);        
        $this->normalExitCode = (int)$this->normalExitCode;
        
        if(!$this->onWorkerStart || !is_callable($this->onWorkerStart)) {
            throw new \Exception("onWorkerStart must be a valid callback");
        }
        
        $this->limitInstance();
    }
    
    protected function limitInstance(){
        static  $handler = null;
        if(!$this->lockFile)  return false;
        
        if(!is_file($this->lockFile) && !touch($this->lockFile)){
            throw new \Exception("can not create file $this->lockFile");
        }
        
        if(!is_file($this->lockFile) || !($handler = fopen($this->lockFile, 'r+'))){
            throw new \Exception("can not open $this->lockFile");
        }
        
        if(!flock($handler, LOCK_EX | LOCK_NB)){
            if($this->debug) {
                $this->Log("lock $this->lockFile failed, master exit.");
            }
            exit(0);
        }        
    }
    
    protected function callback($function, $args=null){
        if(is_callable($function)) {
            return call_user_func($function,$args);
        }
        
        return false;
    }
    
    protected function forkWorkers($pin = -1){
        static $_counter = null;
        if(is_null($_counter))  $_counter = $this->workerNum;        
        
        while(count($this->workers) < $this->workerNum){
            $_counter++;
            $pid = pcntl_fork();
            
            $PIN = $pin >=0 ? $pin : ($_counter % $this->workerNum);
            
            if($pid >0){
                $this->workers[$PIN] = $pid;
            }else if($pid === -1){
                Yii::$app->end();
            }else{
                $this->PIN = $PIN;
                $this->installSignalHandler();
                return $this->callback($this->onWorkerStart, $this->PIN);
            }
        }
    }
    
    protected function daemonize(){
        if($this->deamon && !$this->debug){
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new \Exception('fork fail');
            } elseif ($pid > 0) {
                exit(0);
            }
        }
    }
    
    protected function installSignalHandler() {
        pcntl_signal(SIGHUP, array($this,  'signalHandler'), false);
        pcntl_signal(SIGINT, array($this,  'signalHandler'), false);
        pcntl_signal(SIGTERM, array($this, 'signalHandler'), false);
    }
    
    protected function signalHandler($signal){
        $this->status = self::STATUS_STOPPING;
        if($this->isMaster()) {
            foreach($this->workers as $pid) {
                posix_kill($pid, $signal);
            }
        }else{
            $this->end();
        }
    }    
    
    public function Log($message){
        static $openlog = false;
        if(!$openlog) {
            $openlog = openlog('multiworker', LOG_PID, LOG_DAEMON);
        }
        
        
        if($this->deamon) {
            echo getmypid()." ".date('Y-m-d H:i:s')." ".$message. "\n";
        }
        syslog(LOG_INFO, $message);
    }
    
    protected function isMaster() {
        return $this->masterPid === getmypid();
    }
    
    protected function end(){
        exit($this->normalExitCode);
    }
    
    protected function waitWorkers() {
        if(!$this->isMaster())  return false;
        
        $this->installSignalHandler();
    
        while(true) {
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            pcntl_signal_dispatch();
            if($pid >0) {
                $i = array_search($pid, $this->workers);                
                unset($this->workers[$i]);                
                $exitCode = pcntl_wexitstatus($status);
                $this->Log("worker $pid($i) exit($exitCode)");
    
                if($this->status !== self::STATUS_STOPPING){  
                    if($exitCode !== $this->normalExitCode){
                        $this->Log("for worker for PIN: $i");
                        $this->forkWorkers($i === false ? -1 : $i);
                    }
                }
    
                if(empty($this->workers)) {
                    $this->Log("no workers, master exit");
                    return $this->end();
                }
            }else if($pid < 0 ) {
                $this->Log("no workers, master exit");
                return $this->end();
            }
        }
    }
}