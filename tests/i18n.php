<?php
// SPDX-License-Identifier: AGPL-3.0-only
use Panel\Core\I18n;

$oldCookies=$_COOKIE;$oldAccept=$_SERVER['HTTP_ACCEPT_LANGUAGE']??null;
try {
    unset($_COOKIE['panel_locale'],$_SERVER['HTTP_ACCEPT_LANGUAGE']);
    check(I18n::current()==='zh-CN','locale defaults to Simplified Chinese');
    $_SERVER['HTTP_ACCEPT_LANGUAGE']='fr-FR,en-US;q=0.8,zh-CN;q=0.3';
    check(I18n::current()==='en','browser language selects the highest supported preference');
    $_SERVER['HTTP_ACCEPT_LANGUAGE']='en;q=0,zh-TW;q=0.5';
    check(I18n::current()==='zh-CN','zero-weight language is ignored');
    $_COOKIE['panel_locale']='en';
    check(I18n::current()==='en','saved language overrides browser preference');
    check(I18n::text('域名管理')==='Domains','server templates translate interface labels');
    check(I18n::message('API Key 缺少权限：domains:read')==='API key is missing permission: domains:read','dynamic errors translate without changing permission identifiers');
    check(I18n::message('入口名称已有不匹配 DNS，面板不会覆盖：www.测试.test')==='Conflicting DNS exists at the entry name. The panel will not overwrite it: www.测试.test','dynamic error parameters retain user data');
    check(I18n::message('用户账号：我的服务器')==='用户账号：我的服务器','unknown user content is not translated');
    $_COOKIE['panel_locale']="en\r\nX-Test: injected";
    check(I18n::current()==='zh-CN','invalid saved language cannot inject response headers');
    $_COOKIE['panel_locale']=['en'];
    check(I18n::current()==='zh-CN','array-shaped locale is ignored');
    $dictionary=I18n::dictionary('en');
    check(count($dictionary)>1000,'English catalog includes interface, errors and task messages');
    foreach($dictionary as $source=>$translation){preg_match_all('/\{\d+\}/',$source,$a);preg_match_all('/\{\d+\}/',$translation,$b);sort($a[0]);sort($b[0]);if($a[0]!==$b[0]||$translation===''||preg_match('/\p{Han}/u',$translation)){throw new RuntimeException('Invalid translation: '.$source);}}
    check(true,'English catalog preserves placeholders and contains complete English values');
    $_COOKIE['panel_locale']='en';$_SESSION['csrf']=str_repeat('a',32);$checks=['storage 可写'=>true];$defaults=['db_host'=>'db','db_port'=>3306,'db_name'=>'panel','db_user'=>'panel'];
    ob_start();require BASE_PATH.'/resources/views/install.php';$installMarkup=ob_get_clean();
    check(str_contains($installMarkup,'<html lang="en">')&&str_contains($installMarkup,'Install the panel'),'installer renders English before installation');
    check(substr_count($installMarkup,'<input ')===9&&!str_contains($installMarkup,'&lt;input'),'installer preserves actual form fields during translation');
    check(str_contains($installMarkup,'id="locale-data" hidden>')&&str_contains($installMarkup,'&quot;'),'locale payload is escaped inert HTML instead of inline script');
    $_COOKIE['panel_locale']='zh-CN';
    check(I18n::text('域名管理')==='域名管理','Chinese source labels remain available after switching back');
} finally {$_COOKIE=$oldCookies;if($oldAccept===null){unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);}else{$_SERVER['HTTP_ACCEPT_LANGUAGE']=$oldAccept;}}
