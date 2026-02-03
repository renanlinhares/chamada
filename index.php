<?php
/**
 * Sistema de Chamada Pública - Município de Ermo/SC
 * Versão 3.0 - 2026
 * Sistema monolítico PHP com SQLite
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('upload_max_filesize', '12M');
ini_set('post_max_size', '100M');
ini_set('max_file_uploads', '20');

define('DB_PATH', __DIR__ . '/chamada_publica.db');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('DOCS_DIR', __DIR__ . '/documentos/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);

if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!file_exists(DOCS_DIR)) mkdir(DOCS_DIR, 0755, true);

// ==================== DATABASE ====================

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        initDatabase($db);
    }
    return $db;
}

function initDatabase(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS configuracoes (
            id INTEGER PRIMARY KEY, chave TEXT UNIQUE NOT NULL, valor TEXT, atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS administradores (
            id INTEGER PRIMARY KEY AUTOINCREMENT, usuario TEXT UNIQUE NOT NULL, senha TEXT NOT NULL, nome TEXT NOT NULL, ativo INTEGER DEFAULT 1, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS cargos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, carga_horaria INTEGER NOT NULL, vagas INTEGER NOT NULL, vencimento REAL NOT NULL, habilitacao TEXT NOT NULL, tipo TEXT DEFAULT 'auxiliar', ativo INTEGER DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS criterios_pontuacao (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_cargo TEXT NOT NULL, criterio TEXT NOT NULL, descricao TEXT, pontos_por_unidade REAL DEFAULT 0, pontuacao_maxima REAL DEFAULT 0, unidade TEXT, ordem INTEGER DEFAULT 0, ativo INTEGER DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS candidatos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, protocolo TEXT UNIQUE NOT NULL, nome TEXT NOT NULL, cpf TEXT NOT NULL, rg TEXT NOT NULL, data_nascimento DATE NOT NULL, estado_civil TEXT NOT NULL, telefone TEXT NOT NULL, email TEXT NOT NULL,
            logradouro TEXT, numero TEXT, bairro TEXT, cidade TEXT, estado TEXT DEFAULT 'SC', cep TEXT,
            cargo_id INTEGER NOT NULL, titulacao TEXT DEFAULT 'nenhuma', tempo_servico_meses INTEGER DEFAULT 0, carga_horaria_cursos INTEGER DEFAULT 0,
            declaracao_parentesco INTEGER DEFAULT 0, declaracao_nao_participou INTEGER DEFAULT 0,
            status TEXT DEFAULT 'pendente', pontuacao REAL DEFAULT 0, classificacao INTEGER DEFAULT 0, observacoes TEXT,
            inscrito_em DATETIME DEFAULT CURRENT_TIMESTAMP, ip_inscricao TEXT,
            homologado_em DATETIME, homologado_por INTEGER,
            FOREIGN KEY (cargo_id) REFERENCES cargos(id)
        );
        CREATE TABLE IF NOT EXISTS tempo_servico_periodos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, candidato_id INTEGER NOT NULL, data_inicio DATE NOT NULL, data_fim DATE NOT NULL, local_trabalho TEXT NOT NULL, funcao TEXT NOT NULL, meses INTEGER DEFAULT 0, FOREIGN KEY (candidato_id) REFERENCES candidatos(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS documentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, candidato_id INTEGER NOT NULL, tipo TEXT NOT NULL, nome_original TEXT NOT NULL, nome_arquivo TEXT NOT NULL, tamanho INTEGER, enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (candidato_id) REFERENCES candidatos(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS recursos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, candidato_id INTEGER NOT NULL, protocolo_recurso TEXT UNIQUE, tipo TEXT NOT NULL, fundamentacao TEXT NOT NULL, status TEXT DEFAULT 'pendente', resposta TEXT, interposto_em DATETIME DEFAULT CURRENT_TIMESTAMP, analisado_em DATETIME, analisado_por INTEGER, FOREIGN KEY (candidato_id) REFERENCES candidatos(id)
        );
        CREATE TABLE IF NOT EXISTS documentos_recurso (
            id INTEGER PRIMARY KEY AUTOINCREMENT, recurso_id INTEGER NOT NULL, nome_original TEXT NOT NULL, nome_arquivo TEXT NOT NULL, tamanho INTEGER, enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (recurso_id) REFERENCES recursos(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS publicacoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL, titulo TEXT NOT NULL, descricao TEXT, arquivo TEXT, conteudo TEXT, publicado INTEGER DEFAULT 0, publicado_em DATETIME, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP, criado_por INTEGER
        );
        CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER, acao TEXT NOT NULL, detalhes TEXT, ip TEXT, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
    
    // Migração: adicionar ip_inscricao se não existir
    try { $db->exec("ALTER TABLE candidatos ADD COLUMN ip_inscricao TEXT"); } catch (Exception $e) {}
    
    $stmt = $db->query("SELECT COUNT(*) FROM administradores");
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $db->exec("INSERT INTO administradores (usuario, senha, nome) VALUES ('admin', '$hash', 'Administrador')");
    }
    
    $configs = [
        'titulo_chamada' => 'CHAMADA PÚBLICA Nº 1/2026', 'subtitulo' => 'Edital de Abertura',
        'municipio' => 'Município de Ermo/SC', 'ano' => '2026', 'data_publicacao' => '2026-02-02',
        'inscricoes_inicio' => '2026-02-02 12:00:00', 'inscricoes_fim' => '2026-02-09 12:00:00',
        'resultado_preliminar' => '2026-02-10', 'recursos_inicio' => '2026-02-11 00:00:00',
        'recursos_fim' => '2026-02-11 12:30:00', 'resultado_final' => '2026-02-12',
        'contato_email' => 'rh.pmermo@gmail.com', 'contato_telefone' => '(48) 3198-1497',
        'contato_whatsapp' => '(48) 99185-8431', 'endereco' => 'Rodovia SC 448, Km 6, n. 120, Centro, Ermo/SC',
        'site' => 'https://ermo.sc.gov.br',
        'exibir_pontuacao_publica' => '0', 'exibir_classificacao_publica' => '0',
        'texto_declaracao_parentesco' => 'Declaro, sob as penas da lei, que não possuo parentesco vedado pela Lei Orgânica Municipal.',
        'texto_declaracao_nao_participou' => 'Declaro que não participei da Chamada Pública nº 4/2025.',
        'chamada_anterior' => 'Chamada Pública nº 4/2025',
        'permitir_multiplos_cargos' => '0'
    ];
    $stmt = $db->prepare("INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)");
    foreach ($configs as $k => $v) $stmt->execute([$k, $v]);
    
    $stmt = $db->query("SELECT COUNT(*) FROM cargos");
    if ($stmt->fetchColumn() == 0) {
        $cargos = [
            ['Auxiliar de Ensino da Educação', 30, 5, 2405.50, 'Nível médio no curso de magistério ou ensino médio completo', 'auxiliar'],
            ['Auxiliar de Ensino da Educação', 40, 4, 2405.50, 'Nível médio no curso de magistério ou ensino médio completo', 'auxiliar'],
            ['Professor', 40, 4, 2405.50, 'Graduação em Pedagogia', 'professor']
        ];
        $s = $db->prepare("INSERT INTO cargos (nome, carga_horaria, vagas, vencimento, habilitacao, tipo) VALUES (?,?,?,?,?,?)");
        foreach ($cargos as $c) $s->execute($c);
    }
    
    // Recriar critérios com valores definitivos do edital
    $db->exec("DELETE FROM criterios_pontuacao");
    $criterios = [
        ['professor','titulacao_doutorado','Doutorado na área da Educação ou área afim',45,45,'titulo',1],
        ['professor','titulacao_mestrado','Mestrado na área da Educação ou área afim',30,30,'titulo',2],
        ['professor','titulacao_especializacao','Pós-graduação lato sensu na área da Educação ou área afim',20,20,'titulo',3],
        ['professor','tempo_servico','Tempo de serviço na área de atuação correspondente ao cargo',1,35,'ano',4],
        ['professor','cursos','Cursos de formação ou aperfeiçoamento na área da Educação',0.05,20,'hora',5],
        ['auxiliar','titulacao_pedagogia_pos','Pedagogia concluída com Pós-graduação na área da Educação',45,45,'titulo',1],
        ['auxiliar','titulacao_pedagogia','Pedagogia concluída',30,30,'titulo',2],
        ['auxiliar','titulacao_cursando','Estar cursando graduação em Pedagogia, com matrícula ativa',25,25,'titulo',3],
        ['auxiliar','tempo_servico','Tempo de serviço na área educacional',1,35,'ano',4],
        ['auxiliar','cursos','Cursos de formação ou aperfeiçoamento na área da Educação',0.05,20,'hora',5],
    ];
    $s = $db->prepare("INSERT INTO criterios_pontuacao (tipo_cargo,criterio,descricao,pontos_por_unidade,pontuacao_maxima,unidade,ordem) VALUES (?,?,?,?,?,?,?)");
    foreach ($criterios as $c) $s->execute($c);
}

// ==================== HELPERS ====================

function getConfig(string $k): ?string { $s = getDB()->prepare("SELECT valor FROM configuracoes WHERE chave=?"); $s->execute([$k]); $r=$s->fetch(); return $r?$r['valor']:null; }
function setConfig(string $k, string $v): bool { return getDB()->prepare("INSERT OR REPLACE INTO configuracoes (chave,valor,atualizado_em) VALUES (?,?,datetime('now'))")->execute([$k,$v]); }
function generateCSRFToken(): string { if(empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function validateCSRFToken(string $t): bool { return isset($_SESSION['csrf_token'])&&hash_equals($_SESSION['csrf_token'],$t); }
function sanitize(string $s): string { return htmlspecialchars(trim($s),ENT_QUOTES,'UTF-8'); }
function sanitizeCPF(string $c): string { return preg_replace('/[^0-9]/','',$c); }

function validarCPF(string $cpf): bool {
    $cpf=sanitizeCPF($cpf);
    if(strlen($cpf)!=11||preg_match('/^(\d)\1*$/',$cpf)) return false;
    for($t=9;$t<11;$t++){for($d=0,$c=0;$c<$t;$c++)$d+=$cpf[$c]*(($t+1)-$c);$d=((10*$d)%11)%10;if($cpf[$c]!=$d)return false;}
    return true;
}

function formatarCPF(string $c): string { $c=sanitizeCPF($c); return substr($c,0,3).'.'.substr($c,3,3).'.'.substr($c,6,3).'-'.substr($c,9,2); }

function gerarProtocolo(): string {
    $a=date('Y'); $s=getDB()->query("SELECT MAX(CAST(SUBSTR(protocolo,5,6) AS INTEGER)) as u FROM candidatos WHERE protocolo LIKE 'CP{$a}%'")->fetch();
    return sprintf("CP%s%06d",$a,($s['u']??0)+1);
}
function gerarProtocoloRecurso(): string {
    $a=date('Y'); $s=getDB()->query("SELECT MAX(CAST(SUBSTR(protocolo_recurso,5,6) AS INTEGER)) as u FROM recursos WHERE protocolo_recurso LIKE 'RC{$a}%'")->fetch();
    return sprintf("RC%s%06d",$a,($s['u']??0)+1);
}

function isAdmin(): bool { return isset($_SESSION['admin_id'])&&$_SESSION['admin_id']>0; }
function requireAdmin(): void { if(!isAdmin()){header('Location: ?page=admin_login');exit;} }

function logAction(string $a, string $d=''): void {
    getDB()->prepare("INSERT INTO logs (usuario_id,acao,detalhes,ip) VALUES (?,?,?,?)")->execute([$_SESSION['admin_id']??null,$a,$d,$_SERVER['REMOTE_ADDR']??'']);
}

function getClientIP(): string {
    foreach(['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'] as $k){
        if(!empty($_SERVER[$k])){$ips=explode(',',$_SERVER[$k]);return trim($ips[0]);}
    }
    return 'unknown';
}

function periodoInscricoesAberto(): bool { $i=strtotime(getConfig('inscricoes_inicio'));$f=strtotime(getConfig('inscricoes_fim'));return time()>=$i&&time()<=$f; }
function periodoRecursosAberto(): bool { $i=strtotime(getConfig('recursos_inicio'));$f=strtotime(getConfig('recursos_fim'));return time()>=$i&&time()<=$f; }

function getStatusPeriodo(): array {
    $n=time(); $ii=strtotime(getConfig('inscricoes_inicio')); $if=strtotime(getConfig('inscricoes_fim'));
    $ri=strtotime(getConfig('recursos_inicio')); $rf=strtotime(getConfig('recursos_fim'));
    if($n<$ii) return ['status'=>'aguardando','mensagem'=>'Inscrições iniciam em '.date('d/m/Y H:i',$ii)];
    if($n<=$if) return ['status'=>'inscricoes','mensagem'=>'Inscrições abertas até '.date('d/m/Y H:i',$if)];
    if($n<$ri) return ['status'=>'analise','mensagem'=>'Período de análise das inscrições'];
    if($n<=$rf) return ['status'=>'recursos','mensagem'=>'Período de recursos até '.date('d/m/Y H:i',$rf)];
    return ['status'=>'encerrado','mensagem'=>'Chamada Pública encerrada'];
}

function calcularPontuacao(array $cand): float {
    $db=getDB();
    $s=$db->prepare("SELECT tipo FROM cargos WHERE id=?");$s->execute([$cand['cargo_id']]);$cargo=$s->fetch();
    $tipo=$cargo['tipo']??'auxiliar';
    $pts=0.0;
    
    // === TITULAÇÃO / FORMAÇÃO (máx 45 pts) ===
    // Considera apenas a maior titulação, sem cumulação
    $tit = $cand['titulacao'] ?? 'nenhuma';
    if ($tipo === 'professor') {
        // Professor: Doutorado=45, Mestrado=30, Pós-graduação lato sensu=20
        $pts += match($tit) { 'doutorado'=>45.0, 'mestrado'=>30.0, 'especializacao'=>20.0, default=>0.0 };
    } else {
        // Auxiliar: Pedagogia+Pós=45, Pedagogia concluída=30, Cursando Pedagogia=25
        $pts += match($tit) { 'pedagogia_pos'=>45.0, 'pedagogia'=>30.0, 'cursando_pedagogia'=>25.0, default=>0.0 };
    }
    
    // === TEMPO DE SERVIÇO (máx 35 pts) ===
    // 1 ponto por cada 12 meses — proporcional (ex: 14 meses = 14/12 = 1,16 pts)
    // "O tempo de serviço será apurado em anos e meses, sendo desconsiderados os dias."
    $meses = (int)($cand['tempo_servico_meses'] ?? 0);
    $pts += min(35.0, round($meses / 12, 2));
    
    // === CURSOS DE FORMAÇÃO / APERFEIÇOAMENTO (máx 20 pts) ===
    // 0,05 ponto por hora de curso, limite máximo de 400 horas
    $ch = min(400, (int)($cand['carga_horaria_cursos'] ?? 0));
    $pts += min(20.0, $ch * 0.05);
    
    return $pts;
}

function classificarCandidatos(): void {
    $db=getDB();
    // Recalcular pontuação de TODOS os candidatos homologados
    $todos=$db->query("SELECT id,cargo_id,titulacao,tempo_servico_meses,carga_horaria_cursos FROM candidatos WHERE status='homologado'")->fetchAll();
    $u=$db->prepare("UPDATE candidatos SET pontuacao=? WHERE id=?");
    foreach($todos as $c){
        $pontos=calcularPontuacao($c);
        $u->execute([$pontos,$c['id']]);
    }
    // Classificar por cargo: pontuação DESC, data_nascimento ASC (mais velho = data menor = primeiro)
    foreach($db->query("SELECT DISTINCT cargo_id FROM candidatos WHERE status='homologado'")->fetchAll() as $row){
        $s=$db->prepare("SELECT id FROM candidatos WHERE cargo_id=? AND status='homologado' ORDER BY pontuacao DESC, data_nascimento ASC");
        $s->execute([$row['cargo_id']]); $cl=1;
        $up=$db->prepare("UPDATE candidatos SET classificacao=? WHERE id=?");
        foreach($s->fetchAll() as $c)$up->execute([$cl++,$c['id']]);
    }
}

function getDocCategories(): array {
    return [
        'doc_identidade'=>['nome'=>'Documento de Identificação com Foto','icone'=>'🪪','obrigatorio'=>true],
        'doc_cpf'=>['nome'=>'CPF','icone'=>'📋','obrigatorio'=>true],
        'doc_residencia'=>['nome'=>'Comprovante de Residência','icone'=>'🏠','obrigatorio'=>true],
        'doc_escolaridade'=>['nome'=>'Comprovante de Escolaridade / Formação Exigida para o Cargo','icone'=>'🎓','obrigatorio'=>true],
        'doc_titulacao'=>['nome'=>'Comprovante de Titulação Acadêmica (para pontuação)','icone'=>'📜','obrigatorio'=>false],
        'doc_tempo_servico'=>['nome'=>'Comprovante de Tempo de Serviço','icone'=>'💼','obrigatorio'=>false],
        'doc_equivalencia'=>['nome'=>'Documento oficial comprovando equivalência de cargo/função (ex: lei municipal)','icone'=>'📑','obrigatorio'=>false],
        'doc_cursos'=>['nome'=>'Certificados de Cursos de Formação/Aperfeiçoamento na Área da Educação','icone'=>'📚','obrigatorio'=>false],
        'doc_outros'=>['nome'=>'Outros Documentos','icone'=>'📎','obrigatorio'=>false],
    ];
}

function getTitulacaoLabel(string $titulacao, string $tipoCargo = ''): string {
    $labels = [
        // Professor
        'doutorado' => 'Doutorado na área da Educação ou área afim',
        'mestrado' => 'Mestrado na área da Educação ou área afim',
        'especializacao' => 'Pós-graduação lato sensu na área da Educação ou área afim',
        // Auxiliar
        'pedagogia_pos' => 'Pedagogia concluída com Pós-graduação na área da Educação',
        'pedagogia' => 'Pedagogia concluída',
        'cursando_pedagogia' => 'Cursando graduação em Pedagogia (matrícula ativa)',
        // Genérico
        'nenhuma' => 'Nenhuma / Não se aplica',
    ];
    return $labels[$titulacao] ?? ucfirst(str_replace('_', ' ', $titulacao));
}

function statusBadgeClass(string $status): string {
    return match($status) {
        'homologado' => 'bg-green-100 text-green-800',
        'indeferido' => 'bg-red-100 text-red-800',
        'cancelado' => 'bg-gray-200 text-gray-600',
        default => 'bg-yellow-100 text-yellow-800',
    };
}

// ==================== API VERIFICAR CPF ====================

if (isset($_GET['api']) && $_GET['api'] === 'verificar_cpf') {
    header('Content-Type: application/json');
    $cpf = sanitizeCPF($_GET['cpf'] ?? '');
    $cargoId = (int)($_GET['cargo_id'] ?? 0);
    if (!validarCPF($cpf)) { echo json_encode(['valido'=>false,'mensagem'=>'CPF inválido']); exit; }
    
    $db = getDB();
    // Buscar apenas inscrições ATIVAS (não canceladas)
    $stmt = $db->prepare("SELECT ca.id,ca.protocolo,ca.cargo_id,ca.status,cg.nome as cargo_nome,cg.carga_horaria FROM candidatos ca JOIN cargos cg ON ca.cargo_id=cg.id WHERE ca.cpf=? AND ca.status<>'cancelado' ORDER BY ca.inscrito_em DESC");
    $stmt->execute([$cpf]);
    $existentes = $stmt->fetchAll();
    
    if (empty($existentes)) { echo json_encode(['valido'=>true,'mensagem'=>'CPF disponível para inscrição']); exit; }
    
    // Listar inscrições ativas
    $lista = implode(', ', array_map(fn($e) => "{$e['cargo_nome']} ({$e['carga_horaria']}h) [{$e['protocolo']}]", $existentes));
    echo json_encode([
        'valido' => true,
        'substituir' => true,
        'mensagem' => "CPF já possui inscrição ativa: $lista. Ao prosseguir, TODAS as inscrições anteriores serão canceladas e substituídas pela nova."
    ]);
    exit;
}

// ==================== PROCESSAMENTO ====================

function processarInscricao(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return ['success'=>false,'message'=>'Método inválido'];
    
    // Detectar POST excedido (PHP zera $_POST e $_FILES quando post_max_size é ultrapassado)
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $maxPost = ini_get('post_max_size');
        return ['success'=>false,'message'=>"O tamanho total dos arquivos excedeu o limite do servidor ($maxPost). Reduza o tamanho dos arquivos e tente novamente."];
    }
    
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) return ['success'=>false,'message'=>'Token de segurança inválido. Recarregue a página e tente novamente.'];
    if (!periodoInscricoesAberto()) return ['success'=>false,'message'=>'Período de inscrições encerrado'];
    
    $db = getDB();
    $nome = sanitize($_POST['nome'] ?? '');
    $cpf = sanitizeCPF($_POST['cpf'] ?? '');
    $rg = sanitize($_POST['rg'] ?? '');
    $dataNasc = $_POST['data_nascimento'] ?? '';
    $estadoCivil = sanitize($_POST['estado_civil'] ?? '');
    $telefone = sanitize($_POST['telefone'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $logradouro = sanitize($_POST['logradouro'] ?? '');
    $numero = sanitize($_POST['numero'] ?? '');
    $bairro = sanitize($_POST['bairro'] ?? '');
    $cidade = sanitize($_POST['cidade'] ?? '');
    $estado = sanitize($_POST['estado'] ?? 'SC');
    $cep = sanitize($_POST['cep'] ?? '');
    $cargoId = (int)($_POST['cargo_id'] ?? 0);
    $titulacao = sanitize($_POST['titulacao'] ?? 'nenhuma');
    $chCursos = (int)($_POST['carga_horaria_cursos'] ?? 0);
    $declParent = isset($_POST['declaracao_parentesco']) ? 1 : 0;
    $declNaoPart = isset($_POST['declaracao_nao_participou']) ? 1 : 0;
    $confirmarSub = ($_POST['confirmar_substituicao'] ?? '') === '1';
    
    if (empty($nome)||strlen($nome)<3) return ['success'=>false,'message'=>'Nome inválido'];
    if (!validarCPF($cpf)) return ['success'=>false,'message'=>'CPF inválido'];
    if (empty($rg)) return ['success'=>false,'message'=>'RG é obrigatório'];
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) return ['success'=>false,'message'=>'E-mail inválido'];
    if ($cargoId<=0) return ['success'=>false,'message'=>'Selecione um cargo'];
    if (!$declParent) return ['success'=>false,'message'=>'É obrigatório marcar a declaração de parentesco'];
    if (!$declNaoPart) return ['success'=>false,'message'=>'É obrigatório marcar a declaração de não participação'];
    
    // Tratar CPF existente ANTES de inserir
    // Buscar apenas inscrições ATIVAS (ignorar canceladas)
    $stmt = $db->prepare("SELECT id, cargo_id, protocolo FROM candidatos WHERE cpf=? AND status<>'cancelado'");
    $stmt->execute([$cpf]);
    $existentes = $stmt->fetchAll();
    $idsCancelar = [];
    
    if (!empty($existentes)) {
        if (!$confirmarSub) {
            return ['success'=>false,'message'=>'Já existe uma inscrição ativa para este CPF. Marque a opção "Confirmo que desejo substituir minha inscrição anterior" que aparece abaixo do campo CPF e envie novamente.'];
        }
        // Marcar TODAS as inscrições anteriores para cancelamento (independente do cargo)
        foreach ($existentes as $e) {
            $idsCancelar[] = $e['id'];
        }
    }
    
    // Validar arquivos obrigatórios
    $obrig = ['doc_identidade'=>'Documento de Identificação','doc_cpf'=>'CPF','doc_residencia'=>'Comprovante de Residência','doc_escolaridade'=>'Comprovante de Escolaridade'];
    foreach ($obrig as $campo => $label) {
        if (empty($_FILES[$campo]['name']) || (is_array($_FILES[$campo]['name']) && empty(array_filter($_FILES[$campo]['name'])))) {
            return ['success'=>false,'message'=>"Envie o documento obrigatório: $label"];
        }
    }
    
    try {
        $db->beginTransaction();
        
        // Cancelar inscrições anteriores (manter no banco para auditoria)
        if (!empty($idsCancelar)) {
            $ph = implode(',', array_fill(0, count($idsCancelar), '?'));
            $dataCancel = date('d/m/Y H:i:s');
            $db->prepare("UPDATE candidatos SET status='cancelado', classificacao=0, observacoes=COALESCE(observacoes,'') || '\n[Cancelado em $dataCancel - Substituído por nova inscrição]' WHERE id IN ($ph)")->execute($idsCancelar);
        }
        
        $protocolo = gerarProtocolo();
        $ip = getClientIP();
        
        // Calcular tempo de serviço total
        $tsTotal = 0;
        if (!empty($_POST['tempo_inicio']) && is_array($_POST['tempo_inicio'])) {
            foreach ($_POST['tempo_inicio'] as $i => $ini) {
                if (!empty($ini) && !empty($_POST['tempo_fim'][$i])) {
                    $d = (new DateTime($ini))->diff(new DateTime($_POST['tempo_fim'][$i]));
                    $tsTotal += ($d->y*12)+$d->m;
                }
            }
        }
        
        $stmt = $db->prepare("INSERT INTO candidatos (protocolo,nome,cpf,rg,data_nascimento,estado_civil,telefone,email,logradouro,numero,bairro,cidade,estado,cep,cargo_id,titulacao,tempo_servico_meses,carga_horaria_cursos,declaracao_parentesco,declaracao_nao_participou,ip_inscricao) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$protocolo,$nome,$cpf,$rg,$dataNasc,$estadoCivil,$telefone,$email,$logradouro,$numero,$bairro,$cidade,$estado,$cep,$cargoId,$titulacao,$tsTotal,$chCursos,$declParent,$declNaoPart,$ip]);
        $candId = $db->lastInsertId();
        
        // Salvar períodos
        if (!empty($_POST['tempo_inicio']) && is_array($_POST['tempo_inicio'])) {
            $s = $db->prepare("INSERT INTO tempo_servico_periodos (candidato_id,data_inicio,data_fim,local_trabalho,funcao,meses) VALUES (?,?,?,?,?,?)");
            foreach ($_POST['tempo_inicio'] as $i => $ini) {
                if (!empty($ini) && !empty($_POST['tempo_fim'][$i])) {
                    $local = sanitize($_POST['tempo_local'][$i] ?? '');
                    $func = sanitize($_POST['tempo_funcao'][$i] ?? '');
                    $d = (new DateTime($ini))->diff(new DateTime($_POST['tempo_fim'][$i]));
                    $s->execute([$candId,$ini,$_POST['tempo_fim'][$i],$local,$func,($d->y*12)+$d->m]);
                }
            }
        }
        
        // Pontuação
        $pontos = calcularPontuacao(['cargo_id'=>$cargoId,'titulacao'=>$titulacao,'tempo_servico_meses'=>$tsTotal,'carga_horaria_cursos'=>$chCursos]);
        $db->prepare("UPDATE candidatos SET pontuacao=? WHERE id=?")->execute([$pontos,$candId]);
        
        // Processar uploads
        $tipos = ['doc_identidade','doc_cpf','doc_residencia','doc_escolaridade','doc_titulacao','doc_tempo_servico','doc_equivalencia','doc_cursos','doc_outros'];
        foreach ($tipos as $campo) {
            if (!empty($_FILES[$campo]['name'])) {
                if (is_array($_FILES[$campo]['name'])) {
                    foreach ($_FILES[$campo]['name'] as $idx => $fn) {
                        if (!empty($fn)) {
                            $arq = ['name'=>$fn,'tmp_name'=>$_FILES[$campo]['tmp_name'][$idx],'error'=>$_FILES[$campo]['error'][$idx],'size'=>$_FILES[$campo]['size'][$idx]];
                            $r = processarUpload($arq,$candId,$campo);
                            if (!$r['success']) throw new Exception($r['message']);
                        }
                    }
                } else {
                    $r = processarUpload($_FILES[$campo],$candId,$campo);
                    if (!$r['success']) throw new Exception($r['message']);
                }
            }
        }
        
        $db->commit();
        $sub = !empty($idsCancelar) ? " (cancelou inscrições anteriores: ".implode(',', $idsCancelar).")" : "";
        logAction('inscricao', "Protocolo: $protocolo, IP: $ip$sub");
        // Buscar dados do cargo para comprovante
        $stCargo = $db->prepare("SELECT nome,carga_horaria FROM cargos WHERE id=?"); $stCargo->execute([$cargoId]); $cargoInfo = $stCargo->fetch();
        return ['success'=>true,'message'=>'Inscrição realizada!','protocolo'=>$protocolo,'candidato_id'=>$candId,'nome'=>$nome,'cargo_nome'=>$cargoInfo['nome']??'','cargo_ch'=>$cargoInfo['carga_horaria']??'','data_inscricao'=>date('d/m/Y H:i:s')];
    } catch (Exception $e) {
        $db->rollBack();
        $msg = $e->getMessage();
        if (strpos($msg, 'UNIQUE constraint') !== false) {
            return ['success'=>false,'message'=>'Erro de duplicidade no banco de dados. Tente novamente em alguns segundos.'];
        }
        // Mostrar erro real para diagnóstico
        return ['success'=>false,'message'=>'Erro ao processar inscrição: ' . $msg];
    }
}

function processarUpload(array $arq, int $candId, string $tipo): array {
    if ($arq['error'] !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande. O servidor aceita no máximo ' . ini_get('upload_max_filesize') . 'B por arquivo.',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande para o formulário.',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo selecionado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Erro de configuração do servidor (tmp).',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao gravar arquivo no servidor.',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão do servidor.',
        ];
        $nomeDoc = str_replace(['doc_','_'], ['','. '], $tipo);
        return ['success'=>false,'message'=>"Erro no upload do documento \"$nomeDoc\": " . ($msgs[$arq['error']] ?? "Erro desconhecido (código {$arq['error']})")];
    }
    if ($arq['size'] > MAX_FILE_SIZE) return ['success'=>false,'message'=>'Arquivo muito grande (máx 10MB)'];
    $ext = strtolower(pathinfo($arq['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) return ['success'=>false,'message'=>'Tipo de arquivo não permitido'];
    $nome = sprintf('%d_%s_%s.%s', $candId, $tipo, uniqid(), $ext);
    if (!move_uploaded_file($arq['tmp_name'], UPLOAD_DIR.$nome)) return ['success'=>false,'message'=>'Erro ao salvar'];
    getDB()->prepare("INSERT INTO documentos (candidato_id,tipo,nome_original,nome_arquivo,tamanho) VALUES (?,?,?,?,?)")->execute([$candId,$tipo,$arq['name'],$nome,$arq['size']]);
    return ['success'=>true,'arquivo'=>$nome];
}

function processarRecurso(): array {
    if ($_SERVER['REQUEST_METHOD']!=='POST') return ['success'=>false,'message'=>'Método inválido'];
    
    // Detectar POST excedido
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $maxPost = ini_get('post_max_size');
        return ['success'=>false,'message'=>"O tamanho total dos arquivos excedeu o limite do servidor ($maxPost). Reduza o tamanho dos arquivos e tente novamente."];
    }
    
    if (!validateCSRFToken($_POST['csrf_token']??'')) return ['success'=>false,'message'=>'Token inválido'];
    if (!periodoRecursosAberto()) return ['success'=>false,'message'=>'Período de recursos encerrado'];
    $cpf=sanitizeCPF($_POST['cpf']??'');$prot=sanitize($_POST['protocolo']??'');$tipo=sanitize($_POST['tipo_recurso']??'');$fund=sanitize($_POST['fundamentacao']??'');
    if(strlen($fund)<20) return ['success'=>false,'message'=>'Fundamentação muito curta (mín. 20 caracteres)'];
    $db=getDB();$s=$db->prepare("SELECT id FROM candidatos WHERE cpf=? AND protocolo=?");$s->execute([$cpf,$prot]);$c=$s->fetch();
    if(!$c) return ['success'=>false,'message'=>'Inscrição não encontrada'];
    $s=$db->prepare("SELECT id FROM recursos WHERE candidato_id=? AND status='pendente'");$s->execute([$c['id']]);
    if($s->fetch()) return ['success'=>false,'message'=>'Já existe recurso pendente'];
    
    // Validar arquivos antes de inserir (se houver)
    $arquivosValidos = [];
    if (!empty($_FILES['docs_recurso']['name'])) {
        $nomes = is_array($_FILES['docs_recurso']['name']) ? $_FILES['docs_recurso']['name'] : [$_FILES['docs_recurso']['name']];
        $tmps = is_array($_FILES['docs_recurso']['tmp_name']) ? $_FILES['docs_recurso']['tmp_name'] : [$_FILES['docs_recurso']['tmp_name']];
        $errs = is_array($_FILES['docs_recurso']['error']) ? $_FILES['docs_recurso']['error'] : [$_FILES['docs_recurso']['error']];
        $sizes = is_array($_FILES['docs_recurso']['size']) ? $_FILES['docs_recurso']['size'] : [$_FILES['docs_recurso']['size']];
        $extPermitidas = ['pdf','jpg','jpeg','png','doc','docx'];
        
        for ($i = 0; $i < count($nomes); $i++) {
            if (empty($nomes[$i]) || $errs[$i] === UPLOAD_ERR_NO_FILE) continue;
            if ($errs[$i] !== UPLOAD_ERR_OK) return ['success'=>false,'message'=>'Erro no upload do documento: ' . $nomes[$i]];
            if ($sizes[$i] > MAX_FILE_SIZE) return ['success'=>false,'message'=>'Arquivo "' . $nomes[$i] . '" excede 10MB.'];
            $ext = strtolower(pathinfo($nomes[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $extPermitidas)) return ['success'=>false,'message'=>'Formato não permitido: .' . $ext . '. Use PDF, JPG, PNG, DOC ou DOCX.'];
            $arquivosValidos[] = ['nome'=>$nomes[$i],'tmp'=>$tmps[$i],'size'=>$sizes[$i],'ext'=>$ext];
        }
    }
    
    try {
        $db->beginTransaction();
        $pr=gerarProtocoloRecurso();
        $db->prepare("INSERT INTO recursos (candidato_id,protocolo_recurso,tipo,fundamentacao) VALUES (?,?,?,?)")->execute([$c['id'],$pr,$tipo,$fund]);
        $recursoId = $db->lastInsertId();
        
        // Salvar documentos do recurso
        foreach ($arquivosValidos as $arq) {
            $nomeArq = 'rec_' . $recursoId . '_' . time() . '_' . uniqid() . '.' . $arq['ext'];
            if (!move_uploaded_file($arq['tmp'], UPLOAD_DIR . $nomeArq)) {
                throw new Exception('Erro ao salvar arquivo: ' . $arq['nome']);
            }
            $db->prepare("INSERT INTO documentos_recurso (recurso_id,nome_original,nome_arquivo,tamanho) VALUES (?,?,?,?)")->execute([$recursoId, $arq['nome'], $nomeArq, $arq['size']]);
        }
        
        $db->commit();
        logAction('recurso',"Candidato ID: {$c['id']}, Protocolo: $pr, Docs: ".count($arquivosValidos));
        return ['success'=>true,'message'=>'Recurso interposto!','protocolo_recurso'=>$pr];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success'=>false,'message'=>'Erro ao processar recurso: ' . $e->getMessage()];
    }
}

function processarLogin(): array {
    if ($_SERVER['REQUEST_METHOD']!=='POST') return ['success'=>false,'message'=>'Método inválido'];
    if (!validateCSRFToken($_POST['csrf_token']??'')) return ['success'=>false,'message'=>'Token inválido'];
    $u=sanitize($_POST['usuario']??'');$p=$_POST['senha']??'';
    $db=getDB();$s=$db->prepare("SELECT id,senha,nome FROM administradores WHERE usuario=? AND ativo=1");$s->execute([$u]);$a=$s->fetch();
    if($a&&password_verify($p,$a['senha'])){$_SESSION['admin_id']=$a['id'];$_SESSION['admin_nome']=$a['nome'];logAction('login',"Usuário: $u");return ['success'=>true];}
    return ['success'=>false,'message'=>'Usuário ou senha inválidos'];
}

// ==================== RENDERIZAÇÃO ====================

function renderHead(string $titulo = ''): void {
    $t = $titulo ? "$titulo - " . getConfig('titulo_chamada') : getConfig('titulo_chamada');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($t) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: { extend: {
                fontFamily: { 'sans': ['Source Sans 3','sans-serif'], 'display': ['Playfair Display','serif'] },
                colors: { 'gov': {50:'#f0f5ff',100:'#e0ebff',200:'#c7d9ff',300:'#a3c0ff',400:'#799dff',500:'#5178fc',600:'#3654f0',700:'#2840db',800:'#2536b1',900:'#24338c',950:'#1a2055'} }
            }}
        }
    </script>
    <style>
        body { font-family: 'Source Sans 3', sans-serif; background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); min-height: 100vh; }
        .input-field { width: 100%; padding: 0.75rem 1rem; border: 1px solid #e5e7eb; border-radius: 0.5rem; background: rgba(255,255,255,0.9); transition: all 0.2s; }
        .input-field:focus { outline: none; border-color: #5178fc; box-shadow: 0 0 0 3px rgba(81,120,252,0.2); }
        .btn-primary { display:inline-block; padding: 0.75rem 1.5rem; background: #3654f0; color: white; font-weight: 600; border-radius: 0.5rem; transition: all 0.2s; cursor: pointer; border: none; text-decoration:none; text-align:center; }
        .btn-primary:hover { background: #2840db; }
        .btn-secondary { display:inline-block; padding: 0.75rem 1.5rem; background: white; color: #2840db; font-weight: 600; border-radius: 0.5rem; border: 2px solid #c7d9ff; transition: all 0.2s; cursor: pointer; text-decoration:none; text-align:center; }
        .btn-secondary:hover { border-color: #799dff; background: #f0f5ff; }
        .btn-success { display:inline-block; padding: 0.75rem 1.5rem; background: #059669; color: white; font-weight: 600; border-radius: 0.5rem; cursor: pointer; border: none; text-decoration:none; }
        .btn-success:hover { background: #047857; }
        .btn-danger { display:inline-block; padding: 0.5rem 1rem; background: #dc2626; color: white; font-weight: 600; border-radius: 0.5rem; cursor: pointer; border: none; font-size: 0.875rem; text-decoration:none; }
        .btn-danger:hover { background: #b91c1c; }
        .card { background: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #f3f4f6; }
        .status-badge { display: inline-flex; align-items: center; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 500; }
        .animate-fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; margin: 0; padding: 0; }
            .card { box-shadow: none !important; border: none !important; padding: 1rem !important; }
            main { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
            /* Comprovante: limitar a 1 página */
            #comprovante-inscricao { page-break-after: always; page-break-inside: avoid; }
            #comprovante-inscricao .hidden.print\\:block { display: block !important; }
            /* Esconder formulário e tudo que não é comprovante na impressão */
            #form-inscricao, #cpf-aviso, #confirmar-sub-container, h1, .card > p:first-of-type { display: none !important; }
            @page { size: A4; margin: 15mm 12mm; }
        }
        .file-item { display:flex; align-items:center; padding:0.5rem 0.75rem; background:#f3f4f6; border-radius:0.5rem; margin-top:0.5rem; font-size:0.875rem; }
        .file-item .file-name { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .file-item .file-remove { cursor:pointer; color:#dc2626; margin-left:0.75rem; padding:0.125rem 0.375rem; border-radius:0.25rem; }
        .file-item .file-remove:hover { background:#fee2e2; }
        .file-item .file-view { cursor:pointer; color:#2840db; margin-left:0.5rem; padding:0.125rem 0.375rem; border-radius:0.25rem; font-weight:600; }
        .file-item .file-view:hover { background:#e0ebff; }
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-content { background:white; border-radius:1rem; padding:2rem; max-width:480px; width:90%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); }
    </style>
</head>
<body class="antialiased">
<?php }

function renderHeader(bool $isAdmin = false): void {
    $titulo = getConfig('titulo_chamada'); $mun = getConfig('municipio');
?>
<header class="bg-gradient-to-r from-gov-900 to-gov-800 text-white shadow-2xl no-print">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between py-4">
            <a href="?" class="flex items-center space-x-4">
                <img src="brasao.png" alt="Brasão" class="w-14 h-14 object-contain bg-white rounded-full p-1 shadow-lg" onerror="this.style.display='none'">
                <div><h1 class="text-xl font-bold"><?= sanitize($mun) ?></h1><p class="text-gov-200 text-sm"><?= sanitize($titulo) ?></p></div>
            </a>
            <nav class="hidden md:flex items-center space-x-1">
                <?php if ($isAdmin): ?>
                    <a href="?page=admin" class="px-4 py-2 rounded-lg hover:bg-white/10">Dashboard</a>
                    <a href="?page=admin_inscricoes" class="px-4 py-2 rounded-lg hover:bg-white/10">Inscrições</a>
                    <a href="?page=admin_recursos" class="px-4 py-2 rounded-lg hover:bg-white/10">Recursos</a>
                    <a href="?page=admin_publicacoes" class="px-4 py-2 rounded-lg hover:bg-white/10">Publicações</a>
                    <a href="?page=admin_cargos" class="px-4 py-2 rounded-lg hover:bg-white/10">Cargos</a>
                    <a href="?page=admin_config" class="px-4 py-2 rounded-lg hover:bg-white/10">Config</a>
                    <a href="?action=logout" class="ml-4 px-4 py-2 bg-red-500/20 text-red-200 rounded-lg hover:bg-red-500/30">Sair</a>
                <?php else: ?>
                    <a href="?" class="px-4 py-2 rounded-lg hover:bg-white/10">Início</a>
                    <a href="?page=inscricao" class="px-4 py-2 rounded-lg hover:bg-white/10">Inscrição</a>
                    <a href="?page=consulta" class="px-4 py-2 rounded-lg hover:bg-white/10">Consultar</a>
                    <a href="?page=recursos" class="px-4 py-2 rounded-lg hover:bg-white/10">Recursos</a>
                    <a href="?page=documentos" class="px-4 py-2 rounded-lg hover:bg-white/10">Documentos</a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</header>
<?php }

function renderFooter(): void { ?>
<footer class="bg-gov-950 text-white mt-16 no-print">
    <div class="max-w-7xl mx-auto px-4 py-12">
        <div class="grid md:grid-cols-3 gap-8">
            <div><h3 class="font-bold text-xl mb-4">Contato</h3><p class="text-gov-200">E-mail: <?= sanitize(getConfig('contato_email')) ?></p><p class="text-gov-200">Telefone: <?= sanitize(getConfig('contato_telefone')) ?></p></div>
            <div><h3 class="font-bold text-xl mb-4">Endereço</h3><p class="text-gov-200"><?= sanitize(getConfig('endereco')) ?></p></div>
            <div><h3 class="font-bold text-xl mb-4">Horário</h3><p class="text-gov-200">Segunda a Sexta: 7h às 13h</p></div>
        </div>
        <div class="border-t border-gov-800 mt-8 pt-8 text-center text-gov-300"><p>&copy; <?= date('Y') ?> <?= sanitize(getConfig('municipio')) ?></p></div>
    </div>
</footer></body></html>
<?php }

function renderAlert(string $type, string $msg): void {
    $c = ['success'=>'bg-green-50 border-green-200 text-green-800','error'=>'bg-red-50 border-red-200 text-red-800','warning'=>'bg-yellow-50 border-yellow-200 text-yellow-800','info'=>'bg-blue-50 border-blue-200 text-blue-800'];
    echo "<div class=\"{$c[$type]} border rounded-xl p-4 mb-6 animate-fade-in\"><p class=\"font-medium\">{$msg}</p></div>";
}

// ==================== PÁGINA HOME ====================

function pageHome(): void {
    renderHead(); renderHeader();
    $status = getStatusPeriodo(); $db = getDB();
    $cargos = $db->query("SELECT * FROM cargos WHERE ativo=1")->fetchAll();
    $pubs = $db->query("SELECT * FROM publicacoes WHERE publicado=1 ORDER BY publicado_em DESC LIMIT 5")->fetchAll();
?>
<main class="max-w-7xl mx-auto px-4 py-12">
    <div class="card p-8 md:p-12 mb-12 animate-fade-in">
        <div class="text-center max-w-3xl mx-auto">
            <span class="inline-block px-4 py-1 bg-gov-100 text-gov-700 rounded-full text-sm font-semibold mb-4"><?= sanitize(getConfig('ano')) ?></span>
            <h1 class="font-display text-4xl md:text-5xl font-bold text-gray-900 mb-4"><?= sanitize(getConfig('titulo_chamada')) ?></h1>
            <p class="text-xl text-gray-600 mb-8"><?= sanitize(getConfig('subtitulo')) ?> — <?= sanitize(getConfig('municipio')) ?></p>
            <div class="inline-flex items-center px-6 py-3 rounded-full text-lg font-semibold <?= $status['status']==='inscricoes'?'bg-green-100 text-green-800':($status['status']==='recursos'?'bg-yellow-100 text-yellow-800':'bg-gray-100 text-gray-800') ?>">
                <span class="w-3 h-3 rounded-full mr-3 <?= $status['status']==='inscricoes'?'bg-green-500 animate-pulse':'bg-gray-500' ?>"></span>
                <?= sanitize($status['mensagem']) ?>
            </div>
            <?php if ($status['status']==='inscricoes'): ?><div class="mt-8"><a href="?page=inscricao" class="btn-primary text-lg">Fazer Inscrição</a></div><?php endif; ?>
        </div>
    </div>
    <div class="card p-8 mb-12">
        <h2 class="font-display text-2xl font-bold text-gray-900 mb-6">Cronograma</h2>
        <div class="grid md:grid-cols-5 gap-4">
            <?php $etapas=[['Publicação',getConfig('data_publicacao')],['Inscrições',date('d/m',strtotime(getConfig('inscricoes_inicio'))).' a '.date('d/m',strtotime(getConfig('inscricoes_fim')))],['Resultado Preliminar',getConfig('resultado_preliminar')],['Recursos',date('d/m',strtotime(getConfig('recursos_inicio')))],['Resultado Final',getConfig('resultado_final')]];
            foreach($etapas as $i=>$e): ?>
            <div class="bg-white rounded-xl p-4 border-2 border-gov-100 text-center">
                <div class="w-10 h-10 bg-gov-100 rounded-full flex items-center justify-center mx-auto mb-2"><span class="text-gov-700 font-bold"><?= $i+1 ?></span></div>
                <h3 class="font-semibold text-gray-900 text-sm"><?= $e[0] ?></h3>
                <p class="text-gov-600 text-sm"><?= strlen($e[1])==10?date('d/m/Y',strtotime($e[1])):$e[1] ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card p-8 mb-12">
        <h2 class="font-display text-2xl font-bold text-gray-900 mb-6">Cargos Disponíveis</h2>
        <div class="overflow-x-auto">
            <table class="w-full"><thead><tr class="border-b-2 border-gov-100"><th class="text-left py-4 px-4">Cargo</th><th class="text-center py-4 px-4">CH</th><th class="text-center py-4 px-4">Vagas</th><th class="text-center py-4 px-4">Vencimento</th><th class="text-left py-4 px-4">Habilitação</th></tr></thead>
            <tbody><?php foreach($cargos as $c): ?>
            <tr class="border-b border-gray-100 hover:bg-gov-50/50"><td class="py-4 px-4 font-medium"><?= sanitize($c['nome']) ?></td><td class="py-4 px-4 text-center"><?= $c['carga_horaria'] ?>h</td><td class="py-4 px-4 text-center"><span class="px-3 py-1 bg-gov-100 text-gov-700 rounded-full font-semibold"><?= $c['vagas'] ?> + CR</span></td><td class="py-4 px-4 text-center font-semibold text-green-600">R$ <?= number_format($c['vencimento'],2,',','.') ?></td><td class="py-4 px-4 text-gray-600 text-sm"><?= sanitize($c['habilitacao']) ?></td></tr>
            <?php endforeach; ?></tbody></table>
        </div>
    </div>
    <?php if(!empty($pubs)): ?>
    <div class="card p-8">
        <h2 class="font-display text-2xl font-bold text-gray-900 mb-6">Publicações</h2>
        <div class="space-y-4"><?php foreach($pubs as $p): ?>
            <a href="?page=documentos" class="block p-4 rounded-xl border border-gray-200 hover:border-gov-300 hover:bg-gov-50/50">
                <span class="px-2 py-1 bg-gov-100 text-gov-700 rounded text-xs font-semibold uppercase"><?= sanitize($p['tipo']) ?></span>
                <h3 class="font-semibold text-gray-900 mt-2"><?= sanitize($p['titulo']) ?></h3>
                <p class="text-sm text-gray-500"><?= date('d/m/Y',strtotime($p['publicado_em'])) ?></p>
            </a>
        <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
</main>
<?php renderFooter(); }

// ==================== PÁGINA INSCRIÇÃO ====================

function pageInscricao(): void {
    $resultado = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $resultado = processarInscricao();
    
    renderHead('Inscrição'); renderHeader();
    $db = getDB();
    $cargos = $db->query("SELECT * FROM cargos WHERE ativo=1")->fetchAll();
    $aberto = periodoInscricoesAberto();
    $txtParent = getConfig('texto_declaracao_parentesco');
    $txtNaoPart = getConfig('texto_declaracao_nao_participou');
?>
<main class="max-w-4xl mx-auto px-4 py-12">
    <div class="card p-8 animate-fade-in">
        <h1 class="font-display text-3xl font-bold text-gray-900 mb-2">Formulário de Inscrição</h1>
        <p class="text-gray-600 mb-8">Preencha todos os campos obrigatórios (*) e anexe os documentos necessários.</p>
        
        <?php if ($resultado): ?>
            <?php if ($resultado['success']): ?>
                <!-- Comprovante de Inscrição -->
                <div id="comprovante-inscricao" class="mb-8">
                    <!-- Cabeçalho impresso -->
                    <div class="hidden print:block text-center border-b-2 border-gray-800 pb-4 mb-6">
                        <h2 class="font-bold text-xl uppercase tracking-wide"><?= sanitize(getConfig('municipio')) ?></h2>
                        <p class="text-sm text-gray-700 mt-1"><?= sanitize(getConfig('titulo_chamada')) ?></p>
                        <p class="text-xs text-gray-500 mt-1 font-semibold uppercase tracking-widest">Comprovante de Inscrição</p>
                    </div>
                    
                    <!-- Sucesso -->
                    <div class="bg-green-50 border-2 border-green-300 rounded-xl p-6 mb-6">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="flex-shrink-0 w-12 h-12 bg-green-500 rounded-full flex items-center justify-center text-white text-2xl">✓</span>
                            <div>
                                <h3 class="text-xl font-bold text-green-800">Inscrição Realizada com Sucesso!</h3>
                                <p class="text-green-600 text-sm">Sua inscrição foi registrada no sistema.</p>
                            </div>
                        </div>
                        
                        <!-- Protocolo -->
                        <div class="bg-white rounded-xl p-5 border-2 border-green-300 text-center mb-4">
                            <p class="text-sm text-gray-500 uppercase tracking-wider font-semibold mb-1">Número de Protocolo</p>
                            <span class="text-4xl font-mono font-black text-green-700 tracking-wider"><?= $resultado['protocolo'] ?></span>
                        </div>
                        
                        <!-- Dados da inscrição -->
                        <div class="grid sm:grid-cols-3 gap-4 mb-4">
                            <div class="bg-white rounded-lg p-3 border border-green-200">
                                <p class="text-xs text-gray-500 uppercase font-semibold">Candidato(a)</p>
                                <p class="font-semibold text-gray-900 mt-1"><?= sanitize($resultado['nome']) ?></p>
                            </div>
                            <div class="bg-white rounded-lg p-3 border border-green-200">
                                <p class="text-xs text-gray-500 uppercase font-semibold">Cargo</p>
                                <p class="font-semibold text-gray-900 mt-1"><?= sanitize($resultado['cargo_nome']) ?> (<?= $resultado['cargo_ch'] ?>h)</p>
                            </div>
                            <div class="bg-white rounded-lg p-3 border border-green-200">
                                <p class="text-xs text-gray-500 uppercase font-semibold">Data/Hora</p>
                                <p class="font-semibold text-gray-900 mt-1"><?= $resultado['data_inscricao'] ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- AVISO PROTOCOLO (destacado) -->
                    <div class="relative bg-gradient-to-r from-amber-50 to-yellow-50 border-2 border-amber-400 rounded-xl p-6 mb-6 shadow-md">
                        <div class="absolute -top-3 left-6 bg-amber-500 text-white text-xs font-bold uppercase tracking-wider px-3 py-1 rounded-full shadow">Atenção</div>
                        <div class="flex items-start gap-4 mt-1">
                            <span class="text-4xl flex-shrink-0">🔑</span>
                            <div>
                                <h4 class="text-lg font-bold text-amber-900 mb-2">Guarde seu número de protocolo!</h4>
                                <p class="text-amber-800 text-sm leading-relaxed">O número de protocolo <strong class="font-mono bg-amber-200 px-2 py-0.5 rounded"><?= $resultado['protocolo'] ?></strong> é o <strong>único meio</strong> para consultar sua inscrição, verificar classificação e interpor recursos. Sem ele, não será possível acessar suas informações.</p>
                                <p class="text-amber-700 text-sm mt-2 font-semibold">📸 Tire um print desta tela ou 🖨️ imprima este comprovante agora.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rodapé impresso -->
                    <div class="hidden print:block mt-8 pt-4 border-t-2 border-gray-300">
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>Documento gerado automaticamente pelo sistema.</span>
                            <span>Impresso em: <script>document.write(new Date().toLocaleString('pt-BR'))</script></span>
                        </div>
                        <p class="text-xs text-gray-400 mt-2 text-center">Este comprovante não dispensa o acompanhamento das publicações oficiais referentes à Chamada Pública.</p>
                    </div>
                    
                    <!-- Botões (não imprimem) -->
                    <div class="flex flex-wrap gap-4 no-print">
                        <button onclick="imprimirComprovante()" class="btn-primary text-base px-6">🖨️ Imprimir Comprovante</button>
                        <a href="?page=consulta" class="btn-secondary text-base px-6">🔍 Consultar Inscrição</a>
                    </div>
                </div>
                <script>
                function imprimirComprovante() {
                    window.print();
                }
                </script>
            <?php else: renderAlert('error', $resultado['message']); endif; ?>
        <?php endif; ?>
        
        <?php if (!$aberto && !($resultado['success'] ?? false)): renderAlert('warning', 'Período de inscrições encerrado.');
        elseif (!($resultado['success'] ?? false)): ?>
        
        <div id="cpf-aviso" class="hidden mb-6"></div>
        
        <!-- Confirmação de substituição (mostrado via AJAX quando CPF já existe) -->
        <div id="confirmar-sub-container" class="hidden mb-6">
            <div class="bg-yellow-50 border-2 border-yellow-300 rounded-xl p-4">
                <label class="flex items-start cursor-pointer">
                    <input type="checkbox" id="confirmar_sub_checkbox" class="mt-1 w-5 h-5 text-yellow-600 rounded" onchange="document.getElementById('confirmar_substituicao_field').value = this.checked ? '1' : '';">
                    <span class="ml-3 text-sm text-yellow-800 font-medium">⚠️ Confirmo que desejo <strong>substituir minha inscrição anterior</strong>. Entendo que a inscrição anterior será cancelada e a nova será a válida.</span>
                </label>
            </div>
        </div>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-8" id="form-inscricao" onsubmit="return prepararSubmit()">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="confirmar_substituicao" id="confirmar_substituicao_field" value="">
            
            <!-- Dados Pessoais -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6">
                <legend class="text-lg font-bold text-gray-900 px-2">Dados Pessoais</legend>
                <div class="grid md:grid-cols-2 gap-6 mt-4">
                    <div class="md:col-span-2"><label class="block text-sm font-semibold text-gray-700 mb-2">Nome Completo *</label><input type="text" name="nome" required class="input-field" value="<?= sanitize($_POST['nome']??'') ?>"></div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">CPF *</label>
                        <input type="text" name="cpf" id="cpf-field" required class="input-field" maxlength="14" value="<?= sanitize($_POST['cpf']??'') ?>" oninput="mascaraCPF(this); verificarCPFDebounce();">
                        <p id="cpf-status" class="text-sm mt-1"></p>
                    </div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-2">Número do RG *</label><input type="text" name="rg" required class="input-field" value="<?= sanitize($_POST['rg']??'') ?>"></div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-2">Data de Nascimento *</label><input type="date" name="data_nascimento" required class="input-field" value="<?= sanitize($_POST['data_nascimento']??'') ?>"></div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-2">Estado Civil *</label><select name="estado_civil" required class="input-field"><option value="">Selecione...</option><option value="solteiro">Solteiro(a)</option><option value="casado">Casado(a)</option><option value="divorciado">Divorciado(a)</option><option value="viuvo">Viúvo(a)</option><option value="uniao_estavel">União Estável</option></select></div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-2">Telefone *</label><input type="tel" name="telefone" required class="input-field" placeholder="(00) 00000-0000" value="<?= sanitize($_POST['telefone']??'') ?>"></div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-2">E-mail *</label><input type="email" name="email" required class="input-field" value="<?= sanitize($_POST['email']??'') ?>"></div>
                </div>
            </fieldset>
            
            <!-- Endereço -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6">
                <legend class="text-lg font-bold text-gray-900 px-2">Endereço</legend>
                <div class="grid md:grid-cols-6 gap-4 mt-4">
                    <div class="md:col-span-4"><label class="block text-sm font-semibold text-gray-700 mb-2">Logradouro *</label><input type="text" name="logradouro" required class="input-field"></div>
                    <div class="md:col-span-2"><label class="block text-sm font-semibold text-gray-700 mb-2">Número *</label><input type="text" name="numero" required class="input-field"></div>
                    <div class="md:col-span-2"><label class="block text-sm font-semibold text-gray-700 mb-2">Bairro *</label><input type="text" name="bairro" required class="input-field"></div>
                    <div class="md:col-span-2"><label class="block text-sm font-semibold text-gray-700 mb-2">Cidade *</label><input type="text" name="cidade" required class="input-field"></div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-2">Estado *</label><select name="estado" required class="input-field"><option value="SC" selected>SC</option><option value="RS">RS</option><option value="PR">PR</option></select></div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-2">CEP *</label><input type="text" name="cep" required class="input-field" maxlength="9" oninput="this.value=this.value.replace(/\D/g,'').replace(/(\d{5})(\d)/,'$1-$2')"></div>
                </div>
            </fieldset>
            
            <!-- Cargo -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6">
                <legend class="text-lg font-bold text-gray-900 px-2">Cargo Pretendido</legend>
                <div class="mt-4 space-y-4">
                    <?php foreach($cargos as $c): ?>
                    <label class="flex items-start p-4 border-2 border-gray-200 rounded-xl cursor-pointer hover:border-gov-300 transition-colors">
                        <input type="radio" name="cargo_id" value="<?= $c['id'] ?>" data-tipo="<?= sanitize($c['tipo']) ?>" required class="mt-1 w-5 h-5 text-gov-600" onchange="onCargoChange(this); verificarCPFDebounce();">
                        <div class="ml-4 flex-1">
                            <span class="font-semibold text-gray-900"><?= sanitize($c['nome']) ?></span>
                            <span class="text-gov-600 font-medium"> — <?= $c['carga_horaria'] ?>h semanais</span>
                            <p class="text-sm text-gray-500 mt-1"><?= sanitize($c['habilitacao']) ?></p>
                            <p class="text-sm font-semibold text-green-600">Vencimento: R$ <?= number_format($c['vencimento'],2,',','.') ?></p>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            
            <!-- Critérios de Classificação (campos dinâmicos conforme cargo) -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6" id="fieldset-criterios" style="display:none">
                <legend class="text-lg font-bold text-gray-900 px-2">Critérios de Classificação</legend>
                <p class="text-sm text-gray-500 mt-2 mb-4" id="criterios-info"></p>
                
                <div class="grid md:grid-cols-2 gap-6 mt-4">
                    <!-- Titulação Professor -->
                    <div id="tit-professor" style="display:none">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Titulação Acadêmica <span class="text-gray-400 font-normal">(máx. 45 pts)</span></label>
                        <select name="titulacao_professor" class="input-field" onchange="syncTitulacao(this)">
                            <option value="nenhuma">Nenhuma / Não se aplica — 0 pts</option>
                            <option value="especializacao">Pós-graduação lato sensu (Educação ou área afim) — 20 pts</option>
                            <option value="mestrado">Mestrado (Educação ou área afim) — 30 pts</option>
                            <option value="doutorado">Doutorado (Educação ou área afim) — 45 pts</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Será considerada apenas a maior titulação comprovada.</p>
                    </div>
                    
                    <!-- Formação Auxiliar -->
                    <div id="tit-auxiliar" style="display:none">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Formação na Área de Pedagogia <span class="text-gray-400 font-normal">(máx. 45 pts)</span></label>
                        <select name="titulacao_auxiliar" class="input-field" onchange="syncTitulacao(this)">
                            <option value="nenhuma">Nenhuma / Não se aplica — 0 pts</option>
                            <option value="cursando_pedagogia">Cursando graduação em Pedagogia (matrícula ativa) — 25 pts</option>
                            <option value="pedagogia">Pedagogia concluída — 30 pts</option>
                            <option value="pedagogia_pos">Pedagogia concluída com Pós-graduação (Educação) — 45 pts</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Será considerada apenas a maior formação, vedada cumulação.</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Carga Horária de Cursos de Formação/Aperfeiçoamento <span class="text-gray-400 font-normal">(máx. 20 pts)</span></label>
                        <input type="number" name="carga_horaria_cursos" min="0" max="9999" class="input-field" value="0" oninput="calcularPrevia()">
                        <p class="text-xs text-gray-500 mt-1">0,05 ponto/hora na área da Educação. Limite: 400 horas (20 pts máx.).</p>
                    </div>
                </div>
                
                <!-- Hidden field que recebe o valor real da titulação -->
                <input type="hidden" name="titulacao" id="titulacao-real" value="nenhuma">
                
                <!-- Prévia de pontuação -->
                <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-xl" id="previa-pontuacao" style="display:none">
                    <h4 class="text-sm font-bold text-blue-800 mb-2">📊 Prévia da Pontuação (estimativa)</h4>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <div><span class="text-blue-600">Titulação/Formação:</span> <strong id="prev-tit">0</strong> pts</div>
                        <div><span class="text-blue-600">Tempo de Serviço:</span> <strong id="prev-ts">0</strong> pts</div>
                        <div><span class="text-blue-600">Cursos:</span> <strong id="prev-cur">0</strong> pts</div>
                    </div>
                    <div class="mt-2 pt-2 border-t border-blue-200 text-right"><span class="text-blue-800 font-bold text-lg">Total: <span id="prev-total">0</span> / 100 pts</span></div>
                    <p class="text-xs text-blue-500 mt-1">Desempate: candidato com maior idade. O tempo de serviço será calculado após preenchimento da seção abaixo.</p>
                </div>
            </fieldset>
            
            <!-- Tempo de Serviço — CAMPOS AMPLIADOS -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6">
                <legend class="text-lg font-bold text-gray-900 px-2">Tempo de Serviço na Área de Atuação <span class="text-gray-400 font-normal text-sm">(máx. 35 pts)</span></legend>
                <p class="text-sm text-gray-600 mb-2">1 ponto por ano completo de efetivo exercício, até o limite de 35 anos.</p>
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
                    <p class="text-xs text-amber-800">⚠️ <strong>Atenção:</strong> Caso possua experiência em função similar à exigida, é obrigatório comprovar a equivalência por meio de <strong>documento oficial (ex: lei municipal)</strong> contendo a descrição sumária do cargo. Anexe na seção de documentos abaixo (campo "Documento de Equivalência"). A Administração poderá desconsiderar o tempo de serviço sem comprovação de equivalência.</p>
                </div>
                <div id="tempo-servico-container">
                    <div class="tempo-servico-item border border-gray-200 rounded-lg p-4 mb-4">
                        <div class="grid md:grid-cols-2 gap-4 mb-4">
                            <div><label class="block text-sm font-semibold text-gray-700 mb-2">Data Início</label><input type="date" name="tempo_inicio[]" class="input-field" onchange="calcularPrevia()"></div>
                            <div><label class="block text-sm font-semibold text-gray-700 mb-2">Data Fim</label><input type="date" name="tempo_fim[]" class="input-field" onchange="calcularPrevia()"></div>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Local de Trabalho</label>
                            <textarea name="tempo_local[]" rows="2" class="input-field" placeholder="Nome completo da instituição/empresa onde trabalhou" style="min-height:60px;resize:vertical"></textarea>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Função Exercida</label>
                            <textarea name="tempo_funcao[]" rows="2" class="input-field" placeholder="Descrição completa do cargo ou função exercida" style="min-height:60px;resize:vertical"></textarea>
                        </div>
                        <div class="flex justify-end"><button type="button" onclick="removerTS(this)" class="text-red-600 hover:text-red-800 text-sm font-semibold">✕ Remover Período</button></div>
                    </div>
                </div>
                <button type="button" onclick="adicionarTS()" class="btn-secondary w-full mt-2">+ Adicionar Período de Experiência</button>
            </fieldset>
            
            <!-- Documentos com Preview e Visualização PDF -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6">
                <legend class="text-lg font-bold text-gray-900 px-2">Documentos</legend>
                <p class="text-sm text-gray-600 mb-6">Formatos: PDF, JPG, PNG, DOC, DOCX. Máx: 10MB por arquivo. Clique em <strong>Visualizar</strong> para verificar o arquivo antes de enviar.</p>
                <div class="space-y-4">
                    <?php
                    $cats = getDocCategories();
                    foreach ($cats as $campo => $info):
                        $obg = $info['obrigatorio'];
                        $multi = !$obg; // opcionais permitem múltiplos
                        $bgClass = $obg ? 'bg-red-50 border-red-200' : 'bg-white border-gray-200';
                    ?>
                    <div class="p-4 <?= $bgClass ?> border rounded-lg">
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><?= $info['icone'] ?> <?= $info['nome'] ?> <?= $obg?'*':'' ?></label>
                        <input type="file" name="<?= $campo ?><?= $multi?'[]':'' ?>" id="file-<?= $campo ?>" <?= $obg?'required':'' ?> <?= $multi?'multiple':'' ?> accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" onchange="showPreview(this,'preview-<?= $campo ?>')">
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="document.getElementById('file-<?= $campo ?>').click()" class="btn-secondary text-sm py-2">📁 Selecionar <?= $multi?'Arquivos':'Arquivo' ?></button>
                            <?php if ($multi): ?><span class="text-xs text-gray-500">Pode selecionar múltiplos</span><?php endif; ?>
                        </div>
                        <div id="preview-<?= $campo ?>" class="mt-2"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            
            <!-- Declarações -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6">
                <legend class="text-lg font-bold text-gray-900 px-2">Declarações Obrigatórias</legend>
                <div class="space-y-4 mt-4">
                    <label class="flex items-start p-4 border-2 border-gray-200 rounded-xl cursor-pointer hover:border-gov-300">
                        <input type="checkbox" name="declaracao_parentesco" value="1" required class="mt-1 w-5 h-5 text-gov-600 rounded">
                        <span class="ml-3 text-sm text-gray-700"><?= sanitize($txtParent) ?> *</span>
                    </label>
                    <label class="flex items-start p-4 border-2 border-gray-200 rounded-xl cursor-pointer hover:border-gov-300">
                        <input type="checkbox" name="declaracao_nao_participou" value="1" required class="mt-1 w-5 h-5 text-gov-600 rounded">
                        <span class="ml-3 text-sm text-gray-700"><?= sanitize($txtNaoPart) ?> *</span>
                    </label>
                    <label class="flex items-start p-4 border-2 border-amber-200 bg-amber-50 rounded-xl cursor-pointer hover:border-amber-300">
                        <input type="checkbox" name="aceite_termos" value="1" required class="mt-1 w-5 h-5 text-gov-600 rounded">
                        <span class="ml-3 text-sm text-gray-700">Declaro que li e aceito todas as condições do Edital e que as informações são verdadeiras. *</span>
                    </label>
                </div>
            </fieldset>
            
            <div class="flex justify-end"><button type="submit" class="btn-primary text-lg px-8" id="btn-submit">Enviar Inscrição</button></div>
        </form>
        
        <script>
        // Sincronizar checkbox de substituição no exato momento do submit
        function prepararSubmit() {
            const cb = document.getElementById('confirmar_sub_checkbox');
            const field = document.getElementById('confirmar_substituicao_field');
            const container = document.getElementById('confirmar-sub-container');
            
            // Se o container de confirmação está visível e checkbox não está marcado
            if (container && !container.classList.contains('hidden') && cb && !cb.checked) {
                alert('Já existe uma inscrição ativa para este CPF. Marque a opção de confirmação de substituição para continuar.');
                return false;
            }
            
            // Garantir que o hidden field reflete o estado atual do checkbox
            if (cb && cb.checked) {
                field.value = '1';
            }
            
            return true;
        }
        
        // Máscara CPF
        function mascaraCPF(el) { let v=el.value.replace(/\D/g,''); v=v.replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d{1,2})$/,'$1-$2'); el.value=v; }
        
        // Verificação CPF via AJAX - mostra/esconde checkbox de confirmação
        let cpfTimer=null;
        function verificarCPFDebounce() { clearTimeout(cpfTimer); cpfTimer=setTimeout(verificarCPF, 600); }
        function verificarCPF() {
            const cpf=document.getElementById('cpf-field').value.replace(/\D/g,'');
            const cargo=document.querySelector('input[name="cargo_id"]:checked');
            const st=document.getElementById('cpf-status');
            const av=document.getElementById('cpf-aviso');
            const confirmBox=document.getElementById('confirmar-sub-container');
            const confirmCB=document.getElementById('confirmar_sub_checkbox');
            const confirmField=document.getElementById('confirmar_substituicao_field');
            
            if(cpf.length!==11) { st.innerHTML=''; av.classList.add('hidden'); confirmBox.classList.add('hidden'); confirmField.value=''; if(confirmCB) confirmCB.checked=false; return; }
            
            fetch(`?api=verificar_cpf&cpf=${cpf}&cargo_id=${cargo?cargo.value:0}`)
                .then(r=>r.json()).then(data=>{
                    if(!data.valido){
                        st.innerHTML='<span class="text-red-600">❌ '+data.mensagem+'</span>';
                        av.classList.add('hidden'); confirmBox.classList.add('hidden'); confirmField.value='';
                    } else if(data.substituir){
                        st.innerHTML='<span class="text-yellow-600">⚠️ CPF já cadastrado</span>';
                        av.innerHTML='<div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-xl p-4"><strong>Atenção:</strong> '+data.mensagem+'</div>';
                        av.classList.remove('hidden');
                        confirmBox.classList.remove('hidden');
                        // Reset checkbox ao detectar nova situação
                        if(confirmCB) confirmCB.checked=false;
                        confirmField.value='';
                    } else {
                        st.innerHTML='<span class="text-green-600">✓ CPF disponível</span>';
                        av.classList.add('hidden'); confirmBox.classList.add('hidden'); confirmField.value='';
                    }
                }).catch(()=>{
                    st.innerHTML=''; av.classList.add('hidden'); confirmBox.classList.add('hidden');
                });
        }
        
        // Tempo de Serviço
        function adicionarTS() {
            const c=document.getElementById('tempo-servico-container');
            const t=c.querySelector('.tempo-servico-item').cloneNode(true);
            t.querySelectorAll('input,textarea').forEach(el=>el.value='');
            // Garantir que novos campos de data também disparem calcularPrevia
            t.querySelectorAll('input[type="date"]').forEach(el=>el.addEventListener('change', calcularPrevia));
            c.appendChild(t);
        }
        function removerTS(btn) {
            const c=document.getElementById('tempo-servico-container');
            if(c.querySelectorAll('.tempo-servico-item').length>1) btn.closest('.tempo-servico-item').remove();
            calcularPrevia();
        }
        
        // === CARGO DINÂMICO ===
        let tipoCargoAtual = '';
        
        function onCargoChange(radio) {
            tipoCargoAtual = radio.dataset.tipo || '';
            const fs = document.getElementById('fieldset-criterios');
            const titProf = document.getElementById('tit-professor');
            const titAux = document.getElementById('tit-auxiliar');
            const info = document.getElementById('criterios-info');
            
            fs.style.display = '';
            
            if (tipoCargoAtual === 'professor') {
                titProf.style.display = '';
                titAux.style.display = 'none';
                // Reset auxiliar select
                document.querySelector('select[name="titulacao_auxiliar"]').value = 'nenhuma';
                info.textContent = 'Pontuação máxima: 100 pts (Titulação: 45 + Tempo de Serviço: 35 + Cursos: 20). Desempate: maior idade.';
                syncTitulacao(document.querySelector('select[name="titulacao_professor"]'));
            } else {
                titProf.style.display = 'none';
                titAux.style.display = '';
                // Reset professor select
                document.querySelector('select[name="titulacao_professor"]').value = 'nenhuma';
                info.textContent = 'Pontuação máxima: 100 pts (Formação: 45 + Tempo de Serviço: 35 + Cursos: 20). Desempate: maior idade.';
                syncTitulacao(document.querySelector('select[name="titulacao_auxiliar"]'));
            }
            
            document.getElementById('previa-pontuacao').style.display = '';
            calcularPrevia();
        }
        
        function syncTitulacao(sel) {
            document.getElementById('titulacao-real').value = sel.value;
            calcularPrevia();
        }
        
        function calcularPrevia() {
            const tit = document.getElementById('titulacao-real').value;
            let ptsTit = 0;
            
            if (tipoCargoAtual === 'professor') {
                ptsTit = {'doutorado':45,'mestrado':30,'especializacao':20}[tit] || 0;
            } else {
                ptsTit = {'pedagogia_pos':45,'pedagogia':30,'cursando_pedagogia':25}[tit] || 0;
            }
            
            // Calcular tempo de serviço dos períodos preenchidos
            let totalMeses = 0;
            document.querySelectorAll('.tempo-servico-item').forEach(item => {
                const ini = item.querySelector('input[name="tempo_inicio[]"]');
                const fim = item.querySelector('input[name="tempo_fim[]"]');
                if (ini && fim && ini.value && fim.value) {
                    const d1 = new Date(ini.value), d2 = new Date(fim.value);
                    if (d2 > d1) {
                        // Apurar em anos e meses, desconsiderando dias
                        const meses = (d2.getFullYear() - d1.getFullYear()) * 12 + (d2.getMonth() - d1.getMonth());
                        totalMeses += meses;
                    }
                }
            });
            // Proporcional: 1 pt a cada 12 meses (ex: 14m = 14/12 = 1.17 pts)
            const ptsTS = Math.min(35, Math.round((totalMeses / 12) * 100) / 100);
            
            const ch = Math.min(400, parseInt(document.querySelector('input[name="carga_horaria_cursos"]')?.value || 0));
            const ptsCur = Math.min(20, ch * 0.05);
            
            document.getElementById('prev-tit').textContent = ptsTit;
            document.getElementById('prev-ts').textContent = ptsTS.toFixed(2).replace(/\.?0+$/, '');
            document.getElementById('prev-cur').textContent = ptsCur.toFixed(1).replace('.0','');
            document.getElementById('prev-total').textContent = (ptsTit + ptsTS + ptsCur).toFixed(2).replace(/\.?0+$/, '');
        }
        
        // File preview com visualização de PDF/imagem
        function showPreview(input, previewId) {
            const prev=document.getElementById(previewId); prev.innerHTML='';
            Array.from(input.files).forEach((file,idx)=>{
                const div=document.createElement('div'); div.className='file-item';
                const isPDF = file.type==='application/pdf';
                const isImg = file.type.startsWith('image/');
                let viewBtn = '';
                if (isPDF || isImg) {
                    viewBtn = `<span class="file-view" onclick="visualizarArquivo(event, '${input.id}', ${idx})">👁️ Visualizar</span>`;
                }
                div.innerHTML=`<span class="mr-2">${isPDF?'📕':isImg?'🖼️':'📄'}</span><span class="file-name">${file.name}</span><span class="text-xs text-gray-500 mx-2">(${(file.size/1024).toFixed(1)} KB)</span>${viewBtn}<span class="file-remove" onclick="removerArquivo('${input.id}',${idx},'${previewId}')">✕</span>`;
                prev.appendChild(div);
            });
        }
        
        function visualizarArquivo(evt, inputId, idx) {
            evt.preventDefault();
            const file = document.getElementById(inputId).files[idx];
            if (!file) return;
            const url = URL.createObjectURL(file);
            window.open(url, '_blank');
        }
        
        function removerArquivo(inputId, idx, previewId) {
            const input=document.getElementById(inputId);
            const dt=new DataTransfer();
            Array.from(input.files).forEach((f,i)=>{ if(i!==idx) dt.items.add(f); });
            input.files=dt.files;
            showPreview(input, previewId);
        }
        </script>
        <?php endif; ?>
    </div>
</main>
<?php renderFooter(); }

// ==================== CONSULTA, RECURSOS, DOCUMENTOS ====================

function pageConsulta(): void {
    renderHead('Consultar Inscrição'); renderHeader();
    $candidato=null;$tempos=[];$exPont=getConfig('exibir_pontuacao_publica')==='1';$exClass=getConfig('exibir_classificacao_publica')==='1';
    if($_SERVER['REQUEST_METHOD']==='POST'||isset($_GET['protocolo'])){
        $cpf=sanitizeCPF($_POST['cpf']??$_GET['cpf']??'');$prot=sanitize($_POST['protocolo']??$_GET['protocolo']??'');
        if($prot){$db=getDB();$sql="SELECT c.*,cg.nome as cargo_nome,cg.carga_horaria,cg.tipo as cargo_tipo FROM candidatos c JOIN cargos cg ON c.cargo_id=cg.id WHERE c.protocolo=?";$p=[$prot];
            if($cpf){$sql.=" AND c.cpf=?";$p[]=$cpf;}$s=$db->prepare($sql);$s->execute($p);$candidato=$s->fetch();
            if($candidato)$tempos=$db->query("SELECT * FROM tempo_servico_periodos WHERE candidato_id={$candidato['id']} ORDER BY data_inicio")->fetchAll();
        }
    }
?>
<main class="max-w-4xl mx-auto px-4 py-12">
    <div class="card p-8 animate-fade-in" id="comprovante-consulta">
        <h1 class="font-display text-3xl font-bold text-gray-900 mb-8 no-print">Consultar Inscrição</h1>
        <form method="POST" class="space-y-6 mb-8 no-print">
            <div class="grid md:grid-cols-2 gap-6">
                <div><label class="block text-sm font-semibold text-gray-700 mb-2">CPF</label><input type="text" name="cpf" class="input-field" value="<?= sanitize($_POST['cpf']??'') ?>" oninput="mascaraCPF(this)"></div>
                <div><label class="block text-sm font-semibold text-gray-700 mb-2">Protocolo *</label><input type="text" name="protocolo" required class="input-field" value="<?= sanitize($_POST['protocolo']??$_GET['protocolo']??'') ?>"></div>
            </div>
            <button type="submit" class="btn-primary">Consultar</button>
        </form>
        <script>function mascaraCPF(el){let v=el.value.replace(/\D/g,'');v=v.replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d{1,2})$/,'$1-$2');el.value=v;}</script>
        <?php if($_SERVER['REQUEST_METHOD']==='POST'||isset($_GET['protocolo'])): ?>
            <?php if($candidato): ?>
            <div class="border-t-2 border-gray-100 pt-8">
                <div class="text-center mb-6 hidden print:block"><h2 class="font-bold text-xl"><?= sanitize(getConfig('municipio')) ?></h2><p><?= sanitize(getConfig('titulo_chamada')) ?></p><p class="text-sm text-gray-600">Comprovante de Inscrição</p><hr class="my-4"></div>
                <div class="flex items-center justify-between mb-6">
                    <h2 class="font-display text-2xl font-bold text-gray-900">Dados da Inscrição</h2>
                    <div class="flex items-center space-x-4 no-print">
                        <span class="status-badge <?= statusBadgeClass($candidato['status']) ?>"><?= ucfirst($candidato['status']) ?></span>
                        <button onclick="window.print()" class="btn-secondary text-sm">🖨️ Imprimir</button>
                    </div>
                </div>
                <div class="grid md:grid-cols-2 gap-6 mb-8">
                    <div class="space-y-3">
                        <div><span class="text-sm text-gray-500">Protocolo</span><p class="font-mono font-bold text-gov-600 text-lg"><?= sanitize($candidato['protocolo']) ?></p></div>
                        <div><span class="text-sm text-gray-500">Nome</span><p class="font-semibold"><?= sanitize($candidato['nome']) ?></p></div>
                        <div><span class="text-sm text-gray-500">CPF</span><p><?= formatarCPF($candidato['cpf']) ?></p></div>
                        <div><span class="text-sm text-gray-500">RG</span><p><?= sanitize($candidato['rg']) ?></p></div>
                        <div><span class="text-sm text-gray-500">Data de Nascimento</span><p><?= date('d/m/Y',strtotime($candidato['data_nascimento'])) ?></p></div>
                    </div>
                    <div class="space-y-3">
                        <div><span class="text-sm text-gray-500">Cargo</span><p class="font-semibold"><?= sanitize($candidato['cargo_nome']) ?> (<?= $candidato['carga_horaria'] ?>h)</p></div>
                        <div><span class="text-sm text-gray-500">Titulação/Formação</span><p><?= getTitulacaoLabel($candidato['titulacao'], $candidato['cargo_tipo']) ?></p></div>
                        <div><span class="text-sm text-gray-500">Tempo de Serviço</span><p><?= $candidato['tempo_servico_meses'] ?> meses</p></div>
                        <div><span class="text-sm text-gray-500">CH Cursos</span><p><?= $candidato['carga_horaria_cursos'] ?>h</p></div>
                        <?php if($exPont): ?><div class="p-3 bg-gov-50 rounded-lg"><span class="text-sm text-gov-600">Pontuação</span><p class="text-2xl font-bold text-gov-700"><?= number_format($candidato['pontuacao'],2,',','.') ?> pts</p></div><?php endif; ?>
                        <?php if($exClass && $candidato['classificacao']>0): ?><div class="p-3 bg-green-50 rounded-lg"><span class="text-sm text-green-600">Classificação</span><p class="text-2xl font-bold text-green-700"><?= $candidato['classificacao'] ?>º lugar</p></div><?php endif; ?>
                    </div>
                </div>
                <?php if(!empty($tempos)): ?>
                <div class="mb-8"><h3 class="font-bold text-gray-900 mb-3">Tempo de Serviço Declarado</h3>
                    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b bg-gray-50"><th class="py-2 px-3 text-left">Período</th><th class="py-2 px-3 text-left">Local</th><th class="py-2 px-3 text-left">Função</th><th class="py-2 px-3 text-center">Meses</th></tr></thead><tbody>
                        <?php $tot=0;foreach($tempos as $ts):$tot+=$ts['meses']; ?>
                        <tr class="border-b"><td class="py-2 px-3"><?= date('d/m/Y',strtotime($ts['data_inicio'])) ?> a <?= date('d/m/Y',strtotime($ts['data_fim'])) ?></td><td class="py-2 px-3"><?= sanitize($ts['local_trabalho']) ?></td><td class="py-2 px-3"><?= sanitize($ts['funcao']) ?></td><td class="py-2 px-3 text-center font-semibold"><?= $ts['meses'] ?></td></tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-50 font-bold"><td colspan="3" class="py-2 px-3 text-right">Total:</td><td class="py-2 px-3 text-center"><?= $tot ?> meses</td></tr>
                    </tbody></table></div>
                </div>
                <?php endif; ?>
                <div class="text-sm text-gray-500 mt-8 pt-4 border-t"><p>Data da Inscrição: <?= date('d/m/Y H:i',strtotime($candidato['inscrito_em'])) ?></p></div>
                <?php if(periodoRecursosAberto()): ?><div class="text-center mt-6 no-print"><a href="?page=recursos&cpf=<?= $candidato['cpf'] ?>&protocolo=<?= $candidato['protocolo'] ?>" class="btn-secondary">Interpor Recurso</a></div><?php endif; ?>
            </div>
            <?php else: renderAlert('error','Inscrição não encontrada.'); endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php renderFooter(); }

