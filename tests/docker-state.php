<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Panel\Core\{Config,Database};

// Read-only digest for verifying that replacement containers retain both volumes.
try {
    if(getenv('TEST_HTTP_ISOLATED')!=='1'||!Config::installed()) { throw new RuntimeException('Requires the installed disposable Docker test site'); }
    $config=Config::read();
    if($config['db']['name']!=='cf_panel_docker_preview') { throw new RuntimeException('Unexpected Docker test database'); }
    $db=new Database($config['db']);
    if($db->value("SELECT value FROM settings WHERE name='preview_mode'")!=='1') { throw new RuntimeException('Cloud execution must be disabled for this fixture'); }
    $state=['config'=>hash_file('sha256',Config::storage('config.php')),'lock'=>hash_file('sha256',Config::storage('installed.lock'))];
    foreach(['users','accounts','zones','profiles','entries','origins','domains','jobs','verification_records','dns_changes','domain_changes','dns_operations','managed_sources','entry_routes','api_tokens','migrations'] as $table) {
        $rows=array_map(static fn(array $row):string=>json_encode($row,JSON_THROW_ON_ERROR),$db->all('SELECT * FROM `'.$table.'`'));
        sort($rows,SORT_STRING);
        $state[$table]=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    }
    $state['settings']=hash('sha256',json_encode($db->all("SELECT * FROM settings WHERE name<>'worker_heartbeat' ORDER BY name"),JSON_THROW_ON_ERROR));
    if(!(int)$db->value('SELECT COUNT(*) FROM users')||is_file(Config::storage('install-token'))) { throw new RuntimeException('Administrator or one-time installation lock is invalid'); }
    echo hash('sha256',json_encode($state,JSON_THROW_ON_ERROR)).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
