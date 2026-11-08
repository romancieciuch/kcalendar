<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required']); exit; }
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) === 1;
if (empty($_SERVER['HTTPS']) && !$isLocal) { http_response_code(426); echo json_encode(['ok'=>false,'error'=>'Połączenie HTTPS jest wymagane']); exit; }
$base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($base)) { mkdir($base, 0700, true); }
$db = new PDO('sqlite:' . $base . DIRECTORY_SEPARATOR . 'kcalendar.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, salt TEXT NOT NULL, verifier TEXT NOT NULL, data TEXT NOT NULL DEFAULT "{}"); CREATE TABLE IF NOT EXISTS challenges (nonce TEXT PRIMARY KEY, username TEXT NOT NULL, expires INTEGER NOT NULL); CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires INTEGER NOT NULL);');
require_once __DIR__ . '/data_store.php';
prepareDataStore($db, $base);
function out(array $v, int $code=200): never { http_response_code($code); echo json_encode($v, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
$in=json_decode(file_get_contents('php://input') ?: '{}',true) ?: [];
$action=$_GET['action'] ?? '';
if ($action==='challenge') {
  $u=trim((string)($in['user']??'')); $q=$db->prepare('SELECT salt FROM users WHERE username=?'); $q->execute([$u]); $row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row) out(['ok'=>false,'error'=>'Nieprawidłowy login lub hasło'],401);
  $nonce=bin2hex(random_bytes(32)); $q=$db->prepare('INSERT INTO challenges VALUES(?,?,?)');$q->execute([$nonce,$u,time()+120]);
  out(['ok'=>true,'salt'=>json_decode($row['salt'],true),'nonce'=>$nonce]);
}
if ($action==='auth') {
  $mode=$in['mode']??''; $u=trim((string)($in['user']??''));
  if(!preg_match('/^[\pL\pN_.@-]{2,64}$/u',$u)) out(['ok'=>false,'error'=>'Nieprawidłowa nazwa użytkownika'],400);
  if($mode==='register') {
    $ver=(string)($in['verifier']??''); $salt=$in['salt']??[];
    if(!preg_match('/^[a-f0-9]{64}$/',$ver)||!is_array($salt)||count($salt)!==16) out(['ok'=>false,'error'=>'Nieprawidłowe dane konta'],400);
    try {$q=$db->prepare('INSERT INTO users(username,salt,verifier,data) VALUES(?,?,?,?)');$q->execute([$u,json_encode(array_values($salt)),$ver,'{"foods":[],"meals":[],"days":{}}']);$id=(int)$db->lastInsertId();} catch(Throwable $e){out(['ok'=>false,'error'=>'Ta nazwa użytkownika jest już zajęta'],409);}
  } elseif($mode==='login') {
    $nonce=(string)($in['nonce']??'');$proof=(string)($in['proof']??'');$q=$db->prepare('SELECT c.username,c.expires,u.id,u.verifier FROM challenges c JOIN users u ON u.username=c.username WHERE c.nonce=?');$q->execute([$nonce]);$row=$q->fetch(PDO::FETCH_ASSOC);$db->prepare('DELETE FROM challenges WHERE nonce=?')->execute([$nonce]);
    $nonceBytes=preg_match('/^[a-f0-9]{64}$/',$nonce) ? hex2bin($nonce) : false;
    $authKey=$row ? hex2bin((string)$row['verifier']) : false;
    $validProof=$row&&$nonceBytes!==false&&$authKey!==false&&(hash_equals(hash_hmac('sha256',$nonceBytes,$authKey),$proof)||hash_equals(hash_hmac('sha256',$nonceBytes,(string)$row['verifier']),$proof));
    if(!$row||$row['expires']<time()||!$validProof)out(['ok'=>false,'error'=>'Nieprawidłowy login lub hasło'],401);$id=(int)$row['id'];
  } else out(['ok'=>false,'error'=>'Nieprawidłowe żądanie'],400);
  $uuid=migrateUserData($db,$base,$id);$token=bin2hex(random_bytes(32));$q=$db->prepare('INSERT INTO sessions VALUES(?,?,?)');$q->execute([hash('sha256',$token),$id,time()+60*60*24*30]);out(['ok'=>true,'token'=>$token,'uuid'=>$uuid]);
}
$token=(string)($in['token']??'');$q=$db->prepare('SELECT u.id,u.username,u.data_uuid,s.expires FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=?');$q->execute([hash('sha256',$token)]);$user=$q->fetch(PDO::FETCH_ASSOC);
if(!$user||$user['expires']<time())out(['ok'=>false,'error'=>'Sesja wygasła'],401);
if($action==='logout') { $db->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256',$token)]); out(['ok'=>true]); }
$uuid=(string)($user['data_uuid']??'');
try {if($uuid===''||!is_file(dataFilePath($base,$uuid)))$uuid=migrateUserData($db,$base,(int)$user['id']);}
catch(Throwable $e){out(['ok'=>false,'error'=>'Nie udało się przygotować danych konta'],500);}
try {$document=readDataFile(dataFilePath($base,$uuid));}catch(Throwable $e){out(['ok'=>false,'error'=>'Nie udało się odczytać danych konta'],500);}
if($action==='status')out(['ok'=>true,'user'=>$user['username'],'uuid'=>$uuid,'revision'=>$document['revision'],'updatedAt'=>$document['updatedAt']]);
if($action==='load'){
  $data=$document['data'];
  if(isset($data['days'])&&is_array($data['days'])&&$data['days']===[])$data['days']=(object)[];
  out(['ok'=>true,'user'=>$user['username'],'uuid'=>$uuid,'revision'=>$document['revision'],'updatedAt'=>$document['updatedAt'],'data'=>$data]);
}
if($action==='export') {
  $start=(string)($in['start']??''); $end=(string)($in['end']??'');
  $from=DateTimeImmutable::createFromFormat('!Y-m-d',$start); $to=DateTimeImmutable::createFromFormat('!Y-m-d',$end);
  if(!$from||!$to||$from->format('Y-m-d')!==$start||$to->format('Y-m-d')!==$end||$from>$to||$from->diff($to)->days>3652)
    out(['ok'=>false,'error'=>'Wybierz poprawny zakres dat (maksymalnie 10 lat)'],400);
  $path=tempnam($base,'kcalendar_export_');
  if($path===false)out(['ok'=>false,'error'=>'Nie udało się przygotować eksportu'],500);
  try {
    require_once __DIR__ . '/export_xlsx.php';
    buildExportXlsx($document['data'],$from,$to,$path);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="kcalendar_'.$start.'_'.$end.'.xlsx"');
    header('Content-Length: '.filesize($path));
    readfile($path);
  } catch(Throwable $e) {
    @unlink($path);
    out(['ok'=>false,'error'=>'Nie udało się przygotować pliku XLSX'],500);
  }
  @unlink($path); exit;
}
if($action==='save_day') {
  $date=(string)($in['date']??''); $day=$in['day']??null; $revision=$in['revision']??null;
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!is_array($day)||!is_array($day['meals']??null)||!is_array($day['activities']??null)||!is_int($revision)) out(['ok'=>false,'error'=>'Nieprawidłowe dane dnia'],400);
  try {
    $result=updateUserData($base,$uuid,$revision,static function(array $data) use($date,$day): array {
      if(!is_array($data['days']??null)) $data['days']=[];
      $data['days'][$date]=['meals'=>$day['meals'],'activities'=>$day['activities']];
      return $data;
    });
    if(isset($result['conflict']))out(['ok'=>false,'error'=>'Dane na serwerze są nowsze','revision'=>$result['revision'],'updatedAt'=>$result['updatedAt']],409);
    out($result);
  } catch(Throwable $e) {out(['ok'=>false,'error'=>'Nie udało się zapisać dnia'],500);}
}
if($action==='save') {
  $data=$in['data']??null; $revision=$in['revision']??null;
  if(!is_array($data)||!is_int($revision))out(['ok'=>false,'error'=>'Nieprawidłowe dane'],400);
  try {
    $result=updateUserData($base,$uuid,$revision,static fn(array $current): array=>$data);
    if(isset($result['conflict']))out(['ok'=>false,'error'=>'Dane na serwerze są nowsze','revision'=>$result['revision'],'updatedAt'=>$result['updatedAt']],409);
    out($result);
  } catch(Throwable $e) {out(['ok'=>false,'error'=>'Nie udało się zapisać danych'],500);}
}
out(['ok'=>false,'error'=>'Nieznana operacja'],404);