function pageRecursos(): void {
    $resultado=null;if($_SERVER['REQUEST_METHOD']==='POST')$resultado=processarRecurso();
    renderHead('Interpor Recurso');renderHeader();$aberto=periodoRecursosAberto();
?>
<main class="max-w-3xl mx-auto px-4 py-12">
    <div class="card p-8 animate-fade-in">
        <h1 class="font-display text-3xl font-bold text-gray-900 mb-8">Interpor Recurso</h1>
        <?php if($resultado):if($resultado['success']): ?>
            <div class="bg-green-50 border-2 border-green-200 rounded-xl p-6 mb-8" id="comprovante-recurso">
                <div class="text-center mb-4 hidden print:block"><h2 class="font-bold text-xl"><?= sanitize(getConfig('municipio')) ?></h2><p><?= sanitize(getConfig('titulo_chamada')) ?></p><p class="text-sm">Comprovante de Recurso</p></div>
                <h3 class="text-lg font-bold text-green-800 mb-2">✓ Recurso Interposto!</h3>
                <div class="bg-white rounded-lg p-4 border border-green-200 mb-4"><span class="text-2xl font-mono font-bold text-green-600"><?= $resultado['protocolo_recurso'] ?></span></div>
                <p class="text-green-600 text-sm mb-4"><strong>Guarde este número</strong> como comprovante. O resultado será divulgado por edital.</p>
                <button onclick="window.print()" class="btn-success no-print">🖨️ Imprimir Comprovante</button>
            </div>
        <?php else:renderAlert('error',$resultado['message']);endif;endif; ?>
        <?php if(!$aberto&&!($resultado['success']??false)):renderAlert('warning','Período de recursos encerrado.');
        elseif(!($resultado['success']??false)): ?>
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="grid md:grid-cols-2 gap-6">
                <div><label class="block text-sm font-semibold text-gray-700 mb-2">CPF *</label><input type="text" name="cpf" required class="input-field" value="<?= sanitize($_GET['cpf']??'') ?>"></div>
                <div><label class="block text-sm font-semibold text-gray-700 mb-2">Protocolo *</label><input type="text" name="protocolo" required class="input-field" value="<?= sanitize($_GET['protocolo']??'') ?>"></div>
            </div>
            <div><label class="block text-sm font-semibold text-gray-700 mb-2">Tipo *</label><select name="tipo_recurso" required class="input-field"><option value="">Selecione...</option><option value="pontuacao">Revisão de Pontuação</option><option value="classificacao">Revisão de Classificação</option><option value="documentacao">Documentação</option><option value="indeferimento">Contra Indeferimento</option><option value="outros">Outros</option></select></div>
            <div><label class="block text-sm font-semibold text-gray-700 mb-2">Fundamentação *</label><textarea name="fundamentacao" required rows="8" class="input-field" minlength="20" placeholder="Descreva detalhadamente..."></textarea></div>
            
            <!-- Upload de documentos do recurso -->
            <fieldset class="border-2 border-gray-100 rounded-xl p-6">
                <legend class="text-lg font-bold text-gray-900 px-2">📎 Documentos Anexos</legend>
                <p class="text-sm text-gray-500 mb-4">Anexe documentos que fundamentem o recurso (opcional). Formatos: PDF, JPG, PNG, DOC, DOCX. Máx. 10MB por arquivo.</p>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Selecionar Documentos</label>
                    <input type="file" name="docs_recurso[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="input-field" id="docs-recurso-input" onchange="previewDocsRecurso(this)">
                    <div id="docs-recurso-preview" class="mt-2"></div>
                </div>
            </fieldset>
            <script>
            function previewDocsRecurso(input) {
                const prev = document.getElementById('docs-recurso-preview');
                prev.innerHTML = '';
                if (!input.files.length) return;
                let totalSize = 0;
                Array.from(input.files).forEach((f, i) => {
                    totalSize += f.size;
                    const kb = (f.size/1024).toFixed(1);
                    const over = f.size > 10*1024*1024;
                    prev.innerHTML += `<div class="file-item ${over?'border border-red-300 bg-red-50':''}">
                        <span class="file-name">${f.name} (${kb} KB)${over?' — <span class=text-red-600>Excede 10MB!</span>':''}</span>
                        <span class="file-remove" onclick="removerDocRecurso(${i})">✕</span>
                    </div>`;
                });
            }
            function removerDocRecurso(idx) {
                const input = document.getElementById('docs-recurso-input');
                const dt = new DataTransfer();
                Array.from(input.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
                input.files = dt.files;
                previewDocsRecurso(input);
            }
            </script>
            
            <div class="flex justify-end"><button type="submit" class="btn-primary">Enviar Recurso</button></div>
        </form>
        <?php endif; ?>
    </div>
</main>
<?php renderFooter(); }

function pageDocumentos(): void {
    renderHead('Documentos');renderHeader();$pubs=getDB()->query("SELECT * FROM publicacoes WHERE publicado=1 ORDER BY publicado_em DESC")->fetchAll();
?>
<main class="max-w-4xl mx-auto px-4 py-12">
    <div class="card p-8 animate-fade-in">
        <h1 class="font-display text-3xl font-bold text-gray-900 mb-8">Documentos e Publicações</h1>
        <?php if(empty($pubs)): ?><p class="text-gray-600 text-center py-12">Nenhuma publicação disponível.</p>
        <?php else: ?><div class="space-y-4"><?php foreach($pubs as $p): ?>
            <div class="border border-gray-200 rounded-xl p-6 hover:border-gov-300">
                <span class="px-3 py-1 bg-gov-100 text-gov-700 rounded-full text-xs font-semibold uppercase"><?= sanitize(str_replace('_',' ',$p['tipo'])) ?></span>
                <h2 class="text-xl font-bold text-gray-900 mt-2"><?= sanitize($p['titulo']) ?></h2>
                <?php if($p['descricao']): ?><p class="text-gray-600 mt-1"><?= sanitize($p['descricao']) ?></p><?php endif; ?>
                <p class="text-sm text-gray-500 mt-2">Publicado em <?= date('d/m/Y H:i',strtotime($p['publicado_em'])) ?></p>
                <?php if($p['arquivo']): ?><a href="documentos/<?= sanitize($p['arquivo']) ?>" target="_blank" class="btn-primary inline-block mt-4 text-sm">📄 Baixar Documento</a><?php endif; ?>
            </div>
        <?php endforeach; ?></div><?php endif; ?>
    </div>
</main>
<?php renderFooter(); }

// ==================== ADMIN: LOGIN, DASHBOARD, INSCRIÇÕES ====================

function pageAdminLogin(): void {
    $r=null;if($_SERVER['REQUEST_METHOD']==='POST'){$r=processarLogin();if($r['success']){header('Location: ?page=admin');exit;}}
    renderHead('Login');
?>
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="card p-8 w-full max-w-md animate-fade-in">
        <div class="text-center mb-8">
            <img src="brasao.png" alt="Brasão" class="w-20 h-20 object-contain mx-auto mb-4" onerror="this.style.display='none'">
            <h1 class="font-display text-2xl font-bold text-gray-900">Área Administrativa</h1>
            <p class="text-gray-500 text-sm mt-1"><?= sanitize(getConfig('titulo_chamada')) ?></p>
        </div>
        <?php if($r&&!$r['success']):renderAlert('error',$r['message']);endif; ?>
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div><label class="block text-sm font-semibold text-gray-700 mb-2">Usuário</label><input type="text" name="usuario" required class="input-field" autofocus></div>
            <div><label class="block text-sm font-semibold text-gray-700 mb-2">Senha</label><input type="password" name="senha" required class="input-field"></div>
            <button type="submit" class="btn-primary w-full">Entrar</button>
        </form>
        <div class="mt-6 text-center"><a href="?" class="text-gov-600 hover:text-gov-800">← Voltar ao site</a></div>
    </div>
</div></body></html>
<?php }

function pageAdmin(): void {
    requireAdmin();$db=getDB();
    $tot=$db->query("SELECT COUNT(*) FROM candidatos")->fetchColumn();
    $pend=$db->query("SELECT COUNT(*) FROM candidatos WHERE status='pendente'")->fetchColumn();
    $hom=$db->query("SELECT COUNT(*) FROM candidatos WHERE status='homologado'")->fetchColumn();
    $ind=$db->query("SELECT COUNT(*) FROM candidatos WHERE status='indeferido'")->fetchColumn();
    $recP=$db->query("SELECT COUNT(*) FROM recursos WHERE status='pendente'")->fetchColumn();
    $porCargo=$db->query("SELECT c.nome,c.carga_horaria,COUNT(ca.id) as total FROM cargos c LEFT JOIN candidatos ca ON c.id=ca.cargo_id WHERE c.ativo=1 GROUP BY c.id")->fetchAll();
    $ultimas=$db->query("SELECT ca.*,cg.nome as cargo_nome FROM candidatos ca JOIN cargos cg ON ca.cargo_id=cg.id ORDER BY ca.inscrito_em DESC LIMIT 5")->fetchAll();
    renderHead('Dashboard');renderHeader(true);
?>
<main class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-8">
        <div><h1 class="font-display text-3xl font-bold text-gray-900">Dashboard</h1><p class="text-gray-600">Bem-vindo(a), <?= sanitize($_SESSION['admin_nome']) ?></p></div>
        <div class="text-right text-sm text-gray-500"><p><?= sanitize(getConfig('titulo_chamada')) ?></p><p><?= date('d/m/Y H:i') ?></p></div>
    </div>
    <div class="grid md:grid-cols-5 gap-6 mb-8">
        <div class="card p-6 text-center"><p class="text-3xl font-bold text-gray-900"><?= $tot ?></p><p class="text-sm text-gray-500">Total</p></div>
        <div class="card p-6 text-center border-l-4 border-yellow-400"><p class="text-3xl font-bold text-yellow-600"><?= $pend ?></p><p class="text-sm text-gray-500">Pendentes</p></div>
        <div class="card p-6 text-center border-l-4 border-green-400"><p class="text-3xl font-bold text-green-600"><?= $hom ?></p><p class="text-sm text-gray-500">Homologados</p></div>
        <div class="card p-6 text-center border-l-4 border-red-400"><p class="text-3xl font-bold text-red-600"><?= $ind ?></p><p class="text-sm text-gray-500">Indeferidos</p></div>
        <div class="card p-6 text-center border-l-4 border-purple-400"><p class="text-3xl font-bold text-purple-600"><?= $recP ?></p><p class="text-sm text-gray-500">Recursos</p></div>
    </div>
    <div class="grid md:grid-cols-2 gap-8">
        <div class="card p-6"><h2 class="font-display text-xl font-bold text-gray-900 mb-4">Inscritos por Cargo</h2>
            <?php foreach($porCargo as $i): ?><div class="flex items-center justify-between py-3 border-b border-gray-100 last:border-0"><span><?= sanitize($i['nome']) ?> (<?= $i['carga_horaria'] ?>h)</span><span class="px-3 py-1 bg-gov-100 text-gov-700 rounded-full font-bold"><?= $i['total'] ?></span></div><?php endforeach; ?>
        </div>
        <div class="card p-6"><h2 class="font-display text-xl font-bold text-gray-900 mb-4">Últimas Inscrições</h2>
            <?php foreach($ultimas as $u): ?>
            <a href="?page=admin_inscricao&id=<?= $u['id'] ?>" class="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50">
                <div><p class="font-semibold"><?= sanitize($u['nome']) ?></p><p class="text-sm text-gray-500"><?= sanitize($u['cargo_nome']) ?> • <?= date('d/m H:i',strtotime($u['inscrito_em'])) ?></p></div>
                <span class="status-badge text-xs <?= statusBadgeClass($u['status']) ?>"><?= ucfirst($u['status']) ?></span>
            </a><?php endforeach; ?>
            <a href="?page=admin_inscricoes" class="block mt-4 text-gov-600 font-semibold text-sm text-center">Ver todas →</a>
        </div>
    </div>
    <div class="card p-6 mt-8"><h2 class="font-display text-xl font-bold text-gray-900 mb-4">Ações Rápidas</h2>
        <div class="grid md:grid-cols-5 gap-4">
            <a href="?page=admin_inscricoes" class="p-4 border-2 border-gray-200 rounded-xl hover:border-gov-300 text-center font-semibold">📋 Inscrições</a>
            <a href="?page=admin_recursos" class="p-4 border-2 border-gray-200 rounded-xl hover:border-gov-300 text-center font-semibold">📝 Recursos</a>
            <a href="?page=admin_publicacoes" class="p-4 border-2 border-gray-200 rounded-xl hover:border-gov-300 text-center font-semibold">📢 Publicações</a>
            <a href="?page=admin_cargos" class="p-4 border-2 border-gray-200 rounded-xl hover:border-gov-300 text-center font-semibold">👔 Cargos</a>
            <a href="?page=admin_config" class="p-4 border-2 border-gray-200 rounded-xl hover:border-gov-300 text-center font-semibold">⚙️ Config</a>
        </div>
    </div>
</main>
<?php renderFooter(); }

function pageAdminInscricoes(): void {
    requireAdmin();
    if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['acao_lote'])&&validateCSRFToken($_POST['csrf_token']??'')){
        $db=getDB();$ids=$_POST['candidatos']??[];$acao=$_POST['acao_lote'];
        if(!empty($ids)&&in_array($acao,['homologar','indeferir','pendente'])){
            $st=match($acao){'homologar'=>'homologado','indeferir'=>'indeferido',default=>'pendente'};
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $db->prepare("UPDATE candidatos SET status=?,homologado_em=datetime('now'),homologado_por=? WHERE id IN ($ph)")->execute(array_merge([$st,$_SESSION['admin_id']],$ids));
            if($st==='homologado')classificarCandidatos();logAction('acao_lote',"$acao: ".implode(',',$ids));
        }
    }
    $db=getDB();$fS=$_GET['status']??'';$fC=$_GET['cargo']??'';$fB=$_GET['busca']??'';
    $w="1=1";$p=[];
    if($fS){$w.=" AND ca.status=?";$p[]=$fS;}if($fC){$w.=" AND ca.cargo_id=?";$p[]=$fC;}
    if($fB){$w.=" AND (ca.nome LIKE ? OR ca.cpf LIKE ? OR ca.protocolo LIKE ?)";$b="%$fB%";$p=array_merge($p,[$b,$b,$b]);}
    $s=$db->prepare("SELECT ca.*,cg.nome as cargo_nome,cg.carga_horaria FROM candidatos ca JOIN cargos cg ON ca.cargo_id=cg.id WHERE $w ORDER BY ca.inscrito_em DESC");$s->execute($p);$cands=$s->fetchAll();
    $cargos=$db->query("SELECT * FROM cargos WHERE ativo=1")->fetchAll();
    renderHead('Inscrições');renderHeader(true);
