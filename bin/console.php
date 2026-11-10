<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Panel\Core\{Config, Database, Crypto};
use Panel\Services\{Installer, Cloud};
use Panel\Jobs\Worker;

$command=$argv[1]??'help';
try {
    if ($command==='version'||$command==='--version') { echo 'Cloudflare SaaS自动接入面板 '.\Panel\Core\Version::current()."\n";exit(0); }
    if ($command==='install:token') {
        if (Config::installed()) { echo "站点已安装，安装入口已锁定。\n";exit(0); }
        echo '一次性安装口令：'.(new Installer())->token()."\n请访问 /install 完成初始化。\n";exit(0);
    }
    if ($command==='check') { foreach ((new Installer())->checks() as $name=>$ok) { echo ($ok?'OK ':'FAIL ').$name."\n"; }exit(0); }
    if ($command==='health:worker') {
        if (!Config::installed()) { echo "等待安装。\n";exit(0); }
        $db=new Database(Config::read()['db']);$heartbeat=$db->value("SELECT value FROM settings WHERE name='worker_heartbeat'");
        exit($heartbeat&&time()-(strtotime($heartbeat)?:0)<90?0:1);
    }
    if ($command==='worker') {
        $once=in_array('--once',$argv,true);
        do {
            if (!Config::installed()) { if ($once) { break; }sleep(3);continue; }
            $config=Config::read();$db=new Database($config['db']);$worker=new Worker($db,new Cloud($db,new Crypto($config['key'])));
            do { $worked=$worker->once();if (!$once) { sleep($worked?1:3); } } while (!$once);
        } while (!$once);
        exit(0);
    }
    if ($command==='migrate') { $db=new Database(Config::read()['db']);$db->operationalLock(fn()=>$db->migrate(),30);echo "数据库迁移完成。\n";exit(0); }
    echo "命令：version | install:token | check | migrate | worker [--once] | health:worker\n";
} catch (\Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
