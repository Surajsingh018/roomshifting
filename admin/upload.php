<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
admin_require_role(['admin','staff']);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');http_response_code(405);exit('Method not allowed.');}
admin_verify_csrf();
$file=$_FILES['media']??null;
if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')){http_response_code(422);exit('Choose a valid image file.');}
if((int)$file['size']<1||(int)$file['size']>5*1024*1024){http_response_code(413);exit('Images must be 5 MB or smaller.');}
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
if(!isset($allowed[$mime])||@getimagesize($file['tmp_name'])===false){http_response_code(415);exit('Only valid JPEG, PNG, and WebP images are accepted.');}
$directory=dirname(__DIR__).'/assets/uploads';if(!is_dir($directory)&&!mkdir($directory,0750,true)){http_response_code(500);exit('Upload storage is unavailable.');}
$name=bin2hex(random_bytes(20)).'.'.$allowed[$mime];if(!move_uploaded_file($file['tmp_name'],$directory.'/'.$name)){http_response_code(500);exit('Image upload failed.');}
$url=roomshift_base_url().'/assets/uploads/'.$name;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Image uploaded | RoomShift Admin</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="container py-5"><h1 class="h3">Image uploaded</h1><p>Validated image path:</p><code><?= htmlspecialchars($url,ENT_QUOTES,'UTF-8') ?></code><p class="mt-4"><a href="manage.php?type=banners">Return to CMS</a></p></body></html>