?>
<main class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-8">
        <h1 class="font-display text-3xl font-bold text-gray-900">Gerenciar Inscrições</h1>
        <div class="flex space-x-4"><a href="?page=admin_exportar" class="btn-secondary">📥 CSV</a><a href="?page=admin_classificar" onclick="return confirm('Recalcular?')" class="btn-secondary">🔄 Recalcular</a></div>
    </div>
    <div class="card p-6 mb-6">
        <form method="GET" class="grid md:grid-cols-4 gap-4">
            <input type="hidden" name="page" value="admin_inscricoes">
            <div><label class="block text-sm font-semibold mb-2">Buscar</label><input type="text" name="busca" class="input-field" value="<?= sanitize($fB) ?>" placeholder="Nome, CPF ou Protocolo"></div>
            <div><label class="block text-sm font-semibold mb-2">Status</label><select name="status" class="input-field"><option value="">Todos</option><option value="pendente" <?= $fS==='pendente'?'selected':'' ?>>Pendente</option><option value="homologado" <?= $fS==='homologado'?'selected':'' ?>>Homologado</option><option value="indeferido" <?= $fS==='indeferido'?'selected':'' ?>>Indeferido</option><option value="cancelado" <?= $fS==='cancelado'?'selected':'' ?>>Cancelado</option></select></div>
            <div><label class="block text-sm font-semibold mb-2">Cargo</label><select name="cargo" class="input-field"><option value="">Todos</option><?php foreach($cargos as $c): ?><option value="<?= $c['id'] ?>" <?= $fC==$c['id']?'selected':'' ?>><?= sanitize($c['nome']) ?> (<?= $c['carga_horaria'] ?>h)</option><?php endforeach; ?></select></div>
            <div class="flex items-end gap-2"><button type="submit" class="btn-primary flex-1">Filtrar</button><a href="?page=admin_inscricoes" class="btn-secondary">Limpar</a></div>
        </form>
    </div>
    <div class="card overflow-hidden">
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="p-4 bg-gray-50 border-b flex items-center justify-between">
                <label class="flex items-center"><input type="checkbox" id="sel-all" class="w-5 h-5 mr-2 rounded"><span class="text-sm font-semibold">Selecionar todos</span></label>
                <div class="flex items-center space-x-2"><select name="acao_lote" class="input-field py-2 text-sm"><option value="">Ação...</option><option value="homologar">✓ Homologar</option><option value="indeferir">✗ Indeferir</option><option value="pendente">⏳ Pendente</option></select><button type="submit" class="btn-secondary py-2 text-sm">Aplicar</button></div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full"><thead><tr class="border-b bg-gray-50 text-sm"><th class="py-3 px-4 w-12"></th><th class="py-3 px-4 text-left">Protocolo</th><th class="py-3 px-4 text-left">Nome</th><th class="py-3 px-4 text-left">CPF</th><th class="py-3 px-4 text-left">Cargo</th><th class="py-3 px-4 text-center">Pts</th><th class="py-3 px-4 text-center">Class.</th><th class="py-3 px-4 text-center">Status</th><th class="py-3 px-4 text-center">Ações</th></tr></thead><tbody>
                <?php foreach($cands as $c): ?>
                <tr class="border-b hover:bg-gov-50/50"><td class="py-3 px-4"><input type="checkbox" name="candidatos[]" value="<?= $c['id'] ?>" class="cand-cb w-5 h-5 rounded"></td><td class="py-3 px-4 font-mono text-sm text-gov-600"><?= sanitize($c['protocolo']) ?></td><td class="py-3 px-4 font-medium"><?= sanitize($c['nome']) ?></td><td class="py-3 px-4 text-sm"><?= formatarCPF($c['cpf']) ?></td><td class="py-3 px-4 text-sm"><?= sanitize($c['cargo_nome']) ?> (<?= $c['carga_horaria'] ?>h)</td><td class="py-3 px-4 text-center font-bold text-gov-600"><?= number_format($c['pontuacao'],2,',','.') ?></td><td class="py-3 px-4 text-center"><?= $c['classificacao']>0?"<span class='font-bold text-green-600'>{$c['classificacao']}º</span>":'-' ?></td><td class="py-3 px-4 text-center"><span class="status-badge text-xs <?= statusBadgeClass($c['status']) ?>"><?= ucfirst($c['status']) ?></span></td><td class="py-3 px-4 text-center"><a href="?page=admin_inscricao&id=<?= $c['id'] ?>" class="text-gov-600 hover:text-gov-800 font-semibold text-sm">Ver</a></td></tr>
                <?php endforeach; ?></tbody></table>
            </div>
            <?php if(empty($cands)): ?><div class="p-12 text-center text-gray-500">Nenhuma inscrição.</div><?php endif; ?>
            <div class="p-4 bg-gray-50 border-t text-sm text-gray-600">Total: <?= count($cands) ?></div>
        </form>
    </div>
