<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Http;

use Panel\Core\{Config, Database, Crypto, AppException, Validator};
use Panel\Auth\Auth;
use Panel\Services\{Installer, Cloud, Configuration, DomainService, Audit,DomainConfig,Settings,ZoneRecords,Transfer,BulkRecords};

final class Kernel
{
    private string $requestId;
    private ?Database $db=null;
    public function handle(): void
    {
        $this->requestId=bin2hex(random_bytes(10));
        header('X-Request-ID: '.$this->requestId);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        header('Cache-Control: no-store');
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        try {
            $installed=Config::installed();
            $config=$installed?Config::read():[];
            Auth::session(str_starts_with($config['app_url']??'','https://')||($_SERVER['HTTPS']??'')==='on',$config['key']??Config::storage());
            if ($path==='/health') {
                if ($installed) { $healthDb=new Database($config['db']);$healthDb->value('SELECT 1');$this->schemaCheck($healthDb); }
                $this->json(['installed'=>$installed,'status'=>$installed?'ready':'install_required','version'=>\Panel\Core\Version::current()]);return;
            }
            if ($path==='/install') {
                if ($installed) { throw new AppException('安装已锁定，请从登录页进入',409,'already_installed'); }
                $installer=new Installer();
                if ($method==='POST') { Auth::csrf();$installer->install($this->input());$this->json(['redirect'=>'/login']);return; }
                if ($method!=='GET') { throw new AppException('不支持的方法',405); }
                $checks=$installer->checks();
                $defaults=['db_host'=>getenv('DB_HOST')?:'127.0.0.1','db_port'=>getenv('DB_PORT')?:3306,'db_name'=>getenv('DB_NAME')?:'cf_saas_panel','db_user'=>getenv('DB_USER')?:'cf_panel'];
                $this->view('install',['checks'=>$checks,'defaults'=>$defaults]);return;
            }
            if (!$installed) {
                if (str_starts_with($path,'/api/')) { throw new AppException('请先安装',503,'not_installed'); }
                header('Location: /install');return;
            }
            $this->db=new Database($config['db']);
            $db=$this->db;
            $this->schemaCheck($db);
            $auth=new Auth($db);
            if ($path==='/login') {
                if ($method==='POST') { Auth::csrf();$input=$this->input();$auth->login((string)($input['username']??''),(string)($input['password']??''));$this->json(['redirect'=>'/']);return; }
                $this->view('login');return;
            }
            if ($path==='/logout'&&$method==='POST') { Auth::csrf();$_SESSION=[];session_destroy();$this->json(['redirect'=>'/login']);return; }
            if ($path==='/') {
                if (empty($_SESSION['user_id'])) { header('Location: /login');return; }
                $this->view('panel');return;
            }
            if (!str_starts_with($path,'/api/')) { throw new AppException('页面不存在',404); }
            $write=!in_array($method,['GET','HEAD'],true);
            $admin=str_starts_with($path,'/api/admin/');
            $scope=str_contains($path,'/logs')?'logs:read':($path==='/api/v1/config'?'config:read':($write?'domains:write':'domains:read'));
            $actor=$auth->identity($scope,$write,$admin);
            $input=$write?$this->input():[];
            $crypto=new Crypto($config['key']);
            $cloud=new Cloud($db,$crypto);
            $configuration=new Configuration($db,$crypto,$cloud);
            $domains=new DomainService($db);
            if ($path==='/api/v1/zones'&&$method==='GET') {
                $accountId=null;
                if(array_key_exists('account_id',$_GET)){
                    $accountId=filter_var($_GET['account_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                    if($accountId===false){throw new AppException('account_id 必须是正整数；省略时读取全部账号');}
                }
                $this->json((new ZoneRecords($db))->zones($accountId));return;
            }
            if (preg_match('#^/api/v1/zones/(\d+)/records/bulk$#D',$path,$m)&&$method==='POST') {
                $result=$db->operationalLock(fn():array=>$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn():array=>(new BulkRecords($db,$cloud))->execute((int)$m[1],$input,$actor),$method.' '.$path));
                $this->json($result,$result['preview']?200:202);return;
            }
            if (preg_match('#^/api/v1/zones/(\d+)/records$#D',$path,$m)) {
                $records=new ZoneRecords($db);
                if ($method==='GET') { [$page,$per]=$this->pagination();$this->json($records->records((int)$m[1],$page,$per,(string)($_GET['search']??''),($_GET['include_existing']??'')==='1'));return; }
                if ($method==='POST') { $this->json($db->operationalLock(fn():array=>$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn():array=>$records->create((int)$m[1],$input,$actor),$method.' '.$path)),202);return; }
            }
            if ($path==='/api/v1/config'&&$method==='GET') { $this->json($configuration->list());return; }
            if(preg_match('#^/api/v1/dns-operations/(\d+)$#D',$path,$m)&&$method==='GET'){$this->json((new \Panel\Services\DnsOperations($db,$cloud))->detail((int)$m[1]));return;}
            if(preg_match('#^/api/v1/dns-operations/(\d+)/retry$#D',$path,$m)&&$method==='POST'){$this->json($db->operationalLock(fn()=>(new \Panel\Services\DnsOperations($db,$cloud))->retry((int)$m[1],$actor)),202);return;}
            if ($path==='/api/v1/domains'&&$method==='GET') {
                [$page,$per,$offset]=$this->pagination();
                $items=$db->all('SELECT d.*,p.name AS profile_name,e.name AS entry_name,o.name AS origin_name,j.id AS job_id,j.stage AS job_stage,j.status AS job_status FROM domains d JOIN profiles p ON p.id=d.profile_id JOIN entries e ON e.id=d.entry_id JOIN origins o ON o.id=d.origin_id LEFT JOIN jobs j ON j.id=(SELECT MAX(jj.id) FROM jobs jj WHERE jj.domain_id=d.id) WHERE d.deleted_at IS NULL ORDER BY d.id DESC LIMIT '.$per.' OFFSET '.$offset);
                foreach ($items as &$item) { $applied=(new DomainConfig($db))->applied($item);$item['entry_name']=$applied['entry']['name'];$item['origin_name']=$applied['origin']['name'];$item['applied_config']=$applied; }unset($item);
                $stats=$db->one("SELECT COUNT(*) AS total,SUM(status='active') AS active,SUM(status IN ('queued','waiting','changing')) AS running,SUM(status IN ('failed','needs_attention','rollback_failed')) AS attention FROM domains WHERE deleted_at IS NULL");
                $this->json(['items'=>$items,'page'=>$page,'per_page'=>$per,'total'=>(int)$stats['total'],'stats'=>$stats]);return;
            }
            if ($path==='/api/v1/domains'&&$method==='POST') {
                $result=$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn():array=>$domains->create($input,$actor),$method.' '.$path);
                $this->json($result,202);return;
            }
            if (preg_match('#^/api/v1/domains/(\d+)$#D',$path,$m)&&$method==='GET') { $this->json($domains->detail((int)$m[1]));return; }
            if (preg_match('#^/api/v1/domains/(\d+)$#D',$path,$m)&&$method==='PATCH') {
                $result=$db->operationalLock(fn():array=>$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn():array=>$domains->configure((int)$m[1],$input,$actor),$method.' '.$path));
                $this->json($result,202);return;
            }
            if (preg_match('#^/api/v1/domains/(\d+)$#D',$path,$m)&&$method==='DELETE') {
                $result=$db->operationalLock(fn():array=>$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn():array=>$domains->remove((int)$m[1],$input,$actor),$method.' '.$path));
                $this->json($result,202);return;
            }
            if (preg_match('#^/api/v1/domains/(\d+)/(retry|resume|pause|rollback|diagnose|cancel)$#D',$path,$m)&&$method==='POST') {
                $result=$db->operationalLock(fn():array=>$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn():array=>$domains->action((int)$m[1],$m[2],$actor),$method.' '.$path));
                $this->json($result,202);
                return;
            }
            if (preg_match('#^/api/v1/jobs/(\d+)$#D',$path,$m)&&$method==='GET') {
                $job=$db->one('SELECT * FROM jobs WHERE id=?',[(int)$m[1]]);
                if (!$job) { throw new AppException('任务不存在',404); }
                $this->json($job);return;
            }
            if ($path==='/api/v1/logs'&&$method==='GET') {
                [$page,$per,$offset]=$this->pagination();$where=[];$params=[];
                foreach (['domain_id','job_id'] as $field) { if (!empty($_GET[$field])) { $where[]=$field.'=?';$params[]=(int)$_GET[$field]; } }
                if (!empty($_GET['hostname'])) { $where[]='domain_id IN (SELECT id FROM domains WHERE hostname LIKE ?)';$params[]='%'.mb_substr((string)$_GET['hostname'],0,253).'%'; }
                if (!empty($_GET['event'])) { $where[]='event LIKE ?';$params[]=mb_substr((string)$_GET['event'],0,100).'%'; }
                if (!empty($_GET['actor'])) { $where[]='actor=?';$params[]=mb_substr((string)$_GET['actor'],0,100); }
                foreach (['since'=>'>=','until'=>'<='] as $field=>$operator) {
                    if (empty($_GET[$field])) { continue; }
                    $time=strtotime((string)$_GET[$field]);
                    if ($time===false) { throw new AppException('日志时间格式不正确'); }
                    $where[]='created_at'.$operator.'?';$params[]=gmdate('Y-m-d H:i:s',$time);
                }
                $condition=$where?' WHERE '.implode(' AND ',$where):'';
                $this->json(['items'=>$db->all('SELECT * FROM audit_logs'.$condition.' ORDER BY id DESC LIMIT '.$per.' OFFSET '.$offset,$params),'page'=>$page,'per_page'=>$per,'total'=>(int)$db->value('SELECT COUNT(*) FROM audit_logs'.$condition,$params)]);return;
            }
            if ($admin) {
                if(preg_match('#^/api/admin/zones/(\d+)/dns-sync$#D',$path,$m)&&$method==='POST'){$this->json((new \Panel\Services\ZoneDns($db))->sync((int)$m[1],$cloud,$actor));return;}
                if(preg_match('#^/api/admin/accounts/(\d+)/auth$#D',$path,$m)&&$method==='GET'){$this->json($configuration->authentication((int)$m[1]));return;}
                if($path==='/api/admin/saas-inventory'){
                    $inventory=new \Panel\Services\SaasInventory($db,$cloud);
                    if($method==='GET'){$this->json($inventory->status());return;}
                    if($method==='POST'){$this->json($auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn()=>$inventory->begin($input,$actor),$method.' '.$path),201);return;}
                }
                if(preg_match('#^/api/admin/saas-inventory/(\d+)(?:/(step|items|adopt))?$#D',$path,$m)){
                    $inventory=new \Panel\Services\SaasInventory($db,$cloud);$action=$m[2]??'';
                    if($method==='GET'&&$action===''){$this->json($inventory->status((int)$m[1]));return;}
                    if($method==='GET'&&$action==='items'){[$page,$per]=$this->pagination();$this->json($inventory->items((int)$m[1],$page,$per,(string)($_GET['search']??'')));return;}
                    if($method==='POST'&&$action==='step'){$this->json($inventory->step((int)$m[1],$actor));return;}
                    if($method==='POST'&&$action==='adopt'){$this->json($db->operationalLock(fn()=>$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn()=>(new \Panel\Services\SaasAdoption($db,$cloud,$configuration))->adopt((int)$m[1],$input,$actor),$method.' '.$path)));return;}
                }
                if(preg_match('#^/api/admin/accounts/(\d+)/saas-discovery$#D',$path,$m)&&$method==='GET'){$this->json((new \Panel\Services\SaasDiscovery($db,$cloud))->read((int)$m[1],(string)($_GET['zone_id']??'')));return;}
                if ($path==='/api/admin/export'&&$method==='GET') { $export=$db->operationalLock(fn():array=>$db->transaction(fn():array=>(new Transfer($db,$configuration))->export(isset($_GET['zone_id'])?(int)$_GET['zone_id']:null)));(new Audit($db))->write($actor,'config.exported','导出配置与记录计划：'.count($export['records']).' 条记录（不含凭据）');$this->json($export);return; }
                if ($path==='/api/admin/import'&&$method==='POST') { $this->json($db->operationalLock(fn():array=>$auth->idempotent($actor,$_SERVER['HTTP_IDEMPOTENCY_KEY']??null,$input,fn():array=>(new Transfer($db,$configuration))->import($input,$actor),$method.' '.$path)));return; }
                if (preg_match('#^/api/admin/(accounts|profiles|entries|origins)$#D',$path,$m)&&$method==='POST') { $this->json(['id'=>$configuration->save($m[1],$input,$actor)],201);return; }
                if (preg_match('#^/api/admin/(accounts|profiles|entries|origins)/(\d+)$#D',$path,$m)&&$method==='PATCH') { $this->json($configuration->update($m[1],(int)$m[2],$input,$actor));return; }
                if (preg_match('#^/api/admin/(accounts|profiles|entries|origins)/(\d+)$#D',$path,$m)&&$method==='DELETE') { $this->json($configuration->delete($m[1],(int)$m[2],$actor));return; }
                if ($path==='/api/admin/defaults'&&$method==='PATCH') { $this->json($db->operationalLock(fn():array=>(new Settings($db))->save($input,$actor)));return; }
                if (preg_match('#^/api/admin/accounts/(\d+)/sync$#D',$path,$m)&&$method==='POST') { $this->json($configuration->sync((int)$m[1]));return; }
                if (preg_match('#^/api/admin/profiles/(\d+)/(check|setup)$#D',$path,$m)&&$method==='POST') { $this->json($configuration->profileCheck((int)$m[1],$m[2]==='setup',$actor));return; }
                if ($path==='/api/admin/tokens'&&$method==='GET') { $this->json(['items'=>$db->all('SELECT id,name,scopes,expires_at,revoked_at,last_used_at,created_at FROM api_tokens ORDER BY id DESC')]);return; }
                if ($path==='/api/admin/tokens'&&$method==='POST') { $result=$auth->createToken($input);(new Audit($db))->write($actor,'api_key.created','创建 API Key #'.$result['id']);$this->json($result,201);return; }
                if (preg_match('#^/api/admin/tokens/(\d+)/revoke$#D',$path,$m)&&$method==='POST') { $db->run('UPDATE api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[(int)$m[1]]);(new Audit($db))->write($actor,'api_key.revoked','撤销 API Key #'.$m[1]);$this->json(['revoked'=>true]);return; }
                if ($path==='/api/admin/password'&&$method==='POST') {
                    $user=$db->one('SELECT * FROM users WHERE id=?',[$_SESSION['user_id']]);
                    $newPassword=(string)($input['password']??'');
                    if (!password_verify((string)($input['current_password']??''),$user['password_hash'])||strlen($newPassword)<12||strlen($newPassword)>72) { throw new AppException('原密码不正确，或新密码长度不在 12 至 72 字节内'); }
                    $db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($input['password'],PASSWORD_DEFAULT),$user['id']]);
                    (new Audit($db))->write($actor,'auth.password_changed','管理员密码已更新');
                    session_regenerate_id(true);$this->json(['changed'=>true]);return;
                }
            }
            throw new AppException('接口不存在或请求方法不正确',404);
        } catch (\Throwable $exception) {
            $status=$exception instanceof AppException?$exception->status:500;
            $message=$exception instanceof AppException?$exception->getMessage():'服务异常，请查看服务器日志；请求标识 '.$this->requestId;
            if (!$exception instanceof AppException) { error_log('Panel request '.$this->requestId.' '.get_class($exception).' '.$exception->getMessage()); }
            $this->json(['code'=>$exception instanceof AppException?$exception->errorCode:'internal_error','message'=>$message],$status,false);
        } finally {
            if (isset($actor)&&str_starts_with($actor,'api:')&&$this->db) {
                try { (new Audit($this->db))->write($actor,'api.request',$method.' '.$path.' HTTP '.http_response_code().' source='.($_SERVER['REMOTE_ADDR']??'unknown').' request='.$this->requestId); }catch(\Throwable) {}
            }
        }
    }
    private function schemaCheck(Database $db):void
    {
        $files=glob(BASE_PATH.'/database/migrations/*.sql');$latest=basename(end($files));
        if (!(int)$db->value('SELECT COUNT(*) FROM migrations WHERE name=?',[$latest])) { throw new AppException('数据库需要升级，请执行 php bin/console.php migrate',503,'migration_required'); }
    }
    private function input(): array
    {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>1048576) { throw new AppException('请求内容过大',413); }
        if (str_contains($_SERVER['CONTENT_TYPE']??'','application/json')) {
            $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,1048577);fclose($stream);
            if (strlen($raw)>1048576) { throw new AppException('请求内容过大',413); }
            try { $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new AppException('JSON 格式不正确',400); }
            if (!is_array($input)||array_is_list($input)&&$input!==[]) { throw new AppException('请求必须为 JSON 对象',400); }
            return $input;
        }
        return $_POST;
    }
    private function pagination(): array
    {
        $page=max(1,min(100000,(int)($_GET['page']??1)));$per=max(1,min(100,(int)($_GET['per_page']??50)));
        return [$page,$per,($page-1)*$per];
    }
    private function json(mixed $data,int $status=200,bool $success=true): void
    {
        http_response_code($status);header('Content-Type: application/json; charset=utf-8');
        echo Validator::json(['success'=>$success,$success?'data':'error'=>$data,'request_id'=>$this->requestId]);
    }
    private function view(string $name,array $vars=[]): void
    {
        header('Content-Type: text/html; charset=utf-8');
        extract($vars,EXTR_SKIP);require BASE_PATH.'/resources/views/'.$name.'.php';
    }
}
