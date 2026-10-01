<?php
// ローカルHTTP検証専用。Webサーバーへの配布対象に含めない。
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$post=$_POST; $files=$_FILES;
require __DIR__.'/bootstrap.php';
$_POST=$post; $_FILES=$files;
function wp_max_upload_size(){return 10*MB_IN_BYTES;}
function size_format($n){return (string)$n;}
function wp_check_filetype_and_ext($path,$name){$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);return $ext==='txt' && $mime==='text/plain'?['ext'=>'txt','type'=>$mime]:['ext'=>false,'type'=>false];}
class UploadProbe {
 use Sharesl\Original\MailForm\OMF_Trait_Validation, Sharesl\Original\MailForm\OMF_Trait_Send;
 public function get_form(int|string|null $id=null): WP_Post|array{return new WP_Post();}
 private function is_valid_nonce():bool{return ($_POST['omf_nonce']??'')==='valid';}
 public function run():array{
  if(!$this->is_valid_nonce()){return ['valid'=>false];}
  $data=$this->restrict_array_values($_POST);
  $errors=$this->validate_submission($data);
  $converted=$this->convert_attachments($data);
  $saved=count($converted['attachment_paths']);
  foreach($converted['attachment_ids'] as $id){Sharesl\Original\MailForm\OMF_Uploads::remove($id);}
  return ['valid'=>$errors===[],'errors'=>$errors,'saved'=>$saved];
 }
}
$GLOBALS['meta'][10]['cf_omf_validation']=[['target'=>'email','required'=>'1','email'=>'1'],['target'=>'file','type'=>'file','extension'=>['txt'],'required'=>'1']];
header('Content-Type: application/json');
echo json_encode((new UploadProbe())->run(),JSON_UNESCAPED_UNICODE);