</main>
<script>document.getElementById('sel-all').addEventListener('change',function(){document.querySelectorAll('.cand-cb').forEach(cb=>cb.checked=this.checked);});</script>
<?php renderFooter(); }

// ==================== ADMIN: DETALHE INSCRIÇÃO (DOCS POR CATEGORIA + IP) ====================

function pageAdminInscricao(): void {
    requireAdmin();$id=(int)($_GET['id']??0);$db=getDB();
    $msgAjuste = null;
    if($_SERVER['REQUEST_METHOD']==='POST'&&validateCSRFToken($_POST['csrf_token']??'')){
        $acao = $_POST['acao_admin'] ?? 'status';
        
        if ($acao === 'ajustar_pontuacao') {
            // Ajuste de pontuação pelo admin
            $novaTit = sanitize($_POST['adj_titulacao'] ?? 'nenhuma');
            $novoTS = max(0, (int)($_POST['adj_tempo_servico_meses'] ?? 0));
            $novoCH = max(0, (int)($_POST['adj_carga_horaria_cursos'] ?? 0));
            $justificativa = sanitize($_POST['adj_justificativa'] ?? '');
            
            if (empty($justificativa) || strlen($justificativa) < 5) {
                $msgAjuste = ['tipo'=>'error','msg'=>'Informe uma justificativa para o ajuste (mín. 5 caracteres).'];
            } else {
                // Buscar dados antigos para log
                $old = $db->prepare("SELECT titulacao,tempo_servico_meses,carga_horaria_cursos,pontuacao FROM candidatos WHERE id=?");
                $old->execute([$id]); $oldData = $old->fetch();
                
                // Atualizar dados
                $db->prepare("UPDATE candidatos SET titulacao=?, tempo_servico_meses=?, carga_horaria_cursos=? WHERE id=?")->execute([$novaTit, $novoTS, $novoCH, $id]);
                
                // Buscar cargo para recalcular
                $cInfo = $db->prepare("SELECT cargo_id FROM candidatos WHERE id=?"); $cInfo->execute([$id]); $cRow = $cInfo->fetch();
                $novaPont = calcularPontuacao(['cargo_id'=>$cRow['cargo_id'],'titulacao'=>$novaTit,'tempo_servico_meses'=>$novoTS,'carga_horaria_cursos'=>$novoCH]);
                $db->prepare("UPDATE candidatos SET pontuacao=? WHERE id=?")->execute([$novaPont, $id]);
                
                // Registrar ajuste nas observações
                $obsAtual = $db->prepare("SELECT observacoes FROM candidatos WHERE id=?"); $obsAtual->execute([$id]); $obsRow = $obsAtual->fetch();
                $dataAjuste = date('d/m/Y H:i');
                $adminNome = $_SESSION['admin_user'] ?? 'Admin';
                $logAjuste = "[Ajuste de Pontuação em $dataAjuste por $adminNome]\n";
                $logAjuste .= "De: Titulação={$oldData['titulacao']}, TS={$oldData['tempo_servico_meses']}m, CH={$oldData['carga_horaria_cursos']}h, Pts={$oldData['pontuacao']}\n";
                $logAjuste .= "Para: Titulação=$novaTit, TS={$novoTS}m, CH={$novoCH}h, Pts=$novaPont\n";
                $logAjuste .= "Justificativa: $justificativa\n---\n";
                $novaObs = $logAjuste . ($obsRow['observacoes'] ?? '');
                $db->prepare("UPDATE candidatos SET observacoes=? WHERE id=?")->execute([$novaObs, $id]);
                
                // Reclassificar
                classificarCandidatos();
                logAction('ajuste_pontuacao', "ID:$id Tit:$novaTit TS:{$novoTS}m CH:{$novoCH}h Pts:$novaPont Justif:$justificativa");
                $msgAjuste = ['tipo'=>'success','msg'=>"Pontuação ajustada: {$oldData['pontuacao']} → $novaPont pts. Classificação recalculada."];
            }
        } else {
            // Alteração de status (original)
            $ns=sanitize($_POST['status']??'');$obs=sanitize($_POST['observacoes']??'');
            if(in_array($ns,['pendente','homologado','indeferido','cancelado'])){
                $db->prepare("UPDATE candidatos SET status=?,observacoes=?,homologado_em=datetime('now'),homologado_por=? WHERE id=?")->execute([$ns,$obs,$_SESSION['admin_id'],$id]);
                if($ns==='homologado'||$ns==='cancelado')classificarCandidatos();logAction('alterar_status',"ID:$id Status:$ns");
            }
        }
    }
    $s=$db->prepare("SELECT ca.*,cg.nome as cargo_nome,cg.carga_horaria,cg.vencimento,cg.tipo as cargo_tipo FROM candidatos ca JOIN cargos cg ON ca.cargo_id=cg.id WHERE ca.id=?");$s->execute([$id]);$c=$s->fetch();
    if(!$c){header('Location:?page=admin_inscricoes');exit;}
    $docs=$db->query("SELECT * FROM documentos WHERE candidato_id=$id ORDER BY tipo,enviado_em")->fetchAll();
    $recs=$db->query("SELECT * FROM recursos WHERE candidato_id=$id ORDER BY interposto_em DESC")->fetchAll();
    $tempos=$db->query("SELECT * FROM tempo_servico_periodos WHERE candidato_id=$id ORDER BY data_inicio")->fetchAll();
    
    // Agrupar documentos por categoria
    $cats=getDocCategories();$docsPorCat=[];foreach($cats as $k=>$v)$docsPorCat[$k]=[];
    foreach($docs as $d){if(isset($docsPorCat[$d['tipo']]))$docsPorCat[$d['tipo']][]=$d;}
    
    renderHead('Inscrição #'.$c['protocolo']);renderHeader(true);
?>
<main class="max-w-5xl mx-auto px-4 py-8">
    <a href="?page=admin_inscricoes" class="inline-flex items-center text-gov-600 hover:text-gov-800 mb-6">← Voltar</a>
    <div class="card p-8">
        <div class="flex items-start justify-between mb-8">
            <div>
                <span class="px-3 py-1 bg-gov-100 text-gov-700 rounded-full text-sm font-semibold font-mono"><?= sanitize($c['protocolo']) ?></span>
                <h1 class="font-display text-3xl font-bold text-gray-900 mt-2"><?= sanitize($c['nome']) ?></h1>
                <p class="text-gray-600"><?= sanitize($c['cargo_nome']) ?> (<?= $c['carga_horaria'] ?>h) — R$ <?= number_format($c['vencimento'],2,',','.') ?></p>
            </div>
            <span class="status-badge text-lg <?= statusBadgeClass($c['status']) ?>"><?= ucfirst($c['status']) ?></span>
        </div>
        
        <div class="grid md:grid-cols-2 gap-8 mb-8">
            <div>
                <h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Dados Pessoais</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">CPF</dt><dd class="font-medium"><?= formatarCPF($c['cpf']) ?></dd></div>
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">RG</dt><dd class="font-medium"><?= sanitize($c['rg']) ?></dd></div>
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">Nascimento</dt><dd class="font-medium"><?= date('d/m/Y',strtotime($c['data_nascimento'])) ?></dd></div>
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">Estado Civil</dt><dd class="font-medium"><?= ucfirst(str_replace('_',' ',$c['estado_civil'])) ?></dd></div>
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">E-mail</dt><dd class="font-medium"><?= sanitize($c['email']) ?></dd></div>
                    <div class="flex justify-between py-2"><dt class="text-gray-500">Telefone</dt><dd class="font-medium"><?= sanitize($c['telefone']) ?></dd></div>
                </dl>
                <h3 class="font-bold text-gray-900 mt-6 mb-2">Endereço</h3>
                <p class="text-gray-700 text-sm"><?= sanitize($c['logradouro']) ?>, <?= sanitize($c['numero']) ?><br><?= sanitize($c['bairro']) ?> — <?= sanitize($c['cidade']) ?>/<?= sanitize($c['estado']) ?><br>CEP: <?= sanitize($c['cep']) ?></p>
                <h3 class="font-bold text-gray-900 mt-6 mb-2">Declarações</h3>
                <p class="text-sm"><?= $c['declaracao_parentesco']?'✅':'❌' ?> Parentesco &nbsp; <?= $c['declaracao_nao_participou']?'✅':'❌' ?> Não participou chamada anterior</p>
                <h3 class="font-bold text-gray-900 mt-6 mb-2">Registro</h3>
                <p class="text-sm text-gray-600">Data/Hora: <?= date('d/m/Y H:i:s',strtotime($c['inscrito_em'])) ?></p>
                <p class="text-sm text-gray-600">IP: <?= sanitize($c['ip_inscricao']??'N/D') ?></p>
            </div>
            <div>
                <h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Pontuação</h2>
                
                <?php if ($msgAjuste): ?>
                    <div class="mb-4 p-3 rounded-lg text-sm font-medium <?= $msgAjuste['tipo']==='success'?'bg-green-50 text-green-800 border border-green-200':'bg-red-50 text-red-800 border border-red-200' ?>">
                        <?= $msgAjuste['tipo']==='success'?'✓':'✗' ?> <?= $msgAjuste['msg'] ?>
                    </div>
                <?php endif; ?>
                
                <!-- Valores atuais (declarados pelo candidato / ajustados pelo admin) -->
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">Titulação/Formação</dt><dd class="font-medium"><?= getTitulacaoLabel($c['titulacao'], $c['cargo_tipo']) ?></dd></div>
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">Tempo de Serviço</dt><dd class="font-medium"><?= $c['tempo_servico_meses'] ?> meses — <?= number_format(min(35, round($c['tempo_servico_meses']/12, 2)), 2, ',', '.') ?> pts</dd></div>
                    <div class="flex justify-between py-2 border-b border-gray-100"><dt class="text-gray-500">CH Cursos</dt><dd class="font-medium"><?= $c['carga_horaria_cursos'] ?>h — <?= number_format(min(20, min(400,$c['carga_horaria_cursos'])*0.05),1) ?> pts</dd></div>
                    <div class="flex justify-between py-3 bg-gov-50 rounded px-3 mt-2"><dt class="text-gov-700 font-semibold">PONTUAÇÃO TOTAL</dt><dd class="font-bold text-gov-700 text-xl"><?= number_format($c['pontuacao'],2,',','.') ?> pts</dd></div>
                    <?php if($c['classificacao']>0): ?><div class="flex justify-between py-3 bg-green-50 rounded px-3"><dt class="text-green-700 font-semibold">CLASSIFICAÇÃO</dt><dd class="font-bold text-green-700 text-xl"><?= $c['classificacao'] ?>º</dd></div><?php endif; ?>
                </dl>
                
                <!-- Botão para abrir painel de ajuste -->
                <button type="button" onclick="document.getElementById('painel-ajuste').classList.toggle('hidden')" class="mt-4 w-full text-left px-4 py-3 bg-amber-50 border-2 border-amber-200 rounded-xl hover:bg-amber-100 transition-colors text-sm font-semibold text-amber-800 flex items-center justify-between">
                    <span>✏️ Ajustar Pontuação Manualmente</span>
                    <span class="text-amber-400">▼</span>
                </button>
                
                <!-- Painel de ajuste (escondido por padrão) -->
                <div id="painel-ajuste" class="hidden mt-4 border-2 border-amber-300 rounded-xl overflow-hidden">
                    <div class="bg-amber-50 px-4 py-3 border-b border-amber-200">
                        <h3 class="font-bold text-amber-900 text-sm">Ajuste Manual de Dados para Pontuação</h3>
                        <p class="text-xs text-amber-700 mt-1">Altere os campos conforme verificação dos documentos. A pontuação será recalculada automaticamente. Todas as alterações ficam registradas nas observações.</p>
                    </div>
                    <form method="POST" class="p-4 space-y-4 bg-white">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="acao_admin" value="ajustar_pontuacao">
                        
                        <!-- Titulação/Formação -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <?= $c['cargo_tipo']==='professor' ? 'Titulação Acadêmica' : 'Formação na Área de Pedagogia' ?>
                            </label>
                            <?php if ($c['cargo_tipo'] === 'professor'): ?>
                            <select name="adj_titulacao" class="input-field" onchange="previaAjuste()">
                                <option value="nenhuma" <?= $c['titulacao']==='nenhuma'?'selected':'' ?>>Nenhuma — 0 pts</option>
                                <option value="especializacao" <?= $c['titulacao']==='especializacao'?'selected':'' ?>>Pós-graduação lato sensu — 20 pts</option>
                                <option value="mestrado" <?= $c['titulacao']==='mestrado'?'selected':'' ?>>Mestrado — 30 pts</option>
                                <option value="doutorado" <?= $c['titulacao']==='doutorado'?'selected':'' ?>>Doutorado — 45 pts</option>
                            </select>
                            <?php else: ?>
                            <select name="adj_titulacao" class="input-field" onchange="previaAjuste()">
                                <option value="nenhuma" <?= $c['titulacao']==='nenhuma'?'selected':'' ?>>Nenhuma — 0 pts</option>
                                <option value="cursando_pedagogia" <?= $c['titulacao']==='cursando_pedagogia'?'selected':'' ?>>Cursando Pedagogia — 25 pts</option>
                                <option value="pedagogia" <?= $c['titulacao']==='pedagogia'?'selected':'' ?>>Pedagogia concluída — 30 pts</option>
                                <option value="pedagogia_pos" <?= $c['titulacao']==='pedagogia_pos'?'selected':'' ?>>Pedagogia + Pós-graduação — 45 pts</option>
                            </select>
                            <?php endif; ?>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Tempo de Serviço -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Tempo de Serviço (meses)</label>
                                <input type="number" name="adj_tempo_servico_meses" min="0" max="600" class="input-field" value="<?= $c['tempo_servico_meses'] ?>" oninput="previaAjuste()">
                                <p class="text-xs text-gray-500 mt-1">1 pt/ano completo, máx. 35 pts</p>
                            </div>
                            
                            <!-- CH Cursos -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">CH Cursos (horas)</label>
                                <input type="number" name="adj_carga_horaria_cursos" min="0" max="9999" class="input-field" value="<?= $c['carga_horaria_cursos'] ?>" oninput="previaAjuste()">
                                <p class="text-xs text-gray-500 mt-1">0,05 pt/h, máx. 400h (20 pts)</p>
                            </div>
                        </div>
                        
                        <!-- Prévia -->
                        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm" id="previa-ajuste">
                            <span class="text-blue-700">Nova pontuação estimada: <strong id="adj-prev-total"><?= number_format($c['pontuacao'],2,',','.') ?></strong> pts</span>
                            <span class="text-blue-500 text-xs ml-2">(atual: <?= number_format($c['pontuacao'],2,',','.') ?>)</span>
                        </div>
                        
                        <!-- Justificativa obrigatória -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Justificativa do Ajuste <span class="text-red-500">*</span></label>
                            <textarea name="adj_justificativa" required rows="3" class="input-field" placeholder="Ex: Documentação de mestrado verificada nos anexos, candidato havia declarado apenas especialização."></textarea>
                            <p class="text-xs text-gray-500 mt-1">Obrigatório. Será registrado nas observações da inscrição.</p>
                        </div>
                        
                        <button type="submit" onclick="return confirm('Confirma o ajuste manual de pontuação? Esta ação será registrada.')" class="btn-primary bg-amber-600 hover:bg-amber-700 w-full">💾 Salvar Ajuste de Pontuação</button>
                    </form>
                </div>
                
                <script>
                function previaAjuste() {
                    const tit = document.querySelector('select[name="adj_titulacao"]').value;
                    const ts = parseInt(document.querySelector('input[name="adj_tempo_servico_meses"]').value) || 0;
                    const ch = parseInt(document.querySelector('input[name="adj_carga_horaria_cursos"]').value) || 0;
                    const tipo = '<?= $c['cargo_tipo'] ?>';
                    
                    let ptsTit = 0;
                    if (tipo === 'professor') {
                        ptsTit = {'doutorado':45,'mestrado':30,'especializacao':20}[tit] || 0;
                    } else {
                        ptsTit = {'pedagogia_pos':45,'pedagogia':30,'cursando_pedagogia':25}[tit] || 0;
                    }
                    const ptsTS = Math.min(35, Math.round((ts / 12) * 100) / 100);
                    const ptsCH = Math.min(20, Math.min(400, ch) * 0.05);
                    const total = ptsTit + ptsTS + ptsCH;
                    
                    document.getElementById('adj-prev-total').textContent = total.toFixed(1);
                }
                </script>
            </div>
        </div>
        
        <?php if(!empty($tempos)): ?>
        <div class="mb-8">
            <h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Tempo de Serviço Declarado</h2>
            <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b bg-gray-50"><th class="py-2 px-3 text-left">Período</th><th class="py-2 px-3 text-left">Local de Trabalho</th><th class="py-2 px-3 text-left">Função Exercida</th><th class="py-2 px-3 text-center">Meses</th></tr></thead><tbody>
                <?php $tot=0;foreach($tempos as $ts):$tot+=$ts['meses']; ?>
                <tr class="border-b"><td class="py-2 px-3 whitespace-nowrap"><?= date('d/m/Y',strtotime($ts['data_inicio'])) ?> a <?= date('d/m/Y',strtotime($ts['data_fim'])) ?></td><td class="py-2 px-3"><?= sanitize($ts['local_trabalho']) ?></td><td class="py-2 px-3"><?= sanitize($ts['funcao']) ?></td><td class="py-2 px-3 text-center font-semibold"><?= $ts['meses'] ?></td></tr>
                <?php endforeach; ?>
                <tr class="bg-gray-50 font-bold"><td colspan="3" class="py-2 px-3 text-right">Total:</td><td class="py-2 px-3 text-center"><?= $tot ?> meses</td></tr>
            </tbody></table></div>
        </div>
        <?php endif; ?>
        
        <!-- DOCUMENTOS AGRUPADOS POR CATEGORIA -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-4 border-b pb-2">
                <h2 class="font-bold text-xl text-gray-900">Documentos Anexados</h2>
                <?php if(!empty($docs)): ?>
                <a href="?page=admin_download_docs&id=<?= $c['id'] ?>" class="btn-secondary text-sm py-2 px-4 flex items-center gap-2">
                    📦 Baixar todos (.zip)
                </a>
                <?php endif; ?>
            </div>
            <div class="space-y-4">
                <?php $temDoc=false; foreach($docsPorCat as $tipo=>$lista): if(empty($lista)) continue; $temDoc=true; $info=$cats[$tipo]; ?>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="bg-gray-50 px-4 py-3 border-b">
                        <h3 class="font-semibold text-gray-900 flex items-center">
                            <span class="text-xl mr-2"><?= $info['icone'] ?></span>
                            <?= $info['nome'] ?>
                            <span class="ml-2 px-2 py-0.5 bg-gov-100 text-gov-700 rounded-full text-xs font-bold"><?= count($lista) ?></span>
                        </h3>
                    </div>
                    <div class="p-4 grid md:grid-cols-2 gap-3">
                        <?php foreach($lista as $d): ?>
                        <a href="uploads/<?= sanitize($d['nome_arquivo']) ?>" target="_blank" class="flex items-center p-3 border rounded-lg hover:border-gov-300 hover:bg-gov-50 transition-colors">
                            <span class="text-2xl mr-3">📄</span>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-sm truncate" title="<?= sanitize($d['nome_original']) ?>"><?= sanitize($d['nome_original']) ?></p>
                                <p class="text-xs text-gray-500"><?= number_format($d['tamanho']/1024,1) ?> KB • <?= date('d/m/Y H:i',strtotime($d['enviado_em'])) ?></p>
                            </div>
                            <span class="text-gov-600 text-sm font-semibold ml-2">Abrir ↗</span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(!$temDoc): ?><p class="text-gray-500 text-center py-8">Nenhum documento anexado.</p><?php endif; ?>
            </div>
        </div>
        
        <?php if(!empty($recs)): ?>
        <div class="mb-8"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Recursos</h2>
            <div class="space-y-3"><?php foreach($recs as $r):
                $docsRec=$db->query("SELECT * FROM documentos_recurso WHERE recurso_id={$r['id']} ORDER BY enviado_em")->fetchAll();
            ?>
                <div class="border rounded-lg p-4">
                    <div class="flex items-center justify-between mb-2"><span class="font-mono text-sm"><?= sanitize($r['protocolo_recurso']) ?></span><span class="status-badge text-xs <?= $r['status']==='deferido'?'bg-green-100 text-green-800':($r['status']==='indeferido'?'bg-red-100 text-red-800':'bg-yellow-100 text-yellow-800') ?>"><?= ucfirst($r['status']) ?></span></div>
                    <p class="text-sm"><strong>Tipo:</strong> <?= ucfirst($r['tipo']) ?></p>
                    <p class="text-sm text-gray-600 mt-1"><?= nl2br(sanitize($r['fundamentacao'])) ?></p>
                    <?php if(!empty($docsRec)): ?>
                    <div class="mt-3 space-y-1">
                        <p class="text-xs font-semibold text-gray-500 uppercase">Documentos do Recurso:</p>
                        <?php foreach($docsRec as $dr): ?>
                        <a href="uploads/<?= sanitize($dr['nome_arquivo']) ?>" target="_blank" class="inline-flex items-center text-sm text-gov-600 hover:text-gov-800 mr-4">📄 <?= sanitize($dr['nome_original']) ?> (<?= number_format($dr['tamanho']/1024,1) ?> KB) ↗</a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if($r['resposta']): ?><div class="mt-2 p-2 bg-gray-50 rounded text-sm"><strong>Resposta:</strong> <?= nl2br(sanitize($r['resposta'])) ?></div><?php endif; ?>
                    <?php if($r['status']==='pendente'): ?><a href="?page=admin_recurso_responder&id=<?= $r['id'] ?>" class="inline-block mt-2 text-gov-600 font-semibold text-sm">Analisar →</a><?php endif; ?>
                </div>
            <?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        
        <div class="border-t-2 pt-8">
            <h2 class="font-bold text-xl text-gray-900 mb-4">Alterar Status</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="acao_admin" value="status">
                <div class="grid md:grid-cols-4 gap-4">
                    <label class="flex items-center p-4 border-2 rounded-xl cursor-pointer hover:border-yellow-300 <?= $c['status']==='pendente'?'border-yellow-400 bg-yellow-50':'border-gray-200' ?>"><input type="radio" name="status" value="pendente" <?= $c['status']==='pendente'?'checked':'' ?> class="w-5 h-5 text-yellow-600"><span class="ml-3 font-semibold">⏳ Pendente</span></label>
                    <label class="flex items-center p-4 border-2 rounded-xl cursor-pointer hover:border-green-300 <?= $c['status']==='homologado'?'border-green-400 bg-green-50':'border-gray-200' ?>"><input type="radio" name="status" value="homologado" <?= $c['status']==='homologado'?'checked':'' ?> class="w-5 h-5 text-green-600"><span class="ml-3 font-semibold">✓ Homologado</span></label>
                    <label class="flex items-center p-4 border-2 rounded-xl cursor-pointer hover:border-red-300 <?= $c['status']==='indeferido'?'border-red-400 bg-red-50':'border-gray-200' ?>"><input type="radio" name="status" value="indeferido" <?= $c['status']==='indeferido'?'checked':'' ?> class="w-5 h-5 text-red-600"><span class="ml-3 font-semibold">✗ Indeferido</span></label>
                    <label class="flex items-center p-4 border-2 rounded-xl cursor-pointer hover:border-gray-400 <?= $c['status']==='cancelado'?'border-gray-500 bg-gray-100':'border-gray-200' ?>"><input type="radio" name="status" value="cancelado" <?= $c['status']==='cancelado'?'checked':'' ?> class="w-5 h-5 text-gray-500"><span class="ml-3 font-semibold">🚫 Cancelado</span></label>
                </div>
                <div><label class="block text-sm font-semibold mb-2">Observações</label><textarea name="observacoes" rows="3" class="input-field"><?= sanitize($c['observacoes']??'') ?></textarea></div>
                <button type="submit" class="btn-primary">Salvar</button>
            </form>
        </div>
    </div>
</main>
<?php renderFooter(); }

