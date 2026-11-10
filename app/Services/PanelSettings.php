<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Services;

use Panel\Core\{Config,Database,Validator,AppException};

final class PanelSettings
{
    public function __construct(private Database $db) {}

    public function read():array { return ['app_url'=>Config::read()['app_url']]; }

    public function save(array $input,string $actor):array
    {
        if(array_diff(array_keys($input),['app_url','expected_app_url'])||!is_string($input['expected_app_url']??null)){throw new AppException('请提交面板访问地址和读取时的原地址');}
        $url=Validator::panelUrl($input['app_url']??null);
        $lock=fopen(Config::storage('panel-settings.lock'),'c');
        if($lock===false){throw new AppException('无法保存面板访问地址',500);}
        try{
            if(!flock($lock,LOCK_EX)){throw new AppException('无法保存面板访问地址',500);}
            $config=Config::read();$previous=(string)$config['app_url'];
            if(!hash_equals($previous,$input['expected_app_url'])){throw new AppException('面板地址已被其他操作更新，请刷新后重试',409,'panel_url_conflict');}
            if($previous===$url){return ['app_url'=>$url,'changed'=>false];}
            $original=file_get_contents(Config::storage('config.php'));
            if($original===false){throw new AppException('无法保存面板访问地址',500);}
            $config['app_url']=$url;
            Config::atomicWrite(Config::storage('config.php'),"<?php\nreturn ".var_export($config,true).";\n");
            self::invalidate();
            try{(new Audit($this->db))->write($actor,'panel.url_changed','面板访问地址已更新：'.$url);}
            catch(\Throwable $error){Config::atomicWrite(Config::storage('config.php'),$original);self::invalidate();throw $error;}
            return ['app_url'=>$url,'changed'=>true];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function invalidate():void
    {
        if(function_exists('opcache_invalidate')){@opcache_invalidate(Config::storage('config.php'),true);}
    }
}
