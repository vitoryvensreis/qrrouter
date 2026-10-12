<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
security_headers();
if (!is_file(__DIR__.'/app/config.php')) json_response(['error'=>'Instale o sistema primeiro.'], 503);

try {
    start_session();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); json_response(['error'=>'Método não permitido.'], 405); }
    $body = [];
    if ($method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) json_response(['error'=>'Requisição muito grande.'], 413);
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen((string) $raw) > 16384) json_response(['error'=>'Requisição muito grande.'], 413);
        $body = json_decode((string)$raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body)) json_response(['error'=>'Dados inválidos.'], 400);
        if (!valid_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) json_response(['error'=>'Atualize a página e tente novamente.'], 419);
    }
    $action = (string) ($method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? ''));
    if ($action === 'login' && $method === 'POST') {
        rate_limit('login-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 60, 900);
        $email = validate_email($body['email'] ?? '');
        rate_limit('login-email:' . $email, 10, 900);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if (strlen($password) > 72 || str_contains($password, "\0")) json_response(['error'=>'E-mail ou senha incorretos.'], 401);
        $q = db()->prepare('SELECT * FROM rq_users WHERE email = ? LIMIT 1');
        $q->execute([$email]);
        $u = $q->fetch();
        $dummy = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified = password_verify($password, $u ? $u['password_hash'] : $dummy);
        if (!$u || !$verified || !(int)$u['active']) json_response(['error'=>'E-mail ou senha incorretos.'], 401);
        session_regenerate_id(true);
        $_SESSION = ['uid'=>(int)$u['id'], 'version'=>(int)$u['session_version'], 'started'=>time(), 'last_seen'=>time(), 'csrf'=>bin2hex(random_bytes(32))];
        json_response(['ok'=>true]);
    }
    $user = require_user();
    if ($action === 'logout' && $method === 'POST') { destroy_login(); json_response(['ok'=>true]); }
    if ((int)$user['must_change_password'] && $action !== 'password.change') json_response(['error'=>'Defina sua senha pessoal para continuar.','password_required'=>true], 403);
    if ($action === 'password.change' && $method === 'POST') {
        rate_limit('password:' . $user['id'], 10, 900);
        $password = validate_password($body['password'] ?? '');
        $current = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';
        $q = db()->prepare('SELECT password_hash FROM rq_users WHERE id = ?');
        $q->execute([$user['id']]);
        $oldHash = (string)$q->fetchColumn();
        if (strlen($current) > 72 || str_contains($current,"\0") || !password_verify($current, $oldHash)) json_response(['error'=>'A senha atual está incorreta.'], 422);
        if (password_verify($password, $oldHash)) throw new InvalidArgumentException('Escolha uma senha diferente da atual.');
        $q = db()->prepare('UPDATE rq_users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1 WHERE id = ? AND session_version = ?');
        $q->execute([hash_password($password), $user['id'], $user['session_version']]);
        if ($q->rowCount() !== 1) json_response(['error'=>'Sua conta foi alterada. Entre novamente.'],409);
        session_regenerate_id(true);
        $_SESSION['version'] = (int)$user['session_version']+1;
        json_response(['ok'=>true]);
    }
    if ($action === 'links.list' && $method === 'GET') {
        $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $filter = $user['role'] === 'admin' ? '' : ' WHERE l.user_id = ?';
        $args = $user['role'] === 'admin' ? [] : [$user['id']];
        $q = db()->prepare('SELECT COUNT(*) FROM rq_links l' . $filter); $q->execute($args);
        $total = (int)$q->fetchColumn();
        $pages = max(1,(int)ceil($total/30)); $page = min($page,$pages);
        $q = db()->prepare('SELECT l.*, u.name AS owner_name FROM rq_links l JOIN rq_users u ON u.id = l.user_id' . $filter . ' ORDER BY l.id DESC LIMIT 30 OFFSET ' . (($page-1)*30));
        $q->execute($args);
        json_response(['links'=>array_map('link_record',$q->fetchAll()),'total'=>$total,'page'=>$page,'pages'=>$pages]);
    }
    if ($action === 'links.create' && $method === 'POST') {
        $name = validate_name($body['name'] ?? '');
        $destination = validate_destination($body['destination'] ?? '');
        $code = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
        $q = db()->prepare('INSERT INTO rq_links (user_id, name, code, destination, active) VALUES (?, ?, ?, ?, 1)');
        $q->execute([$user['id'], $name, $code, $destination]);
        $id = (int)db()->lastInsertId();
        $q = db()->prepare('SELECT * FROM rq_links WHERE id = ?'); $q->execute([$id]);
        json_response(['link'=>link_record($q->fetch())],201);
    }
    if (in_array($action,['links.update','links.delete'],true) && $method === 'POST') {
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$id) throw new InvalidArgumentException('Link inválido.');
        $q = db()->prepare('SELECT * FROM rq_links WHERE id = ?'); $q->execute([$id]); $link = $q->fetch();
        if (!$link || ($user['role'] !== 'admin' && (int)$link['user_id'] !== (int)$user['id'])) json_response(['error'=>'Link não encontrado.'],404);
        if ($action === 'links.delete') {
            $q = db()->prepare('DELETE FROM rq_links WHERE id = ?'); $q->execute([$id]); json_response(['ok'=>true]);
        }
        $name = validate_name($body['name'] ?? '');
        $destination = validate_destination($body['destination'] ?? '');
        $active = ($body['active'] ?? true) === true ? 1 : 0;
        $q = db()->prepare('UPDATE rq_links SET name = ?, destination = ?, active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $q->execute([$name,$destination,$active,$id]);
        json_response(['ok'=>true]);
    }
    if ($action === 'users.list' && $method === 'GET') {
        require_admin($user);
        $page = max(1,min(100000,(int)($_GET['page'] ?? 1)));
        $total = (int) db()->query('SELECT COUNT(*) FROM rq_users')->fetchColumn();
        $pages = max(1,(int)ceil($total/30)); $page = min($page,$pages);
        $rows = db()->query('SELECT id,name,email,role,active,must_change_password,created_at FROM rq_users ORDER BY id DESC LIMIT 30 OFFSET ' . (($page-1)*30))->fetchAll();
        json_response(['users'=>array_map('user_record',$rows),'total'=>$total,'page'=>$page,'pages'=>$pages]);
    }
    if ($action === 'users.create' && $method === 'POST') {
        require_admin($user);
        $name = validate_name($body['name'] ?? ''); $email = validate_email($body['email'] ?? ''); $password = validate_password($body['password'] ?? '');
        $role = ($body['role'] ?? '') === 'admin' ? 'admin' : 'user';
        $q = db()->prepare('INSERT INTO rq_users (name,email,password_hash,role,active,must_change_password) VALUES (?,?,?,?,1,1)');
        $q->execute([$name,$email,hash_password($password),$role]);
        json_response(['ok'=>true],201);
    }
    if ($action === 'users.update' && $method === 'POST') {
        require_admin($user);
        $id = filter_var($body['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) throw new InvalidArgumentException('Usuário inválido.');
        $name = validate_name($body['name'] ?? ''); $email = validate_email($body['email'] ?? '');
        $role = ($body['role'] ?? '') === 'admin' ? 'admin' : 'user'; $active = ($body['active'] ?? true) === true ? 1 : 0;
        $newPassword = $body['password'] ?? '';
        $newHash = $newPassword === '' ? null : hash_password(validate_password($newPassword));
        if ((int)$id === (int)$user['id'] && (!$active || $role !== 'admin')) throw new InvalidArgumentException('Você não pode remover seu próprio acesso de administrador.');
        db()->beginTransaction();
        // Lock all administrators in a consistent order: concurrent changes cannot remove the last active admin.
        $admins = db()->query("SELECT id FROM rq_users WHERE role = 'admin' AND active = 1 ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        $q = db()->prepare('SELECT * FROM rq_users WHERE id = ? FOR UPDATE'); $q->execute([$id]); $target = $q->fetch();
        if (!$target) { db()->rollBack(); json_response(['error'=>'Usuário não encontrado.'],404); }
        if ($target['role'] === 'admin' && (int)$target['active'] && (!$active || $role !== 'admin') && count($admins) <= 1) throw new InvalidArgumentException('Mantenha pelo menos um administrador ativo.');
        $sql = 'UPDATE rq_users SET name = ?, email = ?, role = ?, active = ?, session_version = session_version + 1';
        $args = [$name,$email,$role,$active];
        if ($newHash !== null) { $sql .= ', password_hash = ?, must_change_password = 1'; $args[] = $newHash; }
        $sql .= ' WHERE id = ?'; $args[] = $id;
        $q = db()->prepare($sql); $q->execute($args); db()->commit();
        if ((int)$id === (int)$user['id']) $_SESSION['version'] = (int)$target['session_version']+1;
        json_response(['ok'=>true,'self_updated'=>(int)$id === (int)$user['id']]);
    }
    json_response(['error'=>'Operação não encontrada.'],404);
} catch (JsonException $error) {
    json_response(['error'=>'Não foi possível ler os dados enviados.'],400);
} catch (InvalidArgumentException $error) {
    if (isset($user) && db()->inTransaction()) db()->rollBack();
    json_response(['error'=>$error->getMessage()],422);
} catch (Throwable $error) {
    if (isset($user)) { try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {} }
    if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) json_response(['error'=>'Este e-mail já está cadastrado.'],409);
    error_log('RotaQR API: ' . get_class($error) . ' code=' . $error->getCode());
    json_response(['error'=>'Não foi possível concluir agora. Seus dados digitados foram mantidos; tente novamente.'],503);
}