// ==================== ADMIN: RECURSOS, PUBLICAÇÕES ====================

function pageAdminRecursos(): void {
    requireAdmin();$recs=getDB()->query("SELECT r.*,c.nome,c.protocolo FROM recursos r JOIN candidatos c ON r.candidato_id=c.id ORDER BY r.status='pendente' DESC,r.interposto_em DESC")->fetchAll();
    renderHead('Recursos');renderHeader(true);
?>
<main class="max-w-6xl mx-auto px-4 py-8">
    <h1 class="font-display text-3xl font-bold text-gray-900 mb-8">Gerenciar Recursos</h1>
    <div class="card overflow-hidden">
        <table class="w-full"><thead><tr class="border-b bg-gray-50 text-sm"><th class="py-3 px-4 text-left">Protocolo Recurso</th><th class="py-3 px-4 text-left">Candidato</th><th class="py-3 px-4 text-left">Prot. Inscrição</th><th class="py-3 px-4 text-left">Tipo</th><th class="py-3 px-4 text-left">Data</th><th class="py-3 px-4 text-center">Status</th><th class="py-3 px-4 text-center">Ações</th></tr></thead><tbody>
        <?php foreach($recs as $r): ?>
        <tr class="border-b hover:bg-gov-50/50"><td class="py-3 px-4 font-mono text-sm text-gov-600"><?= sanitize($r['protocolo_recurso']) ?></td><td class="py-3 px-4 font-medium"><?= sanitize($r['nome']) ?></td><td class="py-3 px-4 font-mono text-sm"><?= sanitize($r['protocolo']) ?></td><td class="py-3 px-4 text-sm"><?= ucfirst($r['tipo']) ?></td><td class="py-3 px-4 text-sm"><?= date('d/m/Y H:i',strtotime($r['interposto_em'])) ?></td><td class="py-3 px-4 text-center"><span class="status-badge text-xs <?= $r['status']==='deferido'?'bg-green-100 text-green-800':($r['status']==='indeferido'?'bg-red-100 text-red-800':'bg-yellow-100 text-yellow-800') ?>"><?= ucfirst($r['status']) ?></span></td><td class="py-3 px-4 text-center"><a href="?page=admin_recurso_responder&id=<?= $r['id'] ?>" class="text-gov-600 font-semibold text-sm"><?= $r['status']==='pendente'?'Analisar':'Ver' ?></a></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php if(empty($recs)): ?><div class="p-12 text-center text-gray-500">Nenhum recurso.</div><?php endif; ?>
    </div>
</main>
<?php renderFooter(); }

