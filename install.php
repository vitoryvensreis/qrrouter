<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
security_headers(true);
if (is_file(__DIR__.'/app/config.php') || is_file(__DIR__.'/storage/installed.lock')) {
    http_response_code(403);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalação concluída</title><link rel="stylesheet" href="assets/style.css"><body class="message-page"><main class="message-card"><h1>O sistema já foi instalado.</h1><p>O instalador está bloqueado.</p><a class="button primary" href="index.php">Abrir o painel</a></main></body></html>';
    exit;
}
start_session(true);
$checks = ['PHP 8.1 ou superior'=>PHP_VERSION_ID >= 80100,'Extensão PDO MySQL'=>extension_loaded('pdo_mysql'),'Extensão mbstring'=>extension_loaded('mbstring'),'Pasta app gravável'=>is_writable(__DIR__.'/app'),'Pasta storage gravável'=>is_writable(__DIR__.'/storage')];
$error = '';
$success = false;
$input = $_POST;
$lock = null;
$pdo = null;
$configWritten = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!valid_csrf($_POST['csrf'] ?? null)) throw new InvalidArgumentException('Atualize a página e tente novamente.');
        if (in_array(false,$checks,true)) throw new InvalidArgumentException('Resolva os requisitos indicados antes de continuar.');
        $expectedKeyHash = require __DIR__.'/app/installer-key.php';
        $key = is_string($_POST['install_key'] ?? null) ? trim($_POST['install_key']) : '';
        if (!hash_equals($expectedKeyHash,hash('sha256',$key))) throw new InvalidArgumentException('A chave de instalação está incorreta. Consulte ACESSO-INSTALACAO.txt no ZIP.');
        $lock = fopen(__DIR__.'/storage/setup.lock','c+');
        if (!$lock || !flock($lock,LOCK_EX | LOCK_NB)) throw new InvalidArgumentException('Há outra instalação em andamento. Aguarde alguns instantes.');
        if (is_file(__DIR__.'/app/config.php')) throw new InvalidArgumentException('O sistema já foi instalado.');
        $base = rtrim(trim((string)($_POST['base_url'] ?? '')),'/');
        $parts = parse_url($base);
        if (!$parts || !filter_var($base,FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '',['https','http'],true) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || strlen($base)>300) throw new InvalidArgumentException('Informe a URL final do sistema, sem index.php e sem parâmetros.');
        $name = validate_name($_POST['admin_name'] ?? '');
        $email = validate_email($_POST['admin_email'] ?? '');
        $password = validate_password($_POST['admin_password'] ?? '');
        if ($password !== ($_POST['confirm_password'] ?? '')) throw new InvalidArgumentException('As senhas do administrador não conferem.');
        $host = trim((string)($_POST['db_host'] ?? 'localhost'));
        $dbName = trim((string)($_POST['db_name'] ?? ''));
        $dbUser = trim((string)($_POST['db_user'] ?? ''));
        $dbPassword = (string)($_POST['db_password'] ?? '');
        $port = filter_var($_POST['db_port'] ?? 3306,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        if (!preg_match('/^[a-zA-Z0-9.:-]{1,253}$/D',$host) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$dbName) || $dbUser === '' || strlen($dbUser)>128 || !$port) throw new InvalidArgumentException('Confira o host, a porta, o nome e o usuário do banco de dados.');
        $pdo = new PDO('mysql:host='.$host.';port='.$port.';dbname='.$dbName.';charset=utf8mb4',$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8]);
        $pdo->exec("SET time_zone = '+00:00'");
        $schema = file_get_contents(__DIR__.'/app/schema.sql');
        foreach (explode(';',(string)$schema) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
        if ((int)$pdo->query('SELECT COUNT(*) FROM rq_users')->fetchColumn() !== 0) throw new InvalidArgumentException('Este banco já tem usuários do RotaQR. Restaure o config.php original ou use um banco vazio; a instalação não sobrescreve contas.');
        $pdo->beginTransaction();
        $q = $pdo->prepare("INSERT INTO rq_users (name,email,password_hash,role,active,must_change_password) VALUES (?,?,?,'admin',1,0)");
        $q->execute([$name,$email,hash_password($password)]);
        $config = ['base_url'=>$base,'secret'=>bin2hex(random_bytes(32)),'database'=>['host'=>$host,'port'=>$port,'name'=>$dbName,'user'=>$dbUser,'password'=>$dbPassword]];
        $content = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config,true) . ";\n";
        $file = fopen(__DIR__.'/app/config.php','x');
        if (!$file) throw new RuntimeException('Não foi possível criar a configuração.');
        $configWritten = true;
        $written = fwrite($file,$content); fclose($file);
        if ($written !== strlen($content)) throw new RuntimeException('Não foi possível salvar a configuração.');
        @chmod(__DIR__.'/app/config.php',0600);
        $pdo->commit();
        file_put_contents(__DIR__.'/storage/installed.lock',gmdate('c'),LOCK_EX);
        $_SESSION = ['csrf'=>bin2hex(random_bytes(32))];
        session_regenerate_id(true);
        $success = true;
    } catch (InvalidArgumentException $exception) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        if ($configWritten) @unlink(__DIR__.'/app/config.php');
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        if ($configWritten) @unlink(__DIR__.'/app/config.php');
        error_log('RotaQR installation: '.get_class($exception).' code='.$exception->getCode());
        $error = 'Não foi possível instalar. Confira as credenciais e permissões do banco e a gravação nas pastas app e storage. Nenhuma conta existente será substituída.';
    } finally {
        if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); }
    }
}
$scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$suggested = $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com.br').rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')),'/');
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalar · RotaQR</title><link rel="icon" href="assets/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/style.css"></head><body class="setup-page"><main class="setup-shell"><a class="brand dark-text" href="index.php"><img src="assets/favicon.svg" width="38" height="38" alt=""><span>Rota<span class="brand-light">QR</span></span></a>
<?php if ($success): ?><section class="setup-card"><p class="eyebrow">TUDO PRONTO</p><h1>Seu RotaQR está instalado.</h1><p>Entre com o e-mail e a senha de administrador que você acabou de definir.</p><a class="button primary" href="<?=e($base)?>/index.php">Abrir meu painel</a><p class="helper">O instalador foi bloqueado automaticamente. Você já pode excluir install.php e app/installer-key.php da hospedagem.</p></section>
<?php else: ?><header class="setup-heading"><p class="eyebrow">CONFIGURAÇÃO INICIAL</p><h1>Vamos colocar seus links no ar.</h1><p>Crie o banco no painel da hospedagem e preencha os dados abaixo.</p></header><div class="requirements"><?php foreach($checks as $label=>$ok): ?><span class="<?= $ok?'check-ok':'check-fail' ?>"><?= $ok?'✓':'×' ?> <?=e($label)?></span><?php endforeach; ?></div>
<?php if ($error): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
<form method="post" class="setup-card setup-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><fieldset><legend><span>01</span> Seu sistema</legend><label>Chave de instalação<input name="install_key" type="password" required autocomplete="off" placeholder="Está no arquivo ACESSO-INSTALACAO.txt"></label><label>URL definitiva do sistema<input name="base_url" type="url" required value="<?=e($input['base_url'] ?? $suggested)?>" placeholder="https://qr.seudominio.com.br"><span class="helper">Use HTTPS. Se instalar em uma pasta, inclua-a na URL. Este endereço fará parte dos seus QR Codes.</span></label></fieldset><fieldset><legend><span>02</span> Banco MySQL / MariaDB</legend><div class="form-grid"><label>Host<input name="db_host" required value="<?=e($input['db_host'] ?? 'localhost')?>"></label><label>Porta<input name="db_port" type="number" min="1" max="65535" required value="<?=e($input['db_port'] ?? '3306')?>"></label></div><label>Nome do banco<input name="db_name" required maxlength="64" value="<?=e($input['db_name'] ?? '')?>" autocomplete="off"></label><div class="form-grid"><label>Usuário do banco<input name="db_user" required value="<?=e($input['db_user'] ?? '')?>" autocomplete="off"></label><label>Senha do banco<input name="db_password" type="password" autocomplete="new-password"></label></div></fieldset><fieldset><legend><span>03</span> Sua conta de administrador</legend><label>Nome<input name="admin_name" required maxlength="100" value="<?=e($input['admin_name'] ?? '')?>" autocomplete="name"></label><label>E-mail<input name="admin_email" type="email" required maxlength="190" value="<?=e($input['admin_email'] ?? '')?>" autocomplete="email"></label><div class="form-grid"><label>Senha<input name="admin_password" type="password" required minlength="10" maxlength="72" autocomplete="new-password"></label><label>Confirme a senha<input name="confirm_password" type="password" required minlength="10" maxlength="72" autocomplete="new-password"></label></div><p class="helper">Use no mínimo 10 caracteres. Nenhuma senha padrão vem no sistema.</p></fieldset><button class="button primary full" type="submit" <?=in_array(false,$checks,true)?'disabled':''?>>Instalar e criar administrador</button></form><?php endif; ?></main></body></html>
