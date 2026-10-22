<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/bootstrap.php';
require_once dirname(__DIR__,2).'/includes/helpers.php';
require_once dirname(__DIR__,2).'/includes/locales.php';
require_once dirname(__DIR__,2).'/backend/repositories/CmsRepository.php';
$locale=(string)($_GET['lang']??$_COOKIE['roomshift_locale']??'en');if(!isset($roomshiftLocales[$locale]))$locale='en';
if(isset($_GET['lang'])&&isset($roomshiftLocales[$_GET['lang']]))setcookie('roomshift_locale',$locale,['expires'=>time()+31536000,'path'=>'/roomshift','samesite'=>'Lax']);
$t=static fn(string $key):string=>$roomshiftLocales[$locale][$key]??$roomshiftLocales['en'][$key]??$key;
function web_cms(): CmsRepository { static $repository=null;return $repository??=new CmsRepository(roomshift_db()); }
function web_catalog(callable $query): mixed { try{return $query();}catch(Throwable $error){error_log('[RoomShift website CMS] '.$error->getMessage());$GLOBALS['webDataError']=true;return null;} }
$pageTitle=$pageTitle??'RoomShift';$pageMeta=$pageMeta??'RoomShift home and office relocation services.';$pageCanonical=$pageCanonical??(roomshift_base_url().roomshift_public_path());$pageImage=$pageImage??'';$webDataError=false;
?><!doctype html><html lang="<?= e($locale) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#414142"><title><?= e($pageTitle) ?></title><meta name="description" content="<?= e($pageMeta) ?>"><link rel="canonical" href="<?= e($pageCanonical) ?>"><meta property="og:type" content="website"><meta property="og:site_name" content="RoomShift"><meta property="og:title" content="<?= e($pageTitle) ?>"><meta property="og:description" content="<?= e($pageMeta) ?>"><meta property="og:url" content="<?= e($pageCanonical) ?>"><?php if($pageImage!==''): ?><meta property="og:image" content="<?= e($pageImage) ?>"><?php endif; ?><link rel="preconnect" href="https://cdn.jsdelivr.net"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="css/style.css" rel="stylesheet"><link href="css/site.css" rel="stylesheet"><script type="application/ld+json"><?= json_encode(['@context'=>'https://schema.org','@type'=>'MovingCompany','name'=>'RoomShift','url'=>roomshift_base_url(),'areaServed'=>['@type'=>'Country','name'=>'India']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?></script></head><body data-api-base="<?= e(roomshift_base_url().'/api') ?>">