function pageAdminRecursoResponder(): void {
    requireAdmin();$id=(int)($_GET['id']??0);$db=getDB();
    if($_SERVER['REQUEST_METHOD']==='POST'&&validateCSRFToken($_POST['csrf_token']??'')){
        $st=sanitize($_POST['status']??'');$resp=sanitize($_POST['resposta']??'');
        if(in_array($st,['deferido','indeferido'])&&!empty($resp)){
            $db->prepare("UPDATE recursos SET status=?,resposta=?,analisado_em=datetime('now'),analisado_por=? WHERE id=?")->execute([$st,$resp,$_SESSION['admin_id'],$id]);
            logAction('responder_recurso',"ID:$id Status:$st");header('Location:?page=admin_recursos');exit;
        }
    }
    $s=$db->prepare("SELECT r.*,c.nome,c.protocolo,c.id as cand_id FROM recursos r JOIN candidatos c ON r.candidato_id=c.id WHERE r.id=?");$s->execute([$id]);$r=$s->fetch();
    if(!$r){header('Location:?page=admin_recursos');exit;}
    $docsRecurso=$db->query("SELECT * FROM documentos_recurso WHERE recurso_id=$id ORDER BY enviado_em")->fetchAll();
    renderHead('Recurso');renderHeader(true);
?>
<main class="max-w-3xl mx-auto px-4 py-8">
    <a href="?page=admin_recursos" class="inline-flex items-center text-gov-600 mb-6">← Voltar</a>
    <div class="card p-8">
        <div class="flex items-center justify-between mb-6"><h1 class="font-display text-2xl font-bold text-gray-900">Analisar Recurso</h1><span class="status-badge <?= $r['status']==='deferido'?'bg-green-100 text-green-800':($r['status']==='indeferido'?'bg-red-100 text-red-800':'bg-yellow-100 text-yellow-800') ?>"><?= ucfirst($r['status']) ?></span></div>
        <div class="bg-gray-50 rounded-xl p-6 mb-8">
            <div class="grid md:grid-cols-2 gap-4 mb-4">
                <div><span class="text-sm text-gray-500">Prot. Recurso</span><p class="font-mono font-semibold text-gov-600"><?= sanitize($r['protocolo_recurso']) ?></p></div>
                <div><span class="text-sm text-gray-500">Candidato</span><p class="font-semibold"><?= sanitize($r['nome']) ?></p></div>
                <div><span class="text-sm text-gray-500">Prot. Inscrição</span><p class="font-mono"><?= sanitize($r['protocolo']) ?></p></div>
                <div><span class="text-sm text-gray-500">Tipo</span><p class="font-semibold"><?= ucfirst($r['tipo']) ?></p></div>
            </div>
            <div><span class="text-sm text-gray-500">Fundamentação</span><p class="mt-2 p-4 bg-white rounded-lg border"><?= nl2br(sanitize($r['fundamentacao'])) ?></p></div>
            
            <?php if(!empty($docsRecurso)): ?>
            <div class="mt-4">
                <span class="text-sm text-gray-500">Documentos Anexados ao Recurso</span>
                <div class="grid md:grid-cols-2 gap-3 mt-2">
                    <?php foreach($docsRecurso as $dr): ?>
                    <a href="uploads/<?= sanitize($dr['nome_arquivo']) ?>" target="_blank" class="flex items-center p-3 bg-white border rounded-lg hover:border-gov-300 hover:bg-gov-50 transition-colors">
                        <span class="text-2xl mr-3">📄</span>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-sm truncate" title="<?= sanitize($dr['nome_original']) ?>"><?= sanitize($dr['nome_original']) ?></p>
                            <p class="text-xs text-gray-500"><?= number_format($dr['tamanho']/1024,1) ?> KB • <?= date('d/m/Y H:i',strtotime($dr['enviado_em'])) ?></p>
                        </div>
                        <span class="text-gov-600 text-sm font-semibold ml-2">Abrir ↗</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="mb-4"><a href="?page=admin_inscricao&id=<?= $r['cand_id'] ?>" class="text-gov-600 font-semibold text-sm">Ver inscrição →</a></div>
        <?php if($r['status']==='pendente'): ?>
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div><label class="block text-sm font-semibold mb-3">Decisão *</label>
                <div class="grid md:grid-cols-2 gap-4">
                    <label class="flex items-center p-4 border-2 rounded-xl cursor-pointer hover:border-green-300 border-gray-200"><input type="radio" name="status" value="deferido" required class="w-5 h-5 text-green-600"><span class="ml-3 font-semibold text-green-700">✓ Deferido</span></label>
                    <label class="flex items-center p-4 border-2 rounded-xl cursor-pointer hover:border-red-300 border-gray-200"><input type="radio" name="status" value="indeferido" required class="w-5 h-5 text-red-600"><span class="ml-3 font-semibold text-red-700">✗ Indeferido</span></label>
                </div>
            </div>
            <div><label class="block text-sm font-semibold mb-2">Resposta *</label><textarea name="resposta" required rows="6" class="input-field"></textarea></div>
            <button type="submit" class="btn-primary">Registrar Decisão</button>
        </form>
        <?php else: ?><div class="bg-gray-50 rounded-xl p-6"><h3 class="font-semibold mb-2">Resposta</h3><p><?= nl2br(sanitize($r['resposta'])) ?></p><p class="text-sm text-gray-500 mt-4">Analisado em <?= date('d/m/Y H:i',strtotime($r['analisado_em'])) ?></p></div><?php endif; ?>
    </div>
</main>
<?php renderFooter(); }

