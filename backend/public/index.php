<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$staticRoot = dirname(__DIR__, 2) . '/frontend';
if (!str_starts_with($path, '/api/') && $path !== '/api') {
    $file = realpath($staticRoot . $path);
    if ($path !== '/' && $file && str_starts_with($file, realpath($staticRoot)) && is_file($file)) {
        $types = ['css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','svg'=>'image/svg+xml','png'=>'image/png','ico'=>'image/x-icon'];
        header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        readfile($file); exit;
    }
    if ($method === 'GET') { header('Content-Type: text/html; charset=utf-8'); readfile($staticRoot . '/index.html'); exit; }
}
header('Content-Type: application/json; charset=utf-8');
$origin = getenv('FRONTEND_ORIGIN') ?: 'http://localhost:5173';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) $body = [];
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'mysql', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'super_adicto'),
        getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: 'recuperacao_local',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    respond(503, ['error' => 'Banco de dados indisponível. Aguarde o MySQL e tente novamente.']);
}

function respond(int $status, array $data): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function input(string $key, mixed $default = ''): mixed { global $body; return $body[$key] ?? $default; }
function validDate(string $date): bool { $d = DateTime::createFromFormat('!Y-m-d', $date); return $d && $d->format('Y-m-d') === $date; }
function userId(PDO $pdo): int {
    $token = $_COOKIE['session'] ?? '';
    if ($token === '') respond(401, ['error' => 'Faça login para continuar.']);
    $q = $pdo->prepare('SELECT user_id FROM sessions WHERE token_hash=? AND expires_at > NOW()'); $q->execute([hash('sha256', $token)]); $id = $q->fetchColumn();
    if (!$id) respond(401, ['error' => 'Sessão inválida ou expirada.']);
    return (int)$id;
}
function setSession(PDO $pdo, int $id): void {
    $token = bin2hex(random_bytes(32));
    $q = $pdo->prepare('INSERT INTO sessions(token_hash,user_id,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))'); $q->execute([hash('sha256', $token), $id]);
    setcookie('session', $token, ['expires'=>time()+2592000,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
}
function currentUser(PDO $pdo, int $id): array {
    $q = $pdo->prepare('SELECT id,username FROM users WHERE id=?'); $q->execute([$id]); $user = $q->fetch();
    $q = $pdo->prepare("SELECT display_name,DATE_FORMAT(recovery_start,'%Y-%m-%d') recovery_start,treatment_history,story FROM profiles WHERE user_id=?"); $q->execute([$id]); $p = $q->fetch();
    return ['id'=>(int)$user['id'],'username'=>$user['username'],'hasProfile'=>(bool)$p,'profile'=>$p ? ['displayName'=>$p['display_name'],'recoveryStart'=>$p['recovery_start'],'treatmentHistory'=>(bool)$p['treatment_history'],'story'=>$p['story']] : null];
}

if ($method === 'GET' && $path === '/api/health') respond(200, ['status'=>'ok']);
if ($method === 'POST' && $path === '/api/auth/register') {
    $username = trim((string)input('username')); $password = (string)input('password');
    if (mb_strlen($username)<3 || strlen($password)<8) respond(400,['error'=>'Use um usuário com pelo menos 3 caracteres e senha com 8 ou mais.']);
    try { $q=$pdo->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)'); $q->execute([$username,password_hash($password,PASSWORD_DEFAULT)]); $id=(int)$pdo->lastInsertId(); }
    catch (PDOException $e) { if ($e->getCode()==='23000') respond(409,['error'=>'Esse usuário já existe.']); respond(500,['error'=>'Não foi possível criar a conta.']); }
    setSession($pdo,$id); respond(201,['id'=>$id,'username'=>$username,'hasProfile'=>false]);
}
if ($method === 'POST' && $path === '/api/auth/login') {
    $q=$pdo->prepare('SELECT id,password_hash FROM users WHERE username=?');$q->execute([trim((string)input('username'))]);$u=$q->fetch();
    if (!$u || !password_verify((string)input('password'),$u['password_hash'])) respond(401,['error'=>'Usuário ou senha inválidos.']);
    setSession($pdo,(int)$u['id']); respond(200,currentUser($pdo,(int)$u['id']));
}
$id = userId($pdo);
if ($method==='POST' && $path==='/api/auth/logout') { $pdo->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256',$_COOKIE['session']??'')]);setcookie('session','',['expires'=>1,'path'=>'/','httponly'=>true]);respond(200,['ok'=>true]); }
if ($method==='GET' && $path==='/api/me') respond(200,currentUser($pdo,$id));
if ($method==='PUT' && $path==='/api/profile') {
    $name=trim((string)input('displayName'));$date=(string)input('recoveryStart');
    if ($name===''||!validDate($date)||$date>date('Y-m-d')) respond(400,['error'=>'Informe nome e uma data válida para o início do acompanhamento.']);
    $q=$pdo->prepare('INSERT INTO profiles(user_id,display_name,recovery_start,treatment_history,story) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),recovery_start=VALUES(recovery_start),treatment_history=VALUES(treatment_history),story=VALUES(story)');
    $q->execute([$id,$name,$date,(bool)input('treatmentHistory',false),(string)input('story')]);respond(200,['ok'=>true]);
}
if ($method==='GET' && $path==='/api/calendar') {
    $month=(string)($_GET['month']??'');if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)) respond(400,['error'=>'Informe o mês no formato YYYY-MM.']);
    $start=$month.'-01';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
    $q=$pdo->prepare("SELECT DATE_FORMAT(entry_date,'%Y-%m-%d') date,clean_day cleanDay,notes,emotion,events,inventory,DATE_FORMAT(closed_at,'%Y-%m-%dT%H:%i:%s') closedAt FROM daily_entries WHERE user_id=? AND entry_date>=? AND entry_date<?");$q->execute([$id,$start,$end]);$days=$q->fetchAll();foreach($days as &$d){$d['cleanDay']=(bool)$d['cleanDay'];$d['closedAt']=$d['closedAt']??'';}respond(200,['days'=>$days]);
}
if ($method==='PUT' && preg_match('#^/api/days/(\d{4}-\d{2}-\d{2})$#',$path,$m)) {
    $date=$m[1];if (!validDate($date)||$date>date('Y-m-d')) respond(400,['error'=>'Data inválida.']);
    $q=$pdo->prepare('SELECT recovery_start FROM profiles WHERE user_id=?');$q->execute([$id]);$start=$q->fetchColumn();if(!$start||$date<$start)respond(400,['error'=>'Esse dia está antes do início do seu acompanhamento.']);
    $q=$pdo->prepare('INSERT INTO daily_entries(user_id,entry_date,clean_day,notes,emotion,events,inventory) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE clean_day=VALUES(clean_day),notes=VALUES(notes),emotion=VALUES(emotion),events=VALUES(events),inventory=VALUES(inventory)');
    $q->execute([$id,$date,(bool)input('cleanDay',false),(string)input('notes'),(string)input('emotion'),(string)input('events'),(string)input('inventory')]);respond(200,['ok'=>true]);
}
if ($method==='POST' && preg_match('#^/api/days/(\d{4}-\d{2}-\d{2})/close$#',$path,$m)) {
    $today=date('Y-m-d');if($m[1]!==$today)respond(400,['error'=>'O fechamento está disponível somente para o dia de hoje.']);
    $q=$pdo->prepare('SELECT inventory FROM daily_entries WHERE user_id=? AND entry_date=?');$q->execute([$id,$today]);if(!trim((string)$q->fetchColumn()))respond(400,['error'=>'Preencha seu inventário pessoal antes de fechar o dia.']);
    $pdo->prepare('UPDATE daily_entries SET closed_at=NOW() WHERE user_id=? AND entry_date=?')->execute([$id,$today]);respond(200,['ok'=>true,'message'=>'Mais um dia limpo. Você fez o melhor que podia hoje; amanhã é uma nova oportunidade.']);
}
if ($method==='GET' && $path==='/api/relapses') {
    $q=$pdo->prepare("SELECT id,DATE_FORMAT(start_date,'%Y-%m-%d') startDate,COALESCE(DATE_FORMAT(end_date,'%Y-%m-%d'),'') endDate,duration_text duration,account,observations FROM relapses WHERE user_id=? ORDER BY start_date DESC");$q->execute([$id]);respond(200,['relapses'=>$q->fetchAll()]);
}
if ($method==='POST' && $path==='/api/relapses') {
    $start=(string)input('startDate');$end=(string)input('endDate');$account=(string)input('account');
    if(!validDate($start)||$account==='')respond(400,['error'=>'Informe uma data válida e conte um pouco sobre o que aconteceu.']);
    if($end!==''&&(!validDate($end)||$end<$start))respond(400,['error'=>'A data final precisa ser válida e igual ou posterior à inicial.']);
    $q=$pdo->prepare('INSERT INTO relapses(user_id,start_date,end_date,duration_text,account,observations) VALUES(?,?,?,?,?,?)');$q->execute([$id,$start,$end?:null,(string)input('duration'),$account,(string)input('observations')]);respond(201,['ok'=>true]);
}
if ($method==='DELETE' && preg_match('#^/api/relapses/(\d+)$#',$path,$m)) {
    $q=$pdo->prepare('DELETE FROM relapses WHERE id=? AND user_id=?');$q->execute([(int)$m[1],$id]);if(!$q->rowCount())respond(404,['error'=>'Registro não encontrado.']);respond(200,['ok'=>true]);
}
respond(404,['error'=>'Rota não encontrada.']);
