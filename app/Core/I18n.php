<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Core;

final class I18n
{
    public static function languages():array { return ['zh-CN'=>'简体中文','en'=>'English']; }
    public static function current():string
    {
        $cookie=$_COOKIE['panel_locale']??null;
        if(is_string($cookie)&&isset(self::languages()[$cookie])){return $cookie;}
        $preferences=[];
        foreach(explode(',',(string)($_SERVER['HTTP_ACCEPT_LANGUAGE']??'')) as $position=>$part){
            if(preg_match('/^\s*([a-zA-Z-]+)(?:\s*;\s*q=(0(?:\.\d+)?|1(?:\.0+)?))?\s*$/D',$part,$m)){
                $q=isset($m[2])?(float)$m[2]:1.0;if($q<=0){continue;}
                $code=strtolower($m[1]);if(preg_match('/^en(?:-|$)/',$code)){$preferences[]=['en',$q,$position];}elseif(preg_match('/^zh(?:-|$)/',$code)){$preferences[]=['zh-CN',$q,$position];}
            }
        }
        usort($preferences,static fn($a,$b)=>[$b[1],$a[2]]<=>[$a[1],$b[2]]);
        return $preferences[0][0]??'zh-CN';
    }
    public static function dictionary(?string $locale=null):array
    {
        if(($locale??self::current())!=='en'){return [];}
        static $english=null;
        return $english??=json_decode(file_get_contents(BASE_PATH.'/public/assets/locales/en.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public static function text(string $source):string { return self::dictionary()[$source]??$source; }
    public static function message(string $source):string
    {
        $dictionary=self::dictionary();if(!$dictionary){return $source;}
        if(isset($dictionary[$source])){return $dictionary[$source];}
        static $patterns=null;
        if($patterns===null){
            $patterns=[];
            foreach(self::dictionary('en') as $key=>$translation){
                if(!preg_match('/\{\d+\}/',$key)){continue;}
                $parts=preg_split('/(\{\d+\})/',$key,-1,PREG_SPLIT_DELIM_CAPTURE);$regex='';$slots=[];
                foreach($parts as $part){if(preg_match('/^\{(\d+)\}$/D',$part,$m)){$regex.='(.*?)';$slots[]=$m[1];}else{$regex.=preg_quote($part,'~');}}
                $patterns[]=['key'=>$key,'regex'=>'~^'.$regex.'$~usD','slots'=>$slots,'translation'=>$translation];
            }
            usort($patterns,static fn($a,$b)=>strlen($b['key'])<=>strlen($a['key']));
        }
        foreach($patterns as $pattern){if(!preg_match($pattern['regex'],$source,$matches)){continue;}$values=[];foreach($pattern['slots'] as $index=>$slot){$values['{'.$slot.'}']=$matches[$index+1];}return strtr($pattern['translation'],$values);}
        return $source;
    }
}