function pageAdminPublicacoes(): void {
    requireAdmin();$db=getDB();
    if($_SERVER['REQUEST_METHOD']==='POST'&&validateCSRFToken($_POST['csrf_token']??'')){
        $tipo=sanitize($_POST['tipo']??'');$tit=sanitize($_POST['titulo']??'');$desc=sanitize($_POST['descricao']??'');$pub=isset($_POST['publicar'])?1:0;$arq=null;
        if(!empty($_FILES['arquivo']['name'])){$ext=strtolower(pathinfo($_FILES['arquivo']['name'],PATHINFO_EXTENSION));if(in_array($ext,['pdf','doc','docx'])){$arq='pub_'.time().'_'.uniqid().'.'.$ext;move_uploaded_file($_FILES['arquivo']['tmp_name'],DOCS_DIR.$arq);}}
        $db->prepare("INSERT INTO publicacoes (tipo,titulo,descricao,arquivo,publicado,publicado_em,criado_por) VALUES (?,?,?,?,?,?,?)")->execute([$tipo,$tit,$desc,$arq,$pub,$pub?date('Y-m-d H:i:s'):null,$_SESSION['admin_id']]);
        logAction('nova_publicacao',"Título: $tit");
    }
    if(isset($_GET['excluir'])){$db->prepare("DELETE FROM publicacoes WHERE id=?")->execute([(int)$_GET['excluir']]);header('Location:?page=admin_publicacoes');exit;}
    if(isset($_GET['toggle'])){$db->exec("UPDATE publicacoes SET publicado=NOT publicado,publicado_em=CASE WHEN publicado=0 THEN datetime('now') ELSE publicado_em END WHERE id=".(int)$_GET['toggle']);header('Location:?page=admin_publicacoes');exit;}
    $pubs=$db->query("SELECT * FROM publicacoes ORDER BY criado_em DESC")->fetchAll();
    renderHead('Publicações');renderHeader(true);
?>
<main class="max-w-6xl mx-auto px-4 py-8">
    <h1 class="font-display text-3xl font-bold text-gray-900 mb-8">Publicações</h1>
    <div class="grid md:grid-cols-2 gap-8">
        <div class="card p-6"><h2 class="font-bold text-xl mb-4">Nova Publicação</h2>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div><label class="block text-sm font-semibold mb-2">Tipo *</label><select name="tipo" required class="input-field"><option value="">Selecione...</option><option value="edital">Edital</option><option value="retificacao">Retificação</option><option value="resultado_preliminar">Resultado Preliminar</option><option value="resultado_recursos">Resultado Recursos</option><option value="resultado_final">Resultado Final</option><option value="convocacao">Convocação</option><option value="comunicado">Comunicado</option></select></div>
                <div><label class="block text-sm font-semibold mb-2">Título *</label><input type="text" name="titulo" required class="input-field"></div>
                <div><label class="block text-sm font-semibold mb-2">Descrição</label><textarea name="descricao" rows="2" class="input-field"></textarea></div>
                <div><label class="block text-sm font-semibold mb-2">Arquivo</label><input type="file" name="arquivo" accept=".pdf,.doc,.docx" class="block w-full text-sm"></div>
                <div><label class="flex items-center"><input type="checkbox" name="publicar" value="1" class="w-5 h-5 mr-2 rounded"><span class="font-semibold">Publicar imediatamente</span></label></div>
                <button type="submit" class="btn-primary w-full">Criar</button>
            </form>
        </div>
        <div class="card p-6"><h2 class="font-bold text-xl mb-4">Lista</h2>
            <div class="space-y-3 max-h-[600px] overflow-y-auto"><?php foreach($pubs as $p): ?>
                <div class="p-4 border rounded-lg <?= $p['publicado']?'border-green-200 bg-green-50/50':'border-gray-200' ?>">
                    <div class="flex items-center gap-2 mb-1"><span class="px-2 py-0.5 bg-gov-100 text-gov-700 rounded text-xs font-semibold uppercase"><?= sanitize(str_replace('_',' ',$p['tipo'])) ?></span><?php if($p['publicado']): ?><span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-semibold">Publicado</span><?php endif; ?></div>
                    <h3 class="font-semibold"><?= sanitize($p['titulo']) ?></h3>
                    <p class="text-xs text-gray-500 mt-1"><?= date('d/m/Y H:i',strtotime($p['criado_em'])) ?></p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <a href="?page=admin_publicacoes&toggle=<?= $p['id'] ?>" class="text-xs px-3 py-1 bg-gray-100 text-gray-700 rounded hover:bg-gray-200"><?= $p['publicado']?'Despublicar':'Publicar' ?></a>
                        <?php if($p['arquivo']): ?><a href="documentos/<?= sanitize($p['arquivo']) ?>" target="_blank" class="text-xs px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200">Arquivo</a><?php endif; ?>
                        <a href="?page=admin_publicacoes&excluir=<?= $p['id'] ?>" onclick="return confirm('Excluir?')" class="text-xs px-3 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200">Excluir</a>
                    </div>
                </div>
            <?php endforeach; ?><?php if(empty($pubs)): ?><p class="text-gray-500 text-center py-8">Nenhuma.</p><?php endif; ?></div>
        </div>
    </div>
</main>
<?php renderFooter(); }

// ==================== ADMIN: CARGOS (COM EDIÇÃO DE VENCIMENTO) ====================

function pageAdminCargos(): void {
    requireAdmin();$db=getDB();
    if($_SERVER['REQUEST_METHOD']==='POST'&&validateCSRFToken($_POST['csrf_token']??'')){
        if(isset($_POST['novo_cargo'])){
            $db->prepare("INSERT INTO cargos (nome,carga_horaria,vagas,vencimento,habilitacao,tipo) VALUES (?,?,?,?,?,?)")->execute([
                sanitize($_POST['nome']),(int)$_POST['carga_horaria'],(int)$_POST['vagas'],
                (float)str_replace(',','.',$_POST['vencimento']),sanitize($_POST['habilitacao']),sanitize($_POST['tipo'])
            ]);
            logAction('novo_cargo',sanitize($_POST['nome']));
        }
        if(isset($_POST['editar_cargo'])){
            $cargoId=(int)$_POST['cargo_id'];
            $db->prepare("UPDATE cargos SET nome=?,carga_horaria=?,vagas=?,vencimento=?,habilitacao=?,tipo=? WHERE id=?")->execute([
                sanitize($_POST['edit_nome']),(int)$_POST['edit_carga_horaria'],(int)$_POST['edit_vagas'],
                (float)str_replace(',','.',$_POST['edit_vencimento']),sanitize($_POST['edit_habilitacao']),sanitize($_POST['edit_tipo']),$cargoId
            ]);
            logAction('editar_cargo',"ID:$cargoId");
        }
    }
    if(isset($_GET['desativar'])){$db->prepare("UPDATE cargos SET ativo=0 WHERE id=?")->execute([(int)$_GET['desativar']]);header('Location:?page=admin_cargos');exit;}
    if(isset($_GET['ativar'])){$db->prepare("UPDATE cargos SET ativo=1 WHERE id=?")->execute([(int)$_GET['ativar']]);header('Location:?page=admin_cargos');exit;}
    $cargos=$db->query("SELECT * FROM cargos ORDER BY ativo DESC,nome,carga_horaria")->fetchAll();
    $editId=(int)($_GET['editar']??0);$cargoEdit=null;
    if($editId){$s=$db->prepare("SELECT * FROM cargos WHERE id=?");$s->execute([$editId]);$cargoEdit=$s->fetch();}
    renderHead('Cargos');renderHeader(true);
?>
<main class="max-w-6xl mx-auto px-4 py-8">
    <h1 class="font-display text-3xl font-bold text-gray-900 mb-8">Gerenciar Cargos</h1>
    <div class="grid md:grid-cols-3 gap-8">
        <div class="card p-6">
            <?php if($cargoEdit): ?>
            <h2 class="font-bold text-xl mb-4">Editar Cargo</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="editar_cargo" value="1">
                <input type="hidden" name="cargo_id" value="<?= $cargoEdit['id'] ?>">
                <div><label class="block text-sm font-semibold mb-2">Nome *</label><input type="text" name="edit_nome" required class="input-field" value="<?= sanitize($cargoEdit['nome']) ?>"></div>
                <div><label class="block text-sm font-semibold mb-2">Tipo *</label><select name="edit_tipo" required class="input-field"><option value="auxiliar" <?= $cargoEdit['tipo']==='auxiliar'?'selected':'' ?>>Auxiliar</option><option value="professor" <?= $cargoEdit['tipo']==='professor'?'selected':'' ?>>Professor</option></select></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-semibold mb-2">CH *</label><input type="number" name="edit_carga_horaria" required class="input-field" value="<?= $cargoEdit['carga_horaria'] ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Vagas *</label><input type="number" name="edit_vagas" required class="input-field" value="<?= $cargoEdit['vagas'] ?>"></div>
                </div>
                <div><label class="block text-sm font-semibold mb-2">Vencimento (R$) *</label><input type="text" name="edit_vencimento" required class="input-field" value="<?= number_format($cargoEdit['vencimento'],2,',','.') ?>"></div>
                <div><label class="block text-sm font-semibold mb-2">Habilitação *</label><textarea name="edit_habilitacao" required rows="2" class="input-field"><?= sanitize($cargoEdit['habilitacao']) ?></textarea></div>
                <button type="submit" class="btn-primary w-full">Salvar Alterações</button>
                <a href="?page=admin_cargos" class="btn-secondary w-full text-center block">Cancelar</a>
            </form>
            <?php else: ?>
            <h2 class="font-bold text-xl mb-4">Novo Cargo</h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="novo_cargo" value="1">
                <div><label class="block text-sm font-semibold mb-2">Nome *</label><input type="text" name="nome" required class="input-field" placeholder="Ex: Professor"></div>
                <div><label class="block text-sm font-semibold mb-2">Tipo *</label><select name="tipo" required class="input-field"><option value="auxiliar">Auxiliar</option><option value="professor">Professor</option></select></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-semibold mb-2">CH *</label><input type="number" name="carga_horaria" required class="input-field" placeholder="40"></div>
                    <div><label class="block text-sm font-semibold mb-2">Vagas *</label><input type="number" name="vagas" required class="input-field" placeholder="5"></div>
                </div>
                <div><label class="block text-sm font-semibold mb-2">Vencimento (R$) *</label><input type="text" name="vencimento" required class="input-field" placeholder="2405,50"></div>
                <div><label class="block text-sm font-semibold mb-2">Habilitação *</label><textarea name="habilitacao" required rows="2" class="input-field" placeholder="Requisitos..."></textarea></div>
                <button type="submit" class="btn-primary w-full">Adicionar</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="md:col-span-2 card p-6">
            <h2 class="font-bold text-xl mb-4">Cargos Cadastrados</h2>
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead><tr class="border-b bg-gray-50"><th class="py-3 px-3 text-left">Cargo</th><th class="py-3 px-3 text-center">CH</th><th class="py-3 px-3 text-center">Vagas</th><th class="py-3 px-3 text-center">Vencimento</th><th class="py-3 px-3 text-center">Tipo</th><th class="py-3 px-3 text-center">Status</th><th class="py-3 px-3 text-center">Ações</th></tr></thead>
                <tbody><?php foreach($cargos as $cg): ?>
                <tr class="border-b <?= $cg['ativo']?'':'bg-gray-100 text-gray-500' ?>">
                    <td class="py-3 px-3 font-medium"><?= sanitize($cg['nome']) ?></td>
                    <td class="py-3 px-3 text-center"><?= $cg['carga_horaria'] ?>h</td>
                    <td class="py-3 px-3 text-center"><?= $cg['vagas'] ?></td>
                    <td class="py-3 px-3 text-center font-semibold text-green-600">R$ <?= number_format($cg['vencimento'],2,',','.') ?></td>
                    <td class="py-3 px-3 text-center"><span class="px-2 py-0.5 bg-gov-100 text-gov-700 rounded text-xs"><?= ucfirst($cg['tipo']) ?></span></td>
                    <td class="py-3 px-3 text-center"><?= $cg['ativo']?'<span class="text-green-600">Ativo</span>':'<span class="text-gray-500">Inativo</span>' ?></td>
                    <td class="py-3 px-3 text-center space-x-2">
                        <a href="?page=admin_cargos&editar=<?= $cg['id'] ?>" class="text-gov-600 hover:text-gov-800 text-xs font-semibold">Editar</a>
                        <?php if($cg['ativo']): ?><a href="?page=admin_cargos&desativar=<?= $cg['id'] ?>" onclick="return confirm('Desativar?')" class="text-red-600 hover:text-red-800 text-xs">Desativar</a>
                        <?php else: ?><a href="?page=admin_cargos&ativar=<?= $cg['id'] ?>" class="text-green-600 hover:text-green-800 text-xs">Ativar</a><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
</main>
<?php renderFooter(); }

// ==================== ADMIN: CONFIG (COM CHECKBOX MÚLTIPLOS CARGOS) ====================

function pageAdminConfig(): void {
    requireAdmin();$db=getDB();
    if($_SERVER['REQUEST_METHOD']==='POST'&&validateCSRFToken($_POST['csrf_token']??'')){
        $campos=['titulo_chamada','subtitulo','municipio','ano','data_publicacao','inscricoes_inicio','inscricoes_fim','resultado_preliminar','recursos_inicio','recursos_fim','resultado_final','contato_email','contato_telefone','contato_whatsapp','endereco','site','texto_declaracao_parentesco','texto_declaracao_nao_participou','chamada_anterior'];
        foreach($campos as $c)if(isset($_POST[$c]))setConfig($c,sanitize($_POST[$c]));
        // Checkboxes
        setConfig('exibir_pontuacao_publica',isset($_POST['exibir_pontuacao_publica'])?'1':'0');
        setConfig('exibir_classificacao_publica',isset($_POST['exibir_classificacao_publica'])?'1':'0');
        setConfig('permitir_multiplos_cargos',isset($_POST['permitir_multiplos_cargos'])?'1':'0');
        logAction('config','Configurações atualizadas');
    }
    if(isset($_POST['nova_senha'])&&!empty($_POST['nova_senha'])){
        if($_POST['nova_senha']===$_POST['confirmar_senha']){
            $db->prepare("UPDATE administradores SET senha=? WHERE id=?")->execute([password_hash($_POST['nova_senha'],PASSWORD_DEFAULT),$_SESSION['admin_id']]);
            logAction('alterar_senha','');
        }
    }
    renderHead('Configurações');renderHeader(true);
?>
<main class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="font-display text-3xl font-bold text-gray-900 mb-8">Configurações do Sistema</h1>
    <div class="space-y-8">
        <!-- Gerais -->
        <div class="card p-6"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Informações Gerais</h2>
            <form method="POST" class="space-y-4"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-semibold mb-2">Título</label><input type="text" name="titulo_chamada" class="input-field" value="<?= sanitize(getConfig('titulo_chamada')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Subtítulo</label><input type="text" name="subtitulo" class="input-field" value="<?= sanitize(getConfig('subtitulo')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Município</label><input type="text" name="municipio" class="input-field" value="<?= sanitize(getConfig('municipio')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Ano</label><input type="text" name="ano" class="input-field" value="<?= sanitize(getConfig('ano')) ?>"></div>
                </div>
                <button type="submit" class="btn-primary">Salvar</button>
            </form>
        </div>
        
        <!-- Cronograma -->
        <div class="card p-6"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Cronograma</h2>
            <form method="POST" class="space-y-4"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-semibold mb-2">Data Publicação</label><input type="date" name="data_publicacao" class="input-field" value="<?= sanitize(getConfig('data_publicacao')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Resultado Preliminar</label><input type="date" name="resultado_preliminar" class="input-field" value="<?= sanitize(getConfig('resultado_preliminar')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Início Inscrições</label><input type="datetime-local" name="inscricoes_inicio" class="input-field" value="<?= date('Y-m-d\TH:i',strtotime(getConfig('inscricoes_inicio')??'now')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Fim Inscrições</label><input type="datetime-local" name="inscricoes_fim" class="input-field" value="<?= date('Y-m-d\TH:i',strtotime(getConfig('inscricoes_fim')??'now')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Início Recursos</label><input type="datetime-local" name="recursos_inicio" class="input-field" value="<?= date('Y-m-d\TH:i',strtotime(getConfig('recursos_inicio')??'now')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Fim Recursos</label><input type="datetime-local" name="recursos_fim" class="input-field" value="<?= date('Y-m-d\TH:i',strtotime(getConfig('recursos_fim')??'now')) ?>"></div>
                    <div class="md:col-span-2"><label class="block text-sm font-semibold mb-2">Resultado Final</label><input type="date" name="resultado_final" class="input-field" value="<?= sanitize(getConfig('resultado_final')) ?>"></div>
                </div>
                <button type="submit" class="btn-primary">Salvar</button>
            </form>
        </div>
        
        <!-- Declarações -->
        <div class="card p-6"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Declarações</h2>
            <form method="POST" class="space-y-4"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div><label class="block text-sm font-semibold mb-2">Declaração de Parentesco</label><textarea name="texto_declaracao_parentesco" rows="3" class="input-field"><?= sanitize(getConfig('texto_declaracao_parentesco')) ?></textarea></div>
                <div><label class="block text-sm font-semibold mb-2">Chamada Anterior (referência)</label><input type="text" name="chamada_anterior" class="input-field" value="<?= sanitize(getConfig('chamada_anterior')) ?>" placeholder="Ex: Chamada Pública nº 4/2025"></div>
                <div><label class="block text-sm font-semibold mb-2">Declaração de Não Participação</label><textarea name="texto_declaracao_nao_participou" rows="3" class="input-field"><?= sanitize(getConfig('texto_declaracao_nao_participou')) ?></textarea></div>
                <button type="submit" class="btn-primary">Salvar</button>
            </form>
        </div>
        
        <!-- Regras de Inscrição e Exibição -->
        <div class="card p-6"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Regras de Inscrição e Exibição</h2>
            <form method="POST" class="space-y-4"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <p class="text-sm text-gray-600 mb-4">Configure o comportamento do sistema em relação a inscrições e exibição de resultados.</p>
                <div class="space-y-3">
                    <label class="flex items-center p-4 border rounded-lg hover:border-gov-300 cursor-pointer">
                        <input type="checkbox" name="permitir_multiplos_cargos" value="1" <?= getConfig('permitir_multiplos_cargos')==='1'?'checked':'' ?> class="w-5 h-5 text-gov-600 rounded mr-3">
                        <div><span class="font-semibold">Permitir inscrição em mais de um cargo com o mesmo CPF</span><p class="text-sm text-gray-500">Se marcado, o candidato pode ter inscrições em cargos diferentes. Se desmarcado, ao tentar nova inscrição com CPF já cadastrado, a inscrição anterior será cancelada e a nova será a válida.</p></div>
                    </label>
                    <label class="flex items-center p-4 border rounded-lg hover:border-gov-300 cursor-pointer">
                        <input type="checkbox" name="exibir_pontuacao_publica" value="1" <?= getConfig('exibir_pontuacao_publica')==='1'?'checked':'' ?> class="w-5 h-5 text-gov-600 rounded mr-3">
                        <div><span class="font-semibold">Exibir pontuação para o candidato</span><p class="text-sm text-gray-500">O candidato poderá ver sua pontuação na consulta</p></div>
                    </label>
                    <label class="flex items-center p-4 border rounded-lg hover:border-gov-300 cursor-pointer">
                        <input type="checkbox" name="exibir_classificacao_publica" value="1" <?= getConfig('exibir_classificacao_publica')==='1'?'checked':'' ?> class="w-5 h-5 text-gov-600 rounded mr-3">
                        <div><span class="font-semibold">Exibir classificação para o candidato</span><p class="text-sm text-gray-500">O candidato poderá ver sua classificação na consulta</p></div>
                    </label>
                </div>
                <button type="submit" class="btn-primary">Salvar</button>
            </form>
        </div>
        
        <!-- Contato -->
        <div class="card p-6"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Contato</h2>
            <form method="POST" class="space-y-4"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-semibold mb-2">E-mail</label><input type="email" name="contato_email" class="input-field" value="<?= sanitize(getConfig('contato_email')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Telefone</label><input type="text" name="contato_telefone" class="input-field" value="<?= sanitize(getConfig('contato_telefone')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">WhatsApp</label><input type="text" name="contato_whatsapp" class="input-field" value="<?= sanitize(getConfig('contato_whatsapp')) ?>"></div>
                    <div><label class="block text-sm font-semibold mb-2">Site</label><input type="url" name="site" class="input-field" value="<?= sanitize(getConfig('site')) ?>"></div>
                    <div class="md:col-span-2"><label class="block text-sm font-semibold mb-2">Endereço</label><input type="text" name="endereco" class="input-field" value="<?= sanitize(getConfig('endereco')) ?>"></div>
                </div>
                <button type="submit" class="btn-primary">Salvar</button>
            </form>
        </div>
        
        <!-- Senha -->
        <div class="card p-6"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Alterar Senha</h2>
            <form method="POST" class="space-y-4"><input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div class="grid md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-semibold mb-2">Nova Senha</label><input type="password" name="nova_senha" class="input-field" minlength="6"></div>
                    <div><label class="block text-sm font-semibold mb-2">Confirmar</label><input type="password" name="confirmar_senha" class="input-field" minlength="6"></div>
                </div>
                <button type="submit" class="btn-primary">Alterar</button>
            </form>
        </div>
        
        <!-- Ações -->
        <div class="card p-6"><h2 class="font-bold text-xl text-gray-900 mb-4 border-b pb-2">Ações</h2>
            <div class="flex flex-wrap gap-4">
                <a href="?page=admin_classificar" onclick="return confirm('Recalcular classificação?')" class="btn-secondary">🔄 Recalcular Classificação</a>
                <a href="?page=admin_exportar" class="btn-secondary">📥 Exportar CSV</a>
                <a href="?page=admin_cargos" class="btn-secondary">👔 Gerenciar Cargos</a>
            </div>
        </div>
    </div>
</main>
<?php renderFooter(); }

// ==================== CLASSIFICAR, EXPORTAR, ROTEAMENTO ====================

function pageAdminClassificar(): void {
    requireAdmin(); classificarCandidatos(); logAction('classificar','Recalculado');
    header('Location: ?page=admin_inscricoes&status=homologado'); exit;
}

function pageAdminExportar(): void {
    requireAdmin(); $db=getDB();
    $stmt=$db->query("SELECT ca.protocolo,ca.nome,ca.cpf,ca.rg,ca.data_nascimento,ca.email,ca.telefone,ca.logradouro,ca.numero,ca.bairro,ca.cidade,ca.estado,ca.cep,cg.nome as cargo,cg.carga_horaria,cg.tipo as cargo_tipo,ca.titulacao,ca.tempo_servico_meses,ca.carga_horaria_cursos,ca.pontuacao,ca.classificacao,ca.status,ca.inscrito_em,ca.ip_inscricao FROM candidatos ca JOIN cargos cg ON ca.cargo_id=cg.id ORDER BY cg.nome,ca.classificacao,ca.pontuacao DESC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=chamada_publica_'.date('Y-m-d_His').'.csv');
    $o=fopen('php://output','w');fprintf($o,chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($o,['Protocolo','Nome','CPF','RG','Nascimento','E-mail','Telefone','Endereço','Número','Bairro','Cidade','Estado','CEP','Cargo','CH','Titulação/Formação','Tempo Serviço (meses)','CH Cursos','Pontuação','Classificação','Status','Inscrito em','IP'],';');
    foreach($stmt->fetchAll() as $c) fputcsv($o,[$c['protocolo'],$c['nome'],formatarCPF($c['cpf']),$c['rg'],date('d/m/Y',strtotime($c['data_nascimento'])),$c['email'],$c['telefone'],$c['logradouro'],$c['numero'],$c['bairro'],$c['cidade'],$c['estado'],$c['cep'],$c['cargo'],$c['carga_horaria'].'h',getTitulacaoLabel($c['titulacao'],$c['cargo_tipo']),$c['tempo_servico_meses'],$c['carga_horaria_cursos'],number_format($c['pontuacao'],2,',','.'),$c['classificacao']?:'-',ucfirst($c['status']),date('d/m/Y H:i',strtotime($c['inscrito_em'])),$c['ip_inscricao']??''],';');
    fclose($o);exit;
}

function pageAdminDownloadDocs(): void {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    $db = getDB();
    
    // Buscar candidato e cargo
    $s = $db->prepare("SELECT ca.nome, ca.protocolo, cg.nome as cargo_nome FROM candidatos ca JOIN cargos cg ON ca.cargo_id=cg.id WHERE ca.id=?");
    $s->execute([$id]);
    $cand = $s->fetch();
    if (!$cand) { header('Location:?page=admin_inscricoes'); exit; }
    
    // Buscar documentos
    $docs = $db->query("SELECT * FROM documentos WHERE candidato_id=$id ORDER BY tipo, enviado_em")->fetchAll();
    if (empty($docs)) { header('Location:?page=admin_inscricao&id='.$id); exit; }
    
    // Gerar nome do ZIP: "Nome Candidato - Cargo.zip"
    $nomeBase = preg_replace('/[^a-zA-Z0-9\s\-àáâãéêíóôõúüç]/u', '', $cand['nome']);
    $cargoBase = preg_replace('/[^a-zA-Z0-9\s\-àáâãéêíóôõúüç]/u', '', $cand['cargo_nome']);
    $nomeZip = trim($nomeBase) . ' - ' . trim($cargoBase) . '.zip';
    
    // Categorias para nomes de pasta
    $cats = getDocCategories();
    
    // Criar ZIP
    $tmpFile = tempnam(sys_get_temp_dir(), 'docs_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        header('Location:?page=admin_inscricao&id='.$id);
        exit;
    }
    
    foreach ($docs as $d) {
        $filePath = UPLOAD_DIR . $d['nome_arquivo'];
        if (!file_exists($filePath)) continue;
        
        // Organizar em pastas por categoria
        $catNome = isset($cats[$d['tipo']]) ? $cats[$d['tipo']]['nome'] : 'Outros';
        // Limpar nome da pasta
        $catNome = preg_replace('/[\/\\\\:*?"<>|]/', '', $catNome);
        $zip->addFile($filePath, $catNome . '/' . $d['nome_original']);
    }
    
    $zip->close();
    
    // Enviar para download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nomeZip . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

// ==================== ROTEAMENTO ====================

$page = $_GET['page'] ?? 'home';
$action = $_GET['action'] ?? '';

if ($action === 'logout') { session_destroy(); header('Location: ?'); exit; }

getDB();

switch ($page) {
    case 'home': pageHome(); break;
    case 'inscricao': pageInscricao(); break;
    case 'consulta': pageConsulta(); break;
    case 'recursos': pageRecursos(); break;
    case 'documentos': pageDocumentos(); break;
    case 'admin_login': pageAdminLogin(); break;
    case 'admin': pageAdmin(); break;
    case 'admin_inscricoes': pageAdminInscricoes(); break;
    case 'admin_inscricao': pageAdminInscricao(); break;
    case 'admin_recursos': pageAdminRecursos(); break;
    case 'admin_recurso_responder': pageAdminRecursoResponder(); break;
    case 'admin_publicacoes': pageAdminPublicacoes(); break;
    case 'admin_cargos': pageAdminCargos(); break;
    case 'admin_config': pageAdminConfig(); break;
    case 'admin_classificar': pageAdminClassificar(); break;
    case 'admin_exportar': pageAdminExportar(); break;
    case 'admin_download_docs': pageAdminDownloadDocs(); break;
    default: pageHome();
}
