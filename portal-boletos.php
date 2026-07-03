<?php
/**
 * Plugin Name: Portal de Boletos
 * Description: Sistema de gerenciamento e consulta de boletos por CPF.
 * Version: 1.0
 * Author: NicolasKaiky
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (!session_id()) {
    session_start();
}

if (isset($_SESSION['pb_cliente_autenticado_tempo'])) {
    $tempo_limite = 15 * 60; // 15 minutos
    if ((time() - $_SESSION['pb_cliente_autenticado_tempo']) > $tempo_limite) {
        unset($_SESSION['pb_cliente_autenticado']);
        unset($_SESSION['pb_cliente_autenticado_tempo']);
    }
}

register_activation_hook(__FILE__, 'pb_criar_tabelas');

function pb_criar_tabelas() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $clientes = $wpdb->prefix . 'pb_clientes';
    $boletos  = $wpdb->prefix . 'pb_boletos';

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    pb_criar_cargo_funcionario_boletos();

    $sql_clientes = "CREATE TABLE $clientes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        nome VARCHAR(255) NOT NULL,
        cpf VARCHAR(20) NOT NULL,
        email VARCHAR(150) DEFAULT '',
        email_verificado TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY cpf (cpf)
    ) $charset_collate;";

    $sql_boletos = "CREATE TABLE $boletos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cliente_id BIGINT UNSIGNED NOT NULL,
        nome_arquivo VARCHAR(255) NOT NULL,
        caminho_arquivo VARCHAR(255) NOT NULL,
        referencia VARCHAR(50) DEFAULT '',
        mes_referencia VARCHAR(20) DEFAULT '',
        vencimento DATE DEFAULT NULL,
        valor DECIMAL(10,2) DEFAULT NULL,
        nosso_numero VARCHAR(80) DEFAULT '',
        linha_digitavel VARCHAR(150) DEFAULT '',
        email_status VARCHAR(30) DEFAULT 'pendente',
        email_enviado_em DATETIME DEFAULT NULL,
        email_enviado_por BIGINT UNSIGNED DEFAULT NULL,
        email_tentativas INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY cliente_id (cliente_id),
        KEY vencimento (vencimento),
        KEY nosso_numero (nosso_numero)
    ) $charset_collate;";

    dbDelta($sql_clientes);
    dbDelta($sql_boletos);
    
    $codigos = $wpdb->prefix . 'pb_codigos';

    $sql_codigos = "CREATE TABLE $codigos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cliente_id BIGINT UNSIGNED NOT NULL,
        codigo VARCHAR(10) NOT NULL,
        expira_em DATETIME NOT NULL,
        usado TINYINT(1) DEFAULT 0,
        tentativas INT DEFAULT 0,
        bloqueado_ate DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    dbDelta($sql_codigos);
    $logs = $wpdb->prefix . 'pb_logs';

    $sql_logs = "CREATE TABLE $logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        usuario_id BIGINT UNSIGNED DEFAULT NULL,
        usuario_nome VARCHAR(255) DEFAULT '',
        acao VARCHAR(100) NOT NULL,
        detalhes TEXT DEFAULT NULL,
        ip VARCHAR(100) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY usuario_id (usuario_id),
        KEY acao (acao),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    dbDelta($sql_logs);
}

function pb_registrar_log($acao, $detalhes = '') {
    global $wpdb;

    $usuario_id = get_current_user_id();
    $usuario = $usuario_id ? get_userdata($usuario_id) : null;

    $wpdb->insert(
        $wpdb->prefix . 'pb_logs',
        [
            'usuario_id'   => $usuario_id ?: null,
            'usuario_nome' => $usuario ? $usuario->display_name : 'Visitante',
            'acao'         => sanitize_text_field($acao),
            'detalhes'     => is_array($detalhes) ? wp_json_encode($detalhes) : sanitize_textarea_field($detalhes),
            'ip'           => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );
}

function pb_registrar_acesso_painel_limitado() {
    global $wpdb;

    $usuario_id = get_current_user_id();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
    $limite_minutos = 10;

    $tabela_logs = $wpdb->prefix . 'pb_logs';

    $existe_recente = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $tabela_logs
             WHERE acao = %s
             AND usuario_id = %d
             AND ip = %s
             AND created_at >= DATE_SUB(%s, INTERVAL $limite_minutos MINUTE)
             LIMIT 1",
            'acesso_painel',
            $usuario_id,
            $ip,
            current_time('mysql')
        )
    );

    if ($existe_recente) {
        return;
    }

    pb_registrar_log('acesso_painel', 'Acessou o painel de boletos.');
}

function pb_usuario_pode($permissao) {
    if (current_user_can('manage_options')) {
        return true;
    }

    return current_user_can($permissao);
}

function pb_criar_cargo_funcionario_boletos() {
    add_role(
        'pb_funcionario_boletos',
        'Funcionário Boletos',
        [
            'read' => true,

            'pb_ver_boletos' => true,
            'pb_importar_boletos' => false,
            'pb_excluir_boletos' => false,
            'pb_enviar_boletos' => false,
            'pb_registrar_pagamento' => false,

            'pb_ver_clientes' => false,
            'pb_editar_email_cliente' => false,
            'pb_excluir_clientes' => false,

            'pb_ver_logs' => false,
            'pb_limpar_logs' => false,

            'pb_editar_mensagem_email' => false,
            
            'pb_ver_funcionarios' => false,
            'pb_ver_permissoes' => false,
            'pb_editar_pagina_inicial' => false,
        ]
    );
    
    add_role(
        'pb_gestor_boletos',
        'Gestor',
        [
            'read' => true,
    
            'pb_ver_boletos' => true,
            'pb_importar_boletos' => true,
            'pb_excluir_boletos' => true,
            'pb_enviar_boletos' => true,
            'pb_registrar_pagamento' => true,
    
            'pb_ver_clientes' => true,
            'pb_editar_email_cliente' => true,
            'pb_excluir_clientes' => false,
    
            'pb_ver_logs' => true,
            'pb_limpar_logs' => false,
    
            'pb_editar_mensagem_email' => true,
    
            'pb_ver_funcionarios' => false,
            'pb_ver_permissoes' => false,
            'pb_editar_pagina_inicial' => false,
        ]
    );

    $admin = get_role('administrator');

    if ($admin) {
        $permissoes = [
            'pb_ver_boletos',
            'pb_importar_boletos',
            'pb_excluir_boletos',
            'pb_enviar_boletos',
            'pb_enviar_boletos',
            'pb_registrar_pagamento',

            'pb_ver_clientes',
            'pb_editar_email_cliente',
            'pb_excluir_clientes',

            'pb_ver_logs',
            'pb_limpar_logs',

            'pb_editar_mensagem_email',
            
            'pb_ver_funcionarios',
            'pb_ver_permissoes',
            'pb_editar_pagina_inicial',
        ];

        foreach ($permissoes as $permissao) {
            $admin->add_cap($permissao);
        }
    }
}

add_action('admin_init', 'pb_garantir_permissoes_admin_boletos');

function pb_garantir_permissoes_admin_boletos() {
    $admin = get_role('administrator');

    if (!$admin) {
        return;
    }

    $permissoes = [
        'pb_ver_boletos',
        'pb_importar_boletos',
        'pb_excluir_boletos',
        'pb_enviar_boletos',
        'pb_registrar_pagamento',

        'pb_ver_clientes',
        'pb_editar_email_cliente',
        'pb_excluir_clientes',

        'pb_ver_logs',
        'pb_limpar_logs',

        'pb_editar_mensagem_email',

        'pb_ver_funcionarios',
        'pb_ver_permissoes',
        'pb_editar_pagina_inicial',
    ];

    foreach ($permissoes as $permissao) {
        $admin->add_cap($permissao);
    }
}

add_action('admin_init', 'pb_garantir_permissoes_elementor_para_gestor');

function pb_garantir_permissoes_elementor_para_gestor() {
    $usuarios = get_users([
        'role__in' => ['pb_gestor_boletos', 'pb_funcionario_boletos'],
        'fields' => ['ID'],
    ]);

    $caps_elementor = [
        'edit_pages',
        'edit_others_pages',
        'edit_published_pages',
        'edit_private_pages',
        'publish_pages',
        'read_private_pages',
        'upload_files',
        'edit_posts',
        'edit_others_posts',
        'edit_published_posts',
        'edit_private_posts',
        'elementor_edit',
    ];

    foreach ($usuarios as $usuario_item) {
        $usuario = new WP_User($usuario_item->ID);

        foreach ($caps_elementor as $cap) {
            if (user_can($usuario, 'pb_editar_pagina_inicial')) {
                $usuario->add_cap($cap);
            } else {
                $usuario->remove_cap($cap);
            }
        }
    }
}

add_action('admin_menu', 'pb_menu_admin');

add_action('admin_head', 'pb_admin_css_global');

function pb_admin_css_global() {
    if (
        !isset($_GET['page']) ||
        strpos(sanitize_text_field($_GET['page']), 'pb_boletos') !== 0
    ) {
        return;
    }
    ?>
    <style>
        .pb-app {
            min-height: 0 !important;
        }

        .pb-hero .pb-page-title,
        .pb-hero .pb-hero-title,
        .pb-hero h1 {
            color: #ffffff !important;
            margin: 0 !important;
            font-size: 30px !important;
            line-height: 1.2 !important;
            font-weight: 800 !important;
        }

        #wpfooter {
            display: none !important;
        }

        #wpbody-content {
            padding-bottom: 20px !important;
        }
        
        .pb-app .button,
        .pb-app .button-primary,
        .pb-app .button-secondary,
        .pb-app .pb-btn {
            border-radius:10px !important;
            font-weight:700 !important;
            min-height:36px;
            padding:6px 14px !important;
            box-shadow:none !important;
        }
        
        .pb-app .button-primary,
        .pb-app .pb-btn-primary {
            background:#0b5ed7 !important;
            border-color:#0b5ed7 !important;
            color:#fff !important;
        }
        
        .pb-app .button-primary:hover,
        .pb-app .pb-btn-primary:hover {
            background:#084fb8 !important;
            border-color:#084fb8 !important;
        }
        
        .pb-app .button:not(.button-primary):hover {
            border-color:#94a3b8 !important;
            color:#0f172a !important;
        }
        
        .pb-table-wrap {
            background:#fff;
            border-radius:16px !important;
            overflow:hidden !important;
        }
        
        .pb-table-wrap table {
            border-collapse:collapse !important;
        }
        
        .pb-table-wrap th {
            padding:8px 6px !important;
            font-size:10px !important;
            text-transform:uppercase;
            letter-spacing:.03em;
        }
        
        .pb-table-wrap td {
            padding:8px 6px !important;
            font-size:10px !important;
        }
        
        .pb-table-wrap tbody tr:hover {
            background:#f8fbff !important;
        }
        
        .pb-filters {
            background:#fff;
            border:1px solid #e5eaf2;
            border-radius:16px;
            padding:16px;
            margin-bottom:14px;
            box-shadow:0 8px 22px rgba(15,23,42,.04);
        }
        
        .pb-filters label {
            color:#334155;
            font-size:12px;
            font-weight:800 !important;
            margin-bottom:6px !important;
        }
        
        .pb-filters input,
        .pb-filters select {
            min-width:150px;
        }
        .pb-notices-area {
            margin: 0 0 18px 0;
            max-width: 820px;
        }
        
        .pb-notices-area:empty {
            display: none;
        }
        
        .pb-notices-area .pb-alert {
            position: relative;
            margin: 0 0 14px 0;
            padding: 14px 18px 14px 48px;
            border-radius: 14px;
            border: 1px solid #dbe7f3;
            background: #ffffff;
            box-shadow: 0 10px 26px rgba(15,23,42,.08);
            color: #0f172a;
            font-size: 14px;
            line-height: 1.45;
            font-weight: 700;
        }
        
        .pb-notices-area .pb-alert strong {
            font-weight: 900;
        }
        
        .pb-notices-area .pb-alert::before {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 22px;
            height: 22px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 900;
        }
        
        .pb-notices-area .pb-alert-success {
            border-left: 5px solid #22c55e;
            background: #f0fdf4;
            color: #14532d;
        }
        
        .pb-notices-area .pb-alert-success::before {
            content: "✓";
            background: #22c55e;
            color: #ffffff;
        }
        
        .pb-notices-area .pb-alert-error {
            border-left: 5px solid #ef4444;
            background: #fef2f2;
            color: #7f1d1d;
        }
        
        .pb-notices-area .pb-alert-error::before {
            content: "!";
            background: #ef4444;
            color: #ffffff;
        }
        
        .pb-notices-area .pb-alert-warning {
            border-left: 5px solid #f59e0b;
            background: #fffbeb;
            color: #78350f;
        }
        
        .pb-notices-area .pb-alert-warning::before {
            content: "!";
            background: #f59e0b;
            color: #ffffff;
        }
        
        .pb-import-panel {
            border-top: 4px solid #0b5ed7;
        }
        
        .pb-import-panel h2 {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .pb-import-panel h2::before {
            display: none;
        }
        
        .pb-section-title {
            padding: 18px 22px;
        }
        
        .pb-section-title h2 {
            margin-bottom: 4px !important;
        }
        
        .pb-section-title p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
        }
        
        .pb-status {
            min-width: 72px;
            justify-content: center;
            border-radius: 999px !important;
            padding: 6px 10px !important;
            font-size: 11px !important;
            letter-spacing: .03em;
        }
        
        .pb-status-enviado {
            background: #dcfce7 !important;
            color: #166534 !important;
        }
        
        .pb-status-pendente {
            background: #fef3c7 !important;
            color: #92400e !important;
        }
        
        .pb-status-agendado {
            background: #dbeafe !important;
            color: #1d4ed8 !important;
        }
        
        .pb-status-falhou {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }
        
        .pb-import-panel .button-primary {
            padding: 8px 18px !important;
            min-height: 40px;
            box-shadow: 0 8px 18px rgba(11,94,215,.22) !important;
        }
        
        .pb-import-panel .button-primary:hover {
            transform: translateY(-1px);
        }
        
        .pb-table-wrap {
            box-shadow: 0 8px 22px rgba(15,23,42,.04);
        }
        
        .pb-table-wrap tbody tr:nth-child(even) {
            background: #fbfdff;
        }
        
        .pb-table-wrap tbody td:first-child,
        .pb-table-wrap thead th:first-child {
            text-align: center;
            width: 42px;
        }
        
        .pb-edit-email-panel {
            border-top: 4px solid #22c55e;
            margin-bottom: 22px;
        }
        
        .pb-edit-email-panel h2 {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .pb-edit-email-panel h2::before {
            content: "✉";
            width: 24px;
            height: 24px;
            border-radius: 999px;
            background: #dcfce7;
            color: #15803d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 900;
        }
    </style>
    <?php
}

function pb_render_admin_notices() {
    $mensagens = [];

    if (isset($_GET['template']) && $_GET['template'] === 'ok') {
        $mensagens[] = ['success', 'Mensagem padrão de e-mail atualizada com sucesso.'];
    }

    if (isset($_GET['logs']) && $_GET['logs'] === 'limpos') {
        $mensagens[] = ['success', 'Logs limpos com sucesso.'];
    }

    if (isset($_GET['permissoes']) && $_GET['permissoes'] === 'ok') {
        $mensagens[] = ['success', 'Permissões atualizadas com sucesso.'];
    }

    if (isset($_GET['clientes_excluidos'])) {
        $mensagens[] = ['success', 'Clientes excluídos: <strong>' . intval($_GET['clientes_excluidos']) . '</strong>'];
    }

    if (isset($_GET['funcionario']) && $_GET['funcionario'] === 'criado') {
        $mensagens[] = ['success', 'Funcionário cadastrado com sucesso.'];
    }

    if (isset($_GET['funcionario']) && $_GET['funcionario'] === 'atualizado') {
        $mensagens[] = ['success', 'Funcionário atualizado com sucesso.'];
    }

    if (isset($_GET['funcionario']) && $_GET['funcionario'] === 'erro') {
        $mensagens[] = ['error', 'Não foi possível salvar o funcionário. Verifique os dados.'];
    }

    if (isset($_GET['email']) && $_GET['email'] === 'ok') {
        $mensagens[] = ['success', 'E-mail do cliente atualizado com sucesso.'];
    }

    if (isset($_GET['email']) && $_GET['email'] === 'erro') {
        $mensagens[] = ['error', 'Não foi possível atualizar o e-mail. Verifique se o e-mail é válido.'];
    }
    
     if ( isset( $_GET['pagamento'] ) && $_GET['pagamento'] === 'ok' ) {
        $mensagens[] = [ 'success', 'Pagamento registrado com sucesso.' ];
    }

    if ( isset( $_GET['pagamento'] ) && $_GET['pagamento'] === 'revertido' ) {
        $mensagens[] = [ 'success', 'Pagamento revertido. Boleto marcado como pendente.' ];
    }

    if ( isset( $_GET['pagamento'] ) && $_GET['pagamento'] === 'erro' ) {
        $mensagens[] = [ 'error', 'Não foi possível registrar o pagamento. Verifique os dados.' ];
    }

    if ( isset( $_GET['obs'] ) && $_GET['obs'] === 'ok' ) {
        $mensagens[] = [ 'success', 'Observação salva com sucesso.' ];
    }

    if ( isset( $_GET['cliente'] ) && $_GET['cliente'] === 'ok' ) {
        $mensagens[] = [ 'success', 'Dados do cliente atualizados com sucesso.' ];
    }

    if ( isset( $_GET['cliente'] ) && $_GET['cliente'] === 'erro' ) {
        $mensagens[] = [ 'error', 'Não foi possível atualizar os dados do cliente.' ];
    }

    if ( isset( $_GET['reprocessado'] ) && $_GET['reprocessado'] === 'ok' ) {
        $mensagens[] = [
            'success',
            'Reprocessamento concluído. ' .
            'Atualizados: <strong>' . intval( isset($_GET['atualizados'])  ? $_GET['atualizados']  : 0 ) . '</strong> &nbsp;·&nbsp; ' .
            'Já completos: <strong>' . intval( isset($_GET['ja_completos']) ? $_GET['ja_completos'] : 0 ) . '</strong> &nbsp;·&nbsp; ' .
            'Sem arquivo: <strong>' . intval( isset($_GET['sem_arquivo'])   ? $_GET['sem_arquivo']  : 0 ) . '</strong>'
        ];
    }

    if ( isset( $_GET['logs_excluidos'] ) ) {
        $mensagens[] = [ 'success', 'Logs excluídos: <strong>' . intval( $_GET['logs_excluidos'] ) . '</strong>' ];
    }

    if (empty($mensagens)) {
        return;
    }
    ?>
    <div class="pb-notices-area">
        <?php foreach ($mensagens as $mensagem) : ?>
            <div class="pb-alert pb-alert-<?php echo esc_attr($mensagem[0]); ?>">
                <?php echo wp_kses_post($mensagem[1]); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function pb_menu_admin() {
    add_menu_page(
        'Portal de Boletos',
        'Boletos',
        'pb_ver_boletos',
        'pb_boletos',
        'pb_pagina_admin',
        'dashicons-media-document',
        6
    );

    add_submenu_page(
        'pb_boletos',
        'Boletos',
        'Boletos',
        'pb_ver_boletos',
        'pb_boletos',
        'pb_pagina_admin'
    );
    
    add_submenu_page(
        'pb_boletos',
        'Mensagem de E-mail',
        'Mensagem de E-mail',
        'pb_editar_mensagem_email',
        'pb_boletos_mensagem_email',
        'pb_pagina_mensagem_email'
    );
    
    add_submenu_page(
        'pb_boletos',
        'Clientes',
        'Clientes',
        'pb_ver_clientes',
        'pb_boletos_clientes',
        'pb_pagina_clientes'
    );
    
    add_submenu_page(
        'pb_boletos',
        'Logs do Portal',
        'Logs',
        'pb_ver_logs',
        'pb_boletos_logs',
        'pb_pagina_logs'
    );
    
    add_submenu_page(
        'pb_boletos',
        'Funcionários',
        'Funcionários',
        'pb_ver_funcionarios',
        'pb_boletos_funcionarios',
        'pb_pagina_funcionarios'
    );
    
    add_submenu_page(
        'pb_boletos',
        'Permissões',
        'Permissões',
        'pb_ver_permissoes',
        'pb_boletos_permissoes',
        'pb_pagina_permissoes'
    );
}

add_action('admin_post_pb_importar_zip', 'pb_importar_zip');
add_action('admin_post_pb_excluir_boletos', 'pb_excluir_boletos');
add_action('admin_post_pb_excluir_boletos_antigos', 'pb_excluir_boletos_antigos');

add_action('admin_post_pb_agendar_envio_boletos', 'pb_agendar_envio_boletos');
add_action('pb_processar_fila_envio_boletos', 'pb_processar_fila_envio_boletos');

add_action('admin_post_pb_atualizar_email_cliente', 'pb_atualizar_email_cliente');
add_action('admin_post_pb_atualizar_cliente',           'pb_atualizar_cliente');
add_action('admin_post_pb_reprocessar_dados_clientes',  'pb_reprocessar_dados_clientes');

add_action('admin_post_pb_salvar_permissoes_usuario', 'pb_salvar_permissoes_usuario');

add_action('admin_post_pb_marcar_nao_enviado', 'pb_marcar_nao_enviado');

add_action('admin_post_pb_registrar_pagamento', 'pb_registrar_pagamento');
add_action('admin_post_pb_reverter_pagamento',  'pb_reverter_pagamento');
add_action('admin_post_pb_exportar_csv',        'pb_exportar_csv');
add_action('admin_post_pb_relatorio_pagamentos', 'pb_relatorio_pagamentos');
add_action('admin_post_pb_salvar_observacao',   'pb_salvar_observacao');
add_action('admin_post_pb_excluir_logs_selecionados', 'pb_excluir_logs_selecionados');
add_action('admin_post_pb_marcar_notificacoes_lidas', 'pb_marcar_notificacoes_lidas');
add_action('admin_post_pb_relatorio_logs',            'pb_relatorio_logs');
add_action('admin_init', 'pb_migrar_banco_v2');

function pb_migrar_banco_v2() {
    global $wpdb;

    // Coluna observação nos boletos
    $tabela = $wpdb->prefix . 'pb_boletos';
    $colunas = $wpdb->get_results("SHOW COLUMNS FROM $tabela");
    $nomes = array_column($colunas, 'Field');
    if (!in_array('observacao', $nomes, true)) {
        $wpdb->query("ALTER TABLE $tabela ADD COLUMN observacao TEXT DEFAULT NULL");
    }

    // Novas colunas na tabela de clientes
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    $colunas_c = $wpdb->get_results("SHOW COLUMNS FROM $tabela_clientes");
    $nomes_c   = array_column($colunas_c, 'Field');
    if (!in_array('nr_documento', $nomes_c, true)) {
        $wpdb->query("ALTER TABLE $tabela_clientes ADD COLUMN nr_documento VARCHAR(30) DEFAULT ''");
    }
    if (!in_array('endereco', $nomes_c, true)) {
        $wpdb->query("ALTER TABLE $tabela_clientes ADD COLUMN endereco VARCHAR(255) DEFAULT ''");
    }
    if (!in_array('whatsapp', $nomes_c, true)) {
        $wpdb->query("ALTER TABLE $tabela_clientes ADD COLUMN whatsapp VARCHAR(30) DEFAULT ''");
    }

    // Tabela de notificações internas
    $tabela_notif   = $wpdb->prefix . 'pb_notificacoes';
    $charset_collate = $wpdb->get_charset_collate();
    if ($wpdb->get_var("SHOW TABLES LIKE '$tabela_notif'") !== $tabela_notif) {
        $wpdb->query("CREATE TABLE $tabela_notif (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(30) NOT NULL DEFAULT 'info',
            mensagem TEXT NOT NULL,
            lida TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lida (lida)
        ) $charset_collate");
    }
}

function pb_criar_notificacao($tipo, $mensagem) {
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'pb_notificacoes',
        ['tipo' => $tipo, 'mensagem' => sanitize_textarea_field($mensagem), 'lida' => 0],
        ['%s', '%s', '%d']
    );
}

function pb_marcar_notificacoes_lidas() {
    if (!pb_usuario_pode('pb_ver_boletos')) wp_die('Sem permissão.');
    check_admin_referer('pb_notificacoes_action', 'pb_nonce');
    global $wpdb;
    $wpdb->update($wpdb->prefix . 'pb_notificacoes', ['lida' => 1], ['lida' => 0], ['%d'], ['%d']);
    wp_redirect(admin_url('admin.php?page=pb_boletos'));
    exit;
}

function pb_salvar_observacao() {
    if (!pb_usuario_pode('pb_ver_boletos')) wp_die('Sem permissão.');
    check_admin_referer('pb_salvar_observacao_action', 'pb_nonce');

    $boleto_id   = isset($_POST['boleto_id'])  ? intval($_POST['boleto_id'])                        : 0;
    $observacao  = isset($_POST['observacao']) ? sanitize_textarea_field($_POST['observacao'])       : '';

    if (!$boleto_id) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&obs=erro'));
        exit;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'pb_boletos',
        ['observacao' => $observacao],
        ['id' => $boleto_id],
        ['%s'], ['%d']
    );

    pb_registrar_log('observacao_boleto', "Observação salva no boleto #{$boleto_id}: {$observacao}");

    wp_redirect(admin_url('admin.php?page=pb_boletos&obs=ok'));
    exit;
}

function pb_excluir_logs_selecionados() {
    if (!current_user_can('manage_options')) wp_die('Apenas administradores podem excluir logs.');
    check_admin_referer('pb_excluir_logs_action', 'pb_nonce');

    if (empty($_POST['logs']) || !is_array($_POST['logs'])) {
        wp_redirect(admin_url('admin.php?page=pb_boletos_logs&logs_excluidos=0'));
        exit;
    }

    global $wpdb;
    $ids = array_filter(array_map('intval', $_POST['logs']));
    $excluidos = 0;
    foreach ($ids as $id) {
        $wpdb->delete($wpdb->prefix . 'pb_logs', ['id' => $id], ['%d']);
        $excluidos++;
    }

    wp_redirect(admin_url('admin.php?page=pb_boletos_logs&logs_excluidos=' . $excluidos));
    exit;
}

// ---------------------------------------------------------------
// Relatório de Logs em HTML — mesmo layout do relatório de pagamentos
// ---------------------------------------------------------------
function pb_relatorio_logs() {
    if (!pb_usuario_pode('pb_ver_logs')) wp_die('Sem permissão.');
    check_admin_referer('pb_relatorio_logs_action', 'pb_nonce');

    global $wpdb;
    $tabela_logs = $wpdb->prefix . 'pb_logs';

    $filtro_usuario = isset($_POST['filtro_usuario']) ? intval($_POST['filtro_usuario'])                : 0;
    $filtro_acao    = isset($_POST['filtro_acao'])    ? sanitize_text_field($_POST['filtro_acao'])      : '';
    $data_inicio    = isset($_POST['data_inicio'])    ? sanitize_text_field($_POST['data_inicio'])      : '';
    $data_fim       = isset($_POST['data_fim'])       ? sanitize_text_field($_POST['data_fim'])         : '';

    $where  = 'WHERE 1=1';
    $params = [];
    if ($filtro_usuario) { $where .= ' AND usuario_id = %d'; $params[] = $filtro_usuario; }
    if ($filtro_acao)    { $where .= ' AND acao = %s';       $params[] = $filtro_acao; }
    if ($data_inicio)    { $where .= ' AND created_at >= %s'; $params[] = $data_inicio . ' 00:00:00'; }
    if ($data_fim)       { $where .= ' AND created_at <= %s'; $params[] = $data_fim . ' 23:59:59'; }

    $sql  = "SELECT * FROM $tabela_logs $where ORDER BY id DESC LIMIT 1000";
    $logs = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

    $titulo_filtro = [];
    if ($filtro_usuario) {
        $nome = $wpdb->get_var($wpdb->prepare("SELECT usuario_nome FROM $tabela_logs WHERE usuario_id = %d LIMIT 1", $filtro_usuario));
        if ($nome) $titulo_filtro[] = 'Funcionário: ' . $nome;
    }
    if ($filtro_acao)  $titulo_filtro[] = 'Ação: ' . $filtro_acao;
    if ($data_inicio)  $titulo_filtro[] = 'De: ' . date('d/m/Y', strtotime($data_inicio));
    if ($data_fim)     $titulo_filtro[] = 'Até: ' . date('d/m/Y', strtotime($data_fim));
    $subtitulo = !empty($titulo_filtro) ? implode(' &nbsp;|&nbsp; ', $titulo_filtro) : 'Todos os registros';

    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Logs</title>
        <style>
            * { box-sizing:border-box; margin:0; padding:0; }
            body { font-family:'Segoe UI',Arial,sans-serif; background:#f1f5f9; color:#0f172a; font-size:13px; }
            .page { max-width:1280px; margin:0 auto; padding:28px 20px; }
            .header { background:linear-gradient(135deg,#071f46 0%,#0b5ed7 100%); border-radius:16px; padding:24px 28px; color:#fff; display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; box-shadow:0 8px 30px rgba(11,44,97,.25); }
            .header h1 { font-size:22px; font-weight:800; letter-spacing:-.02em; margin-bottom:4px; }
            .header p  { color:rgba(255,255,255,.7); font-size:12px; }
            .header-right { text-align:right; }
            .header-right .label { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:rgba(255,255,255,.6); margin-bottom:4px; }
            .header-right .data  { font-size:15px; font-weight:800; }
            .resumo { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:20px; }
            .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; box-shadow:0 2px 8px rgba(15,23,42,.04); }
            .card .card-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:8px; }
            .card .card-value { font-size:20px; font-weight:900; color:#0f172a; line-height:1; }
            .card.azul { border-left:3px solid #3b82f6; }
            .acoes { display:flex; gap:8px; margin-bottom:16px; }
            .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 18px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; border:none; }
            .btn-primary { background:#0b5ed7; color:#fff; }
            .btn-outline { background:#fff; color:#475569; border:1px solid #e2e8f0; }
            .tabela-wrap { background:#fff; border-radius:14px; border:1px solid #e2e8f0; overflow:hidden; box-shadow:0 2px 8px rgba(15,23,42,.04); }
            .tabela-header { padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc; }
            .tabela-header-title { font-weight:700; font-size:13px; color:#0f172a; }
            .tabela-header-count { font-size:11px; color:#94a3b8; font-weight:600; }
            table { width:100%; border-collapse:collapse; }
            thead th { padding:9px 12px; text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#64748b; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
            tbody td { padding:9px 12px; border-bottom:1px solid #f1f5f9; font-size:12px; color:#334155; vertical-align:top; }
            tbody tr:last-child td { border-bottom:none; }
            tbody tr:nth-child(even) td { background:#fafcff; }
            .badge-acao { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; background:#f1f5f9; color:#475569; white-space:nowrap; }
            pre { white-space:pre-wrap; font-family:inherit; font-size:11px; color:#64748b; line-height:1.5; margin:0; }
            .rodape { margin-top:20px; display:flex; justify-content:space-between; color:#94a3b8; font-size:11px; }
            @media print {
                body { background:#fff; }
                .page { padding:12px; max-width:100%; }
                .acoes { display:none !important; }
                .header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
                thead th { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            }
        </style>
    </head>
    <body>
    <div class="page">
        <div class="header">
            <div>
                <h1>Relatório de Logs</h1>
                <p><?php echo esc_html($subtitulo); ?></p>
            </div>
            <div class="header-right">
                <div class="label">Gerado em</div>
                <div class="data"><?php echo date('d/m/Y H:i'); ?></div>
            </div>
        </div>

        <div class="resumo">
            <div class="card azul">
                <div class="card-label">Total de logs</div>
                <div class="card-value"><?php echo count($logs); ?></div>
            </div>
            <div class="card azul">
                <div class="card-label">Período</div>
                <div class="card-value" style="font-size:13px;">
                    <?php echo ($data_inicio ? date('d/m/Y', strtotime($data_inicio)) : '—') . ' → ' . ($data_fim ? date('d/m/Y', strtotime($data_fim)) : '—'); ?>
                </div>
            </div>
            <div class="card azul">
                <div class="card-label">Gerado em</div>
                <div class="card-value" style="font-size:14px;"><?php echo date('d/m/Y H:i'); ?></div>
            </div>
        </div>

        <div class="acoes">
            <button class="btn btn-primary" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
            <button class="btn btn-outline" onclick="window.close()">✕ Fechar guia</button>
        </div>

        <div class="tabela-wrap">
            <div class="tabela-header">
                <span class="tabela-header-title">Detalhamento de logs</span>
                <span class="tabela-header-count"><?php echo count($logs); ?> registro<?php echo count($logs) !== 1 ? 's' : ''; ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Funcionário</th>
                        <th>Ação</th>
                        <th>Detalhes</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:28px;">Nenhum log encontrado.</td></tr>
                    <?php else : ?>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td style="white-space:nowrap; color:#475569;">
                                    <?php echo date('d/m/Y', strtotime($log->created_at)); ?><br>
                                    <span style="font-size:10px; color:#94a3b8;"><?php echo date('H:i:s', strtotime($log->created_at)); ?></span>
                                </td>
                                <td style="font-weight:600; white-space:nowrap;"><?php echo esc_html($log->usuario_nome); ?></td>
                                <td><span class="badge-acao"><?php echo esc_html($log->acao); ?></span></td>
                                <td><pre><?php echo esc_html($log->detalhes); ?></pre></td>
                                <td style="color:#94a3b8; white-space:nowrap; font-size:11px;"><?php echo esc_html($log->ip); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="rodape">
            <span>ASSC Saúde &nbsp;·&nbsp; Portal de Boletos</span>
            <span>Gerado em <?php echo date('d/m/Y \à\s H:i'); ?></span>
        </div>
    </div>
    <script>window.onload = function() { window.print(); };</script>
    </body>
    </html>
    <?php
    exit;
}

add_action('admin_post_pb_salvar_template_email', 'pb_salvar_template_email');
add_action('admin_post_pb_remover_anexo_extra_email', 'pb_remover_anexo_extra_email');

add_action('admin_post_pb_limpar_logs', 'pb_limpar_logs');
add_action('admin_post_pb_excluir_clientes', 'pb_excluir_clientes');

add_action('admin_post_pb_salvar_funcionario', 'pb_salvar_funcionario');
add_action('admin_post_pb_excluir_funcionario', 'pb_excluir_funcionario');

add_action('admin_post_pb_atualizar_funcionario', 'pb_atualizar_funcionario');


// ---------------------------------------------------------------
// Marca um boleto como pago
// ---------------------------------------------------------------
function pb_registrar_pagamento() {
    if ( ! pb_usuario_pode( 'pb_registrar_pagamento' ) ) {
        wp_die( 'Sem permissão para registrar pagamento.' );
    }

    check_admin_referer( 'pb_registrar_pagamento_action', 'pb_nonce' );

    $boleto_id = isset( $_POST['boleto_id'] ) ? intval( $_POST['boleto_id'] ) : 0;
    $pago_em   = isset( $_POST['pago_em'] )   ? sanitize_text_field( $_POST['pago_em'] ) : '';

    if ( ! $boleto_id || empty( $pago_em ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pago_em ) ) {
        wp_redirect( admin_url( 'admin.php?page=pb_boletos&pagamento=erro' ) );
        exit;
    }

    global $wpdb;
    $tabela  = $wpdb->prefix . 'pb_boletos';
    $usuario = get_current_user_id();

    $boleto = $wpdb->get_row( $wpdb->prepare(
        "SELECT b.*, c.nome, c.cpf
         FROM $tabela b
         LEFT JOIN {$wpdb->prefix}pb_clientes c ON c.id = b.cliente_id
         WHERE b.id = %d LIMIT 1",
        $boleto_id
    ) );

    if ( ! $boleto ) {
        wp_redirect( admin_url( 'admin.php?page=pb_boletos&pagamento=erro' ) );
        exit;
    }

    $wpdb->update(
        $tabela,
        [
            'status_pagamento' => 'pago',
            'pago_em'          => $pago_em,
            'pago_por'         => $usuario,
        ],
        [ 'id' => $boleto_id ],
        [ '%s', '%s', '%d' ],
        [ '%d' ]
    );

    $data_fmt = date( 'd/m/Y', strtotime( $pago_em ) );

    pb_registrar_log(
        'baixa_pagamento',
        "Pagamento registrado:\n" .
        "- Cliente: {$boleto->nome}\n" .
        "- CPF: {$boleto->cpf}\n" .
        "- Vigência: {$boleto->mes_referencia}\n" .
        "- Data de pagamento: {$data_fmt}"
    );

    wp_redirect( admin_url( 'admin.php?page=pb_boletos&pagamento=ok' ) );
    exit;
}

// ---------------------------------------------------------------
// Reverte um boleto de pago → pendente
// ---------------------------------------------------------------
function pb_reverter_pagamento() {
    if ( ! pb_usuario_pode( 'pb_registrar_pagamento' ) ) {
        wp_die( 'Sem permissão para reverter pagamento.' );
    }

    check_admin_referer( 'pb_reverter_pagamento_action', 'pb_nonce' );

    $boleto_id = isset( $_POST['boleto_id'] ) ? intval( $_POST['boleto_id'] ) : 0;

    if ( ! $boleto_id ) {
        wp_redirect( admin_url( 'admin.php?page=pb_boletos&pagamento=erro' ) );
        exit;
    }

    global $wpdb;
    $tabela = $wpdb->prefix . 'pb_boletos';

    $boleto = $wpdb->get_row( $wpdb->prepare(
        "SELECT b.*, c.nome, c.cpf
         FROM $tabela b
         LEFT JOIN {$wpdb->prefix}pb_clientes c ON c.id = b.cliente_id
         WHERE b.id = %d LIMIT 1",
        $boleto_id
    ) );

    if ( ! $boleto ) {
        wp_redirect( admin_url( 'admin.php?page=pb_boletos&pagamento=erro' ) );
        exit;
    }

    $wpdb->update(
        $tabela,
        [
            'status_pagamento' => 'pendente',
            'pago_em'          => null,
            'pago_por'         => null,
        ],
        [ 'id' => $boleto_id ],
        [ '%s', '%s', '%d' ],
        [ '%d' ]
    );

    pb_registrar_log(
        'reversao_pagamento',
        "Pagamento revertido para pendente:\n" .
        "- Cliente: {$boleto->nome}\n" .
        "- CPF: {$boleto->cpf}\n" .
        "- Vigência: {$boleto->mes_referencia}"
    );

    wp_redirect( admin_url( 'admin.php?page=pb_boletos&pagamento=revertido' ) );
    exit;
}

// ---------------------------------------------------------------
// Exportar boletos como CSV
// ---------------------------------------------------------------
function pb_exportar_csv() {
    if ( ! pb_usuario_pode( 'pb_ver_boletos' ) ) {
        wp_die( 'Sem permissão para exportar boletos.' );
    }

    check_admin_referer( 'pb_exportar_csv_action', 'pb_nonce' );

    global $wpdb;
    $tabela_boletos  = $wpdb->prefix . 'pb_boletos';
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';

    // Respeita os mesmos filtros da tela
    $filtro_mes            = isset( $_POST['filtro_mes'] )            ? sanitize_text_field( $_POST['filtro_mes'] )            : '';
    $filtro_cliente        = isset( $_POST['filtro_cliente'] )        ? sanitize_text_field( $_POST['filtro_cliente'] )        : '';
    $filtro_status         = isset( $_POST['filtro_status'] )         ? sanitize_text_field( $_POST['filtro_status'] )         : '';
    $filtro_status_pgto    = isset( $_POST['filtro_status_pgto'] )    ? sanitize_text_field( $_POST['filtro_status_pgto'] )    : '';

    $where  = 'WHERE 1=1';
    $params = [];

    if ( ! empty( $filtro_mes ) ) {
        $where   .= ' AND b.mes_referencia = %s';
        $params[] = $filtro_mes;
    }
    if ( ! empty( $filtro_cliente ) ) {
        $busca    = '%' . $wpdb->esc_like( $filtro_cliente ) . '%';
        $where   .= ' AND (c.nome LIKE %s OR c.cpf LIKE %s)';
        $params[] = $busca;
        $params[] = $busca;
    }
    if ( ! empty( $filtro_status ) ) {
        $where   .= ' AND b.email_status = %s';
        $params[] = $filtro_status;
    }
    if ( ! empty( $filtro_status_pgto ) ) {
        $where   .= ' AND b.status_pagamento = %s';
        $params[] = $filtro_status_pgto;
    }

    $sql = "
        SELECT
            b.id,
            c.nome,
            c.cpf,
            c.email,
            b.mes_referencia,
            b.vencimento,
            b.valor,
            b.email_status,
            b.status_pagamento,
            b.pago_em,
            b.created_at
        FROM $tabela_boletos b
        LEFT JOIN $tabela_clientes c ON c.id = b.cliente_id
        $where
        ORDER BY b.id DESC
    ";

    $boletos = ! empty( $params )
        ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
        : $wpdb->get_results( $sql, ARRAY_A );

    $nome_arquivo = 'boletos-' . date( 'Y-m-d-His' ) . '.csv';

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $nome_arquivo . '"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    $saida = fopen( 'php://output', 'w' );

    // BOM para Excel reconhecer UTF-8
    fputs( $saida, "\xEF\xBB\xBF" );

    // Cabeçalho
    fputcsv( $saida, [
        'ID',
        'Cliente',
        'CPF',
        'E-mail',
        'Mês Referência',
        'Vencimento',
        'Valor (R$)',
        'Status E-mail',
        'Status Pagamento',
        'Pago Em',
        'Data Importação',
    ], ';' );

    foreach ( $boletos as $row ) {
        fputcsv( $saida, [
            $row['id'],
            $row['nome'],
            $row['cpf'],
            $row['email'],
            $row['mes_referencia'],
            ! empty( $row['vencimento'] )  ? date( 'd/m/Y', strtotime( $row['vencimento'] ) )  : '',
            ! empty( $row['valor'] )       ? number_format( (float) $row['valor'], 2, ',', '.' ) : '',
            $row['email_status'],
            $row['status_pagamento'],
            ! empty( $row['pago_em'] )     ? date( 'd/m/Y', strtotime( $row['pago_em'] ) )      : '',
            ! empty( $row['created_at'] )  ? date( 'd/m/Y H:i', strtotime( $row['created_at'] ) ) : '',
        ], ';' );
    }

    fclose( $saida );
    exit;
}

// ---------------------------------------------------------------
// Relatório de Pagamentos em HTML para impressão como PDF
// ---------------------------------------------------------------
function pb_relatorio_pagamentos() {
    if ( ! pb_usuario_pode( 'pb_ver_boletos' ) ) {
        wp_die( 'Sem permissão.' );
    }

    check_admin_referer( 'pb_relatorio_pagamentos_action', 'pb_nonce' );

    global $wpdb;
    $tabela_boletos  = $wpdb->prefix . 'pb_boletos';
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';

    $filtro_mes         = isset( $_POST['filtro_mes'] )         ? sanitize_text_field( $_POST['filtro_mes'] )         : '';
    $filtro_cliente     = isset( $_POST['filtro_cliente'] )     ? sanitize_text_field( $_POST['filtro_cliente'] )     : '';
    $filtro_status      = isset( $_POST['filtro_status'] )      ? sanitize_text_field( $_POST['filtro_status'] )      : '';
    $filtro_status_pgto = isset( $_POST['filtro_status_pgto'] ) ? sanitize_text_field( $_POST['filtro_status_pgto'] ) : '';

    $where  = 'WHERE 1=1';
    $params = [];

    if ( ! empty( $filtro_mes ) ) {
        $where   .= ' AND b.mes_referencia = %s';
        $params[] = $filtro_mes;
    }
    if ( ! empty( $filtro_cliente ) ) {
        $busca    = '%' . $wpdb->esc_like( $filtro_cliente ) . '%';
        $where   .= ' AND (c.nome LIKE %s OR c.cpf LIKE %s)';
        $params[] = $busca;
        $params[] = $busca;
    }
    if ( ! empty( $filtro_status ) ) {
        $where   .= ' AND b.email_status = %s';
        $params[] = $filtro_status;
    }
    if ( ! empty( $filtro_status_pgto ) ) {
        $where   .= ' AND b.status_pagamento = %s';
        $params[] = $filtro_status_pgto;
    }

    $sql = "
        SELECT b.*, c.nome, c.cpf, c.email, c.nr_documento
        FROM $tabela_boletos b
        LEFT JOIN $tabela_clientes c ON c.id = b.cliente_id
        $where
        ORDER BY c.nome ASC, b.vencimento ASC
    ";

    $boletos = ! empty( $params )
        ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
        : $wpdb->get_results( $sql );

    $total_boletos  = count( $boletos );
    $total_pagos    = count( array_filter( $boletos, function($b) { return $b->status_pagamento === 'pago'; } ) );
    $total_abertos  = $total_boletos - $total_pagos;
    $valor_total    = array_sum( array_column( $boletos, 'valor' ) );
    $valor_pago     = array_sum( array_map( function($b) { return $b->status_pagamento === 'pago' ? (float)$b->valor : 0; }, $boletos ) );
    $valor_aberto   = $valor_total - $valor_pago;

    $titulo_filtro  = [];
    if ( $filtro_mes )         $titulo_filtro[] = 'Mês: ' . $filtro_mes;
    if ( $filtro_cliente )     $titulo_filtro[] = 'Cliente: ' . $filtro_cliente;
    if ( $filtro_status_pgto ) $titulo_filtro[] = 'Pagamento: ' . ucfirst( $filtro_status_pgto );
    $subtitulo = ! empty( $titulo_filtro ) ? implode( ' &nbsp;|&nbsp; ', $titulo_filtro ) : 'Todos os registros';

    header( 'Content-Type: text/html; charset=UTF-8' );
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Relatório de Pagamentos</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                background: #f1f5f9;
                color: #0f172a;
                font-size: 13px;
            }

            .page {
                max-width: 1060px;
                margin: 0 auto;
                padding: 28px 20px;
            }

            /* Cabeçalho */
            .header {
                background: linear-gradient(135deg, #071f46 0%, #0b5ed7 100%);
                border-radius: 16px;
                padding: 24px 28px;
                color: #fff;
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                box-shadow: 0 8px 30px rgba(11,44,97,.25);
            }

            .header-left h1 {
                font-size: 22px;
                font-weight: 800;
                letter-spacing: -.02em;
                margin-bottom: 4px;
            }

            .header-left p {
                color: rgba(255,255,255,.7);
                font-size: 12px;
            }

            .header-right {
                text-align: right;
            }

            .header-right .label {
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: .06em;
                color: rgba(255,255,255,.6);
                margin-bottom: 4px;
            }

            .header-right .data {
                font-size: 15px;
                font-weight: 800;
                color: #fff;
            }

            /* Cards de resumo */
            .resumo {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 12px;
                margin-bottom: 20px;
            }

            .card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 14px 16px;
                box-shadow: 0 2px 8px rgba(15,23,42,.04);
            }

            .card .card-label {
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .06em;
                color: #94a3b8;
                margin-bottom: 8px;
            }

            .card .card-value {
                font-size: 20px;
                font-weight: 900;
                color: #0f172a;
                line-height: 1;
            }

            .card.verde  { border-left: 3px solid #22c55e; }
            .card.verde .card-value { color: #16a34a; }
            .card.amarelo { border-left: 3px solid #f59e0b; }
            .card.amarelo .card-value { color: #d97706; }
            .card.azul { border-left: 3px solid #3b82f6; }

            /* Barra de ações */
            .acoes {
                display: flex;
                gap: 8px;
                margin-bottom: 16px;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                border: none;
                transition: opacity .15s;
            }

            .btn:hover { opacity: .88; }

            .btn-primary { background: #0b5ed7; color: #fff; }
            .btn-outline { background: #fff; color: #475569; border: 1px solid #e2e8f0; }

            /* Tabela */
            .tabela-wrap {
                background: #fff;
                border-radius: 14px;
                border: 1px solid #e2e8f0;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(15,23,42,.04);
            }

            .tabela-header {
                padding: 14px 18px;
                border-bottom: 1px solid #e2e8f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #f8fafc;
            }

            .tabela-header-title {
                font-weight: 700;
                font-size: 13px;
                color: #0f172a;
            }

            .tabela-header-count {
                font-size: 11px;
                color: #94a3b8;
                font-weight: 600;
            }

            table { width: 100%; border-collapse: collapse; }

            thead th {
                padding: 9px 12px;
                text-align: left;
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .06em;
                color: #64748b;
                border-bottom: 1px solid #e2e8f0;
                background: #f8fafc;
            }

            tbody td {
                padding: 10px 12px;
                border-bottom: 1px solid #f1f5f9;
                font-size: 12px;
                color: #334155;
                vertical-align: middle;
            }

            tbody tr:last-child td { border-bottom: none; }
            tbody tr:nth-child(even) td { background: #fafcff; }

            .badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 9px;
                border-radius: 999px;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .03em;
                white-space: nowrap;
            }

            .badge-pago    { background: #dcfce7; color: #166534; }
            .badge-pendente { background: #fef3c7; color: #92400e; }

            .num { color: #94a3b8; font-size: 11px; }
            .nome { font-weight: 600; color: #0f172a; }
            .valor { font-weight: 700; }

            /* Rodapé */
            .rodape {
                margin-top: 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                color: #94a3b8;
                font-size: 11px;
            }

            /* Impressão */
            @media print {
                body { background: #fff; }
                .page { padding: 12px; max-width: 100%; }
                .acoes { display: none !important; }
                .header, .badge, .card { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
    <div class="page">

        <div class="header">
            <div class="header-left">
                <h1>Relatório de Pagamentos</h1>
                <p><?php echo esc_html( $subtitulo ); ?></p>
            </div>
            <div class="header-right">
                <div class="label">Gerado em</div>
                <div class="data"><?php echo date('d/m/Y H:i'); ?></div>
            </div>
        </div>

        <div class="resumo">
            <div class="card azul">
                <div class="card-label">Total</div>
                <div class="card-value"><?php echo $total_boletos; ?></div>
            </div>
            <div class="card verde">
                <div class="card-label">Pagos</div>
                <div class="card-value"><?php echo $total_pagos; ?></div>
            </div>
            <div class="card amarelo">
                <div class="card-label">Em aberto</div>
                <div class="card-value"><?php echo $total_abertos; ?></div>
            </div>
            <div class="card verde">
                <div class="card-label">Valor recebido</div>
                <div class="card-value" style="font-size:14px;">R$ <?php echo number_format( $valor_pago, 2, ',', '.' ); ?></div>
            </div>
            <div class="card amarelo">
                <div class="card-label">Valor em aberto</div>
                <div class="card-value" style="font-size:14px;">R$ <?php echo number_format( $valor_aberto, 2, ',', '.' ); ?></div>
            </div>
        </div>

        <div class="acoes">
            <button class="btn btn-primary" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
            <button class="btn btn-outline" onclick="window.close(); return false;">✕ Fechar guia</button>
        </div>

        <div class="tabela-wrap">
            <div class="tabela-header">
                <span class="tabela-header-title">Detalhamento por boleto</span>
                <span class="tabela-header-count"><?php echo $total_boletos; ?> registro<?php echo $total_boletos !== 1 ? 's' : ''; ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th style="white-space:nowrap;">NR BANCO</th>
                        <th>Cliente</th>
                        <th>CPF</th>
                        <th style="white-space:nowrap;">E-mail</th>
                        <th>Mês</th>
                        <th>Vencimento</th>
                        <th style="text-align:right; white-space:nowrap;">Valor R$</th>
                        <th>Pagamento</th>
                        <th style="white-space:nowrap;">Pago em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $boletos ) ) : ?>
                        <tr>
                            <td colspan="9" style="text-align:center; color:#94a3b8; padding:28px;">
                                Nenhum boleto encontrado com os filtros aplicados.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $boletos as $i => $b ) : ?>
                            <tr>
                                <td class="num"><?php echo $i + 1; ?></td>
                                <td style="color:#475569; white-space:nowrap; font-weight:600;"><?php echo esc_html( isset($b->nr_documento) && !empty($b->nr_documento) ? $b->nr_documento : '—' ); ?></td>
                                <td class="nome"><?php echo esc_html( $b->nome ); ?></td>
                                <td style="color:#64748b;"><?php echo esc_html( $b->cpf ); ?></td>
                                <td style="color:#64748b; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo ! empty( $b->email ) ? esc_html( $b->email ) : '<span style="color:#cbd5e1;">—</span>'; ?></td>
                                <td><?php echo esc_html( $b->mes_referencia ?: '—' ); ?></td>
                                <td><?php echo ! empty( $b->vencimento ) ? date( 'd/m/Y', strtotime( $b->vencimento ) ) : '—'; ?></td>
                                <td class="valor" style="text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap;"><?php echo number_format( (float) $b->valor, 2, ',', '.' ); ?></td>
                                <td>
                                    <?php if ( $b->status_pagamento === 'pago' ) : ?>
                                        <span class="badge badge-pago">✓ Pago</span>
                                    <?php else : ?>
                                        <span class="badge badge-pendente">Pendente</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#64748b; white-space:nowrap;"><?php echo ! empty( $b->pago_em ) ? date( 'd/m/Y', strtotime( $b->pago_em ) ) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="rodape">
            <span>ASSC Saúde &nbsp;·&nbsp; Portal de Boletos</span>
            <span>Gerado em <?php echo date('d/m/Y \à\s H:i'); ?></span>
        </div>

    </div>
    <script>
        window.onload = function() { window.print(); };
    </script>
    </body>
    </html>
    <?php
    exit;
}

function pb_marcar_nao_enviado() {
    if (!pb_usuario_pode('pb_enviar_boletos')) {
        wp_die('Sem permissão.');
    }

    check_admin_referer('pb_excluir_boletos_action', 'pb_nonce');

    if (empty($_POST['boletos']) || !is_array($_POST['boletos'])) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&reenvio=nenhum'));
        exit;
    }

    global $wpdb;

    $tabela_boletos = $wpdb->prefix . 'pb_boletos';
    $ids = array_filter(array_map('intval', $_POST['boletos']));

    if (empty($ids)) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&reenvio=nenhum'));
        exit;
    }

    foreach ($ids as $id) {
        $wpdb->update(
            $tabela_boletos,
            [
                'email_status'    => 'pendente',
                'email_tentativas'=> 0,
                'email_enviado_em'=> null,
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    pb_registrar_log(
        'marcado_para_reenvio',
        "Boletos marcados como pendentes novamente:\n" . pb_formatar_boletos_log($ids)
    );

    wp_redirect(admin_url('admin.php?page=pb_boletos&reenvio=ok'));
    exit;
}

function pb_importar_zip() {
    if (!pb_usuario_pode('pb_importar_boletos')) {
        wp_die('Sem permissão para importar boletos.');
    }

    check_admin_referer('pb_importar_zip_action', 'pb_nonce');

    if (!class_exists('ZipArchive')) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&erro=ziparchive'));
        exit;
    }

    if (empty($_FILES['arquivo_zip']['name'])) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&erro=sem_arquivo'));
        exit;
    }

    $arquivo = $_FILES['arquivo_zip'];

    if (!isset($arquivo['tmp_name']) || $arquivo['error'] !== UPLOAD_ERR_OK) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&erro=upload'));
        exit;
    }

    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        wp_redirect(admin_url('admin.php?page=pb_boletos&erro=tipo'));
        exit;
    }

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']) . 'portal-boletos';
    $zip_dir  = trailingslashit($base_dir) . 'tmp';
    $pdf_dir  = trailingslashit($base_dir) . 'boletos';

    wp_mkdir_p($base_dir);
    wp_mkdir_p($zip_dir);
    wp_mkdir_p($pdf_dir);

    $zip_path = trailingslashit($zip_dir) . 'importacao-' . time() . '.zip';

    if (!move_uploaded_file($arquivo['tmp_name'], $zip_path)) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&erro=salvar_zip'));
        exit;
    }

    $extract_dir = trailingslashit($zip_dir) . 'extraido-' . time();

    wp_mkdir_p($extract_dir);

    $zip = new ZipArchive();
    $open = $zip->open($zip_path);

    if ($open !== true) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&erro=abrir_zip'));
        exit;
    }

    $zip->extractTo($extract_dir);
    $zip->close();

    global $wpdb;
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    $tabela_boletos  = $wpdb->prefix . 'pb_boletos';

    $importados = 0;
    $ignorados = 0;
    $erros = 0;

    $arquivos = scandir($extract_dir);

    foreach ($arquivos as $nome_arquivo) {
        if ($nome_arquivo === '.' || $nome_arquivo === '..') {
            continue;
        }

        $caminho_temporario = trailingslashit($extract_dir) . $nome_arquivo;

        if (is_dir($caminho_temporario)) {
            continue;
        }

        $extensao = strtolower(pathinfo($nome_arquivo, PATHINFO_EXTENSION));
        if ($extensao !== 'pdf') {
            $ignorados++;
            continue;
        }

        $dados = pb_extrair_nome_cpf_do_arquivo($nome_arquivo);

        if (!$dados) {
            $erros++;
            continue;
        }

        $nome = $dados['nome'];
        $cpf  = $dados['cpf'];

        $cliente = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tabela_clientes WHERE cpf = %s", $cpf)
        );

        if (!$cliente) {
            $wpdb->insert(
                $tabela_clientes,
                [
                    'nome' => $nome,
                    'cpf'  => $cpf,
                ],
                ['%s', '%s']
            );

            $cliente_id = $wpdb->insert_id;
        } else {
            $cliente_id = $cliente->id;
        }

        $nome_final = sanitize_file_name($nome_arquivo);
        $nome_final = time() . '-' . wp_generate_password(6, false, false) . '-' . $nome_final;

        $caminho_final = trailingslashit($pdf_dir) . $nome_final;

        if (!rename($caminho_temporario, $caminho_final)) {
            $erros++;
            continue;
        }
        
        // 🔍 Extrair dados do PDF
        $dados_boleto = pb_extrair_dados_boleto_pdf($caminho_final);

        // Atualizar cliente com nr_documento e endereço se ainda não tiver
        if (!empty($dados_boleto['nr_documento']) || !empty($dados_boleto['endereco'])) {
            $atualizar_cliente = [];
            $formatos_cliente  = [];
            if (!empty($dados_boleto['nr_documento'])) {
                $atualizar_cliente['nr_documento'] = sanitize_text_field($dados_boleto['nr_documento']);
                $formatos_cliente[] = '%s';
            }
            if (!empty($dados_boleto['endereco'])) {
                $atualizar_cliente['endereco'] = sanitize_text_field($dados_boleto['endereco']);
                $formatos_cliente[] = '%s';
            }
            if (!empty($atualizar_cliente)) {
                $wpdb->update($tabela_clientes, $atualizar_cliente, ['id' => $cliente_id], $formatos_cliente, ['%d']);
            }
        }
        
        // 🚫 Verificar duplicidade (cliente + nosso número + vencimento)
        $existe = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $tabela_boletos 
                 WHERE cliente_id = %d 
                 AND nosso_numero = %s 
                 AND vencimento = %s 
                 LIMIT 1",
                $cliente_id,
                $dados_boleto['nosso_numero'],
                $dados_boleto['vencimento']
            )
        );
        
        if ($existe) {
            $ignorados++;
            continue;
        }
        
        // 💾 Salvar no banco
        // Extrair dados internos do PDF
        $dados_boleto = pb_extrair_dados_boleto_pdf($caminho_final);
        
        // Se não conseguir identificar a vigência, não importa
        if (empty($dados_boleto['mes_referencia'])) {
            @unlink($caminho_final);
            $erros++;
            continue;
        }
        
        // Verificar se já existe boleto desse cliente para esse mês
        $boleto_existente = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $tabela_boletos 
                 WHERE cliente_id = %d 
                 AND mes_referencia = %s 
                 LIMIT 1",
                $cliente_id,
                $dados_boleto['mes_referencia']
            )
        );
        
        if ($boleto_existente) {
            @unlink($caminho_final);
            $ignorados++;
            continue;
        }
        
        // Salvar novo boleto
        $wpdb->insert(
            $tabela_boletos,
            [
                'cliente_id'      => $cliente_id,
                'nome_arquivo'    => $nome_arquivo,
                'caminho_arquivo' => $caminho_final,
                'referencia'      => $dados_boleto['mes_referencia'],
                'mes_referencia'  => $dados_boleto['mes_referencia'],
                'vencimento'      => $dados_boleto['vencimento'],
                'valor'           => $dados_boleto['valor'],
                'nosso_numero'    => $dados_boleto['nosso_numero'],
                'linha_digitavel' => $dados_boleto['linha_digitavel'],
                'email_status'    => 'pendente',
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s']
        );
        
        $importados++;
    }

    pb_remover_diretorio($extract_dir);
    @unlink($zip_path);

    $url = add_query_arg(
        [
            'page'       => 'pb_boletos',
            'importados' => $importados,
            'ignorados'  => $ignorados,
            'erros'      => $erros,
        ],
        admin_url('admin.php')
    );
    
    pb_registrar_log(
        'importacao_zip',
        "Importação de ZIP concluída:\n" .
        "- Importados: {$importados}\n" .
        "- Ignorados: {$ignorados}\n" .
        "- Erros: {$erros}"
    );
    
    wp_redirect($url);
    exit;
}

function pb_excluir_boletos() {
    if (!pb_usuario_pode('pb_excluir_boletos')) {
        wp_die('Sem permissão para excluir boletos.');
    }

    check_admin_referer('pb_excluir_boletos_action', 'pb_nonce');

    if (empty($_POST['boletos']) || !is_array($_POST['boletos'])) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&excluidos=0'));
        exit;
    }

    global $wpdb;
    $tabela_boletos = $wpdb->prefix . 'pb_boletos';

    $ids = array_map('intval', $_POST['boletos']);
    $excluidos = 0;
    
    $detalhes_exclusao = pb_formatar_boletos_log($ids);

    foreach ($ids as $id) {
        if (!$id) {
            continue;
        }

        $boleto = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tabela_boletos WHERE id = %d", $id)
        );

        if (!$boleto) {
            continue;
        }

        if (!empty($boleto->caminho_arquivo) && file_exists($boleto->caminho_arquivo)) {
            @unlink($boleto->caminho_arquivo);
        }

        $wpdb->delete(
            $tabela_boletos,
            ['id' => $id],
            ['%d']
        );
        
        $cliente_log = $wpdb->get_row(
            $wpdb->prepare("SELECT nome, cpf FROM {$wpdb->prefix}pb_clientes WHERE id = %d", $boleto->cliente_id)
        );
        
        #pb_registrar_log('exclusao_boleto', [
        #    'boleto_id' => $id,
        #    'cliente' => $cliente_log ? $cliente_log->nome : 'Cliente não encontrado',
        #    'cpf' => $cliente_log ? $cliente_log->cpf : '',
        #    'arquivo' => $boleto->nome_arquivo,
        #    'mes' => $boleto->mes_referencia,
        #]);

        $excluidos++;
    }
    
    pb_registrar_log('exclusao_boletos', "Boletos excluídos:\n" . $detalhes_exclusao);

    wp_redirect(admin_url('admin.php?page=pb_boletos&excluidos=' . $excluidos));
    exit;
}

function pb_excluir_boletos_antigos() {
    if (!pb_usuario_pode('pb_excluir_boletos')) {
        wp_die('Sem permissão para excluir boletos antigos.');
    }

    check_admin_referer('pb_excluir_boletos_action', 'pb_nonce');

    global $wpdb;

    $tabela_boletos = $wpdb->prefix . 'pb_boletos';

    $data_limite = date('Y-m-d', strtotime('-6 months'));

    $boletos = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $tabela_boletos
             WHERE vencimento IS NOT NULL
             AND vencimento < %s",
            $data_limite
        )
    );

    if (empty($boletos)) {
        wp_redirect(admin_url('admin.php?page=pb_boletos&antigos_excluidos=0'));
        exit;
    }

    $ids = [];
    $excluidos = 0;

    foreach ($boletos as $boleto) {
        $ids[] = intval($boleto->id);

        if (!empty($boleto->caminho_arquivo) && file_exists($boleto->caminho_arquivo)) {
            @unlink($boleto->caminho_arquivo);
        }

        $wpdb->delete(
            $tabela_boletos,
            ['id' => $boleto->id],
            ['%d']
        );

        $excluidos++;
    }

    pb_registrar_log(
        'exclusao_boletos_antigos',
        "Limpeza manual de boletos antigos concluída:\n" .
        "- Critério: vencimento anterior a {$data_limite}\n" .
        "- Total excluído: {$excluidos}\n\n" .
        pb_formatar_boletos_log($ids)
    );

    wp_redirect(admin_url('admin.php?page=pb_boletos&antigos_excluidos=' . $excluidos));
    exit;
}

function pb_atualizar_email_cliente() {
    if (!pb_usuario_pode('pb_editar_email_cliente')) {
        wp_die('Sem permissão para editar e-mail de cliente.');
    }

    check_admin_referer('pb_atualizar_email_cliente_action', 'pb_nonce');

    $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

    $retorno = isset($_POST['retorno']) ? sanitize_text_field($_POST['retorno']) : '';

    $remover_email = isset($_POST['remover_email']) && $_POST['remover_email'] === '1';

    if (!$cliente_id || (!$remover_email && (empty($email) || !is_email($email)))) {
        if ($retorno === 'clientes') {
            wp_redirect(admin_url('admin.php?page=pb_boletos_clientes&email=erro'));
        } else {
            wp_redirect(admin_url('admin.php?page=pb_boletos&email=erro'));
        }
    
        exit;
    }

    global $wpdb;
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    
    $cliente_antigo = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $tabela_clientes WHERE id = %d", $cliente_id)
    );

    $wpdb->update(
        $tabela_clientes,
        [
            'email' => $remover_email ? '' : $email,
            'email_verificado' => $remover_email ? 0 : 1,
        ],
        ['id' => $cliente_id],
        ['%s', '%d'],
        ['%d']
    );
    
    $detalhes_log = "E-mail do cliente alterado:\n";
    $detalhes_log .= "- Cliente: " . ($cliente_antigo ? $cliente_antigo->nome : 'Cliente não encontrado') . "\n";
    $detalhes_log .= "- CPF: " . ($cliente_antigo ? $cliente_antigo->cpf : '-') . "\n";
    $detalhes_log .= "- E-mail anterior: " . ($cliente_antigo && !empty($cliente_antigo->email) ? $cliente_antigo->email : 'Sem e-mail') . "\n";
    $detalhes_log .= "- Novo e-mail: " . ($remover_email ? 'Sem e-mail' : $email);
    
    pb_registrar_log('alteracao_email_cliente', $detalhes_log);
    
    if ($retorno === 'clientes') {
        wp_redirect(admin_url('admin.php?page=pb_boletos_clientes&email=ok'));
    } else {
        wp_redirect(admin_url('admin.php?page=pb_boletos&email=ok'));
    }
    
    exit;
}

function pb_atualizar_cliente() {
    if (!pb_usuario_pode('pb_editar_email_cliente')) {
        wp_die('Sem permissão para editar cliente.');
    }

    check_admin_referer('pb_atualizar_cliente_action', 'pb_nonce');

    $cliente_id  = isset($_POST['cliente_id']) ? intval($_POST['cliente_id'])                              : 0;
    $email       = isset($_POST['email'])       ? sanitize_email($_POST['email'])                          : '';
    $whatsapp    = isset($_POST['whatsapp'])    ? sanitize_text_field($_POST['whatsapp'])                   : '';
    $endereco    = isset($_POST['endereco'])    ? sanitize_text_field($_POST['endereco'])                   : '';
    $nr_documento = isset($_POST['nr_documento']) ? sanitize_text_field($_POST['nr_documento'])             : '';

    if (!$cliente_id) {
        wp_redirect(admin_url('admin.php?page=pb_boletos_clientes&cliente=erro'));
        exit;
    }

    global $wpdb;
    $tabela = $wpdb->prefix . 'pb_clientes';

    $wpdb->update(
        $tabela,
        [
            'email'        => $email,
            'whatsapp'     => $whatsapp,
            'endereco'     => $endereco,
            'nr_documento' => $nr_documento,
        ],
        ['id' => $cliente_id],
        ['%s', '%s', '%s', '%s'],
        ['%d']
    );

    pb_registrar_log('edicao_cliente', "Dados do cliente #{$cliente_id} atualizados.");

    wp_redirect(admin_url('admin.php?page=pb_boletos_clientes&cliente=ok&ver=' . $cliente_id));
    exit;
}

function pb_reprocessar_dados_clientes() {
    if ( ! pb_usuario_pode( 'pb_importar_boletos' ) ) {
        wp_die( 'Sem permissão.' );
    }

    check_admin_referer( 'pb_reprocessar_dados_clientes_action', 'pb_nonce' );

    global $wpdb;
    $tabela_boletos  = $wpdb->prefix . 'pb_boletos';
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';

    // Buscar todos os boletos que têm PDF salvo
    $boletos = $wpdb->get_results( "
        SELECT b.id, b.cliente_id, b.caminho_arquivo, c.nr_documento, c.endereco
        FROM $tabela_boletos b
        INNER JOIN $tabela_clientes c ON c.id = b.cliente_id
        ORDER BY b.id ASC
    " );

    $atualizados  = 0;
    $sem_arquivo  = 0;
    $ja_completos = 0;

    // Agrupar por cliente para não reprocessar vários boletos do mesmo cliente
    $clientes_processados = [];

    foreach ( $boletos as $boleto ) {
        $cliente_id = intval( $boleto->cliente_id );

        // Pular se já processamos esse cliente nesta rodada
        if ( isset( $clientes_processados[ $cliente_id ] ) ) {
            continue;
        }

        // Pular se já tem todos os dados E o nr_documento parece correto (só dígitos)
        if (
            ! empty( $boleto->nr_documento ) && preg_match('/^\d+$/', $boleto->nr_documento) &&
            ! empty( $boleto->endereco )
        ) {
            $clientes_processados[ $cliente_id ] = true;
            $ja_completos++;
            continue;
        }

        // Verificar se o arquivo existe
        if ( empty( $boleto->caminho_arquivo ) || ! file_exists( $boleto->caminho_arquivo ) ) {
            $sem_arquivo++;
            continue;
        }

        // Extrair dados do PDF
        $dados = pb_extrair_dados_boleto_pdf( $boleto->caminho_arquivo );

        $atualizar = [];
        $formatos  = [];

        if ( ! empty( $dados['nr_documento'] ) ) {
            $atualizar['nr_documento'] = sanitize_text_field( $dados['nr_documento'] );
            $formatos[] = '%s';
        }

        if ( ! empty( $dados['endereco'] ) && empty( $boleto->endereco ) ) {
            $atualizar['endereco'] = sanitize_text_field( $dados['endereco'] );
            $formatos[] = '%s';
        }

        if ( ! empty( $atualizar ) ) {
            $wpdb->update( $tabela_clientes, $atualizar, [ 'id' => $cliente_id ], $formatos, [ '%d' ] );
            $atualizados++;
        }

        $clientes_processados[ $cliente_id ] = true;
    }

    pb_registrar_log(
        'reprocessamento_dados_clientes',
        "Reprocessamento concluído:\n" .
        "- Clientes atualizados: {$atualizados}\n" .
        "- Já completos: {$ja_completos}\n" .
        "- Sem arquivo PDF: {$sem_arquivo}"
    );

    wp_redirect( admin_url(
        'admin.php?page=pb_boletos_clientes'
        . '&reprocessado=ok'
        . '&atualizados=' . $atualizados
        . '&ja_completos=' . $ja_completos
        . '&sem_arquivo=' . $sem_arquivo
    ) );
    exit;
}

function pb_agendar_envio_boletos() {
    if (!pb_usuario_pode('pb_enviar_boletos')) {
        wp_die('Sem permissão para enviar boletos.');
    }

    check_admin_referer('pb_excluir_boletos_action', 'pb_nonce');

    global $wpdb;
    $usuario_id = get_current_user_id();
    $tabela_boletos = $wpdb->prefix . 'pb_boletos';

    if (!empty($_POST['enviar_todos'])) {
        $usuario_id = get_current_user_id();
    
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $tabela_boletos
                 SET email_status = 'agendado',
                     email_tentativas = 0,
                     email_enviado_por = %d
                 WHERE email_status = 'pendente'",
                $usuario_id
            )
        );
        
        pb_registrar_log(
            'agendamento_envio_todos',
            'Envio de todos os boletos pendentes foi agendado.'
        );
    } else {
        if (empty($_POST['boletos']) || !is_array($_POST['boletos'])) {
            wp_redirect(admin_url('admin.php?page=pb_boletos&envio=nenhum'));
            exit;
        }

        $ids = array_map('intval', $_POST['boletos']);
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_redirect(admin_url('admin.php?page=pb_boletos&envio=nenhum'));
            exit;
        }

        foreach ($ids as $id) {
            $usuario_id = get_current_user_id();

            $wpdb->update(
                $tabela_boletos,
                [
                    'email_status' => 'agendado',
                    'email_tentativas' => 0,
                    'email_enviado_por' => $usuario_id,
                ],
                ['id' => $id],
                ['%s', '%d', '%d'],
                ['%d']
            );
        }
        
        pb_registrar_log(
            'agendamento_envio_selecionados',
            "Envio agendado para os boletos:\n" . pb_formatar_boletos_log($ids)
        );
    }

    if (!wp_next_scheduled('pb_processar_fila_envio_boletos')) {
        wp_schedule_single_event(time() + 10, 'pb_processar_fila_envio_boletos');
        spawn_cron(time() + 10);
    }

    wp_redirect(admin_url('admin.php?page=pb_boletos&envio=agendado'));
    exit;
}

function pb_extrair_nome_cpf_do_arquivo($nome_arquivo) {
    $sem_extensao = pathinfo($nome_arquivo, PATHINFO_FILENAME);

    if (!preg_match('/^(.*)-(\d{3}\.?\d{3}\.?\d{3}\-?\d{2})$/', $sem_extensao, $matches)) {
        return false;
    }

    $nome = trim($matches[1]);
    $cpf  = preg_replace('/\D/', '', $matches[2]);

    if ($nome === '' || strlen($cpf) !== 11) {
        return false;
    }

    return [
        'nome' => $nome,
        'cpf'  => $cpf,
    ];
}

function pb_extrair_texto_pdf($caminho_pdf) {
    if (!class_exists('\Smalot\PdfParser\Parser')) {
        return '';
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($caminho_pdf);
        return $pdf->getText();
    } catch (Exception $e) {
        return '';
    }
}

function pb_converter_data_boleto($data_br) {
    $partes = explode('/', $data_br);

    if (count($partes) !== 3) {
        return null;
    }

    return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
}

function pb_converter_valor_boleto($valor_br) {
    $valor = str_replace('.', '', $valor_br);
    $valor = str_replace(',', '.', $valor);

    return number_format((float) $valor, 2, '.', '');
}

function pb_mes_referencia_por_vencimento($vencimento_sql) {
    if (empty($vencimento_sql)) {
        return '';
    }

    $timestamp = strtotime($vencimento_sql);

    if (!$timestamp) {
        return '';
    }

    return date('m/Y', $timestamp);
}

function pb_extrair_dados_boleto_pdf($caminho_pdf) {
    $texto = pb_extrair_texto_pdf($caminho_pdf);

    $dados = [
        'vencimento'      => null,
        'valor'           => null,
        'nosso_numero'    => '',
        'linha_digitavel' => '',
        'mes_referencia'  => '',
        'nr_documento'    => '',
        'endereco'        => '',
    ];

    if (empty($texto)) {
        return $dados;
    }

    $texto_limpo = preg_replace('/\s+/', ' ', $texto);

    if (preg_match('/Data de Vencimento\s+(\d{2}\/\d{2}\/\d{4})/i', $texto_limpo, $m)) {
        $dados['vencimento'] = pb_converter_data_boleto($m[1]);
        $dados['mes_referencia'] = pb_mes_referencia_por_vencimento($dados['vencimento']);
    }

    if (preg_match('/Valor do Documento\s+([\d\.]+,\d{2})/i', $texto_limpo, $m)) {
        $dados['valor'] = pb_converter_valor_boleto($m[1]);
    }

    if (preg_match('/Nosso-?Número\s+(\d{8,30})/iu', $texto_limpo, $m)) {
        $dados['nosso_numero'] = trim($m[1]);
    }

    if (preg_match('/\d{5}\.\d{5}\s+\d{5}\.\d{6}\s+\d{5}\.\d{6}\s+\d\s+\d{14}/', $texto_limpo, $m)) {
        $dados['linha_digitavel'] = trim($m[0]);
    }

    // Extrair Nr Documento
    // Caso 1: número vem APÓS o label (ex: "Nr Documento 00111/0426")
    if (preg_match('/Nr\.?\s*Documento\s+(\d+)\//i', $texto_limpo, $m)) {
        $dados['nr_documento'] = trim($m[1]);
    }
    // Caso 2: número vem ANTES do label — padrão do BB na ficha de compensação
    // Ex: "24/03/2026 00111/0426 Nr Documento"
    elseif (preg_match('/(\d{5,})\s*\/\s*\d+\s+Nr\.?\s*Documento/i', $texto_limpo, $m)) {
        $dados['nr_documento'] = trim($m[1]);
    }
    // Caso 3: capturar qualquer número com / próximo ao label, texto_limpo pode ter variações
    elseif (preg_match('/(\d{4,})\/\d{4}\s*(?:Nr\.?\s*Documento|Esp[eé]cie)/i', $texto_limpo, $m)) {
        $dados['nr_documento'] = trim($m[1]);
    }
    // Caso 4: buscar no texto original linha por linha — número sozinho antes de /
    if (empty($dados['nr_documento'])) {
        foreach (explode("\n", $texto) as $linha) {
            $linha = trim($linha);
            // Linha que tem só um número seguido de /AAAA (ex: "00111/0426")
            if (preg_match('/^(\d{4,})\/\d{4}$/', $linha, $m)) {
                $dados['nr_documento'] = $m[1];
                break;
            }
            // Linha que tem data e número (ex: "24/03/2026 00111/0426")
            if (preg_match('/\d{2}\/\d{2}\/\d{4}\s+(\d{4,})\/\d{4}/', $linha, $m)) {
                $dados['nr_documento'] = $m[1];
                break;
            }
        }
    }

    // Extrair endereço do pagador
    // O campo "Nome do Pagador/CPF/CNPJ/Endereço" contém nome, CPF e depois endereço
    // Estratégia: pegar as linhas após o CPF no bloco do pagador
    if (preg_match(
        '/Nome do Pagador\/CPF\/CNPJ\/Endere[çc]o\s+[^\n]+?CPF[:\s]+([\d\.\-]+)\s+(.+?)\s+(\d{5}-\d{3}\s*[-–]\s*[A-Z\s]+\s*[-–]\s*[A-Z]{2})/isu',
        $texto,
        $m
    )) {
        $dados['endereco'] = trim($m[2]) . ' — ' . trim($m[3]);
    } elseif (preg_match(
        '/Nome do Pagador\/CPF\/CNPJ\/Endere[çc]o\s+.+?CPF[:\s]+[\d\.\-]+\s+((?:[^\n]+\n){1,3})/isu',
        $texto,
        $m
    )) {
        $dados['endereco'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    return $dados;
}

function pb_remover_diretorio($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $itens = scandir($dir);

    foreach ($itens as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $caminho = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($caminho)) {
            pb_remover_diretorio($caminho);
        } else {
            @unlink($caminho);
        }
    }

    @rmdir($dir);
}

function pb_pagina_admin() {
    if (!pb_usuario_pode('pb_ver_boletos')) {
        wp_die('Você não tem permissão para acessar esta página.');
    }
    
    pb_registrar_acesso_painel_limitado();

    global $wpdb;
    $tabela_boletos = $wpdb->prefix . 'pb_boletos';
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    $total_clientes = $wpdb->get_var("SELECT COUNT(*) FROM $tabela_clientes");
    $total_boletos = $wpdb->get_var("SELECT COUNT(*) FROM $tabela_boletos");
    
    $mes_atual = date('m/Y');
    $total_sem_boleto_mes = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM $tabela_clientes c
        WHERE NOT EXISTS (
            SELECT 1 FROM $tabela_boletos b
            WHERE b.cliente_id = c.id
            AND b.mes_referencia = %s
        )
    ", $mes_atual));
    
    $total_mes_atual = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM $tabela_boletos 
        WHERE mes_referencia = DATE_FORMAT(CURDATE(), '%m/%Y')
    ");
    
    $valor_mes_atual = $wpdb->get_var("
        SELECT SUM(valor) 
        FROM $tabela_boletos 
        WHERE mes_referencia = DATE_FORMAT(CURDATE(), '%m/%Y')
    ");
    
    $total_pendentes = $wpdb->get_var("SELECT COUNT(*) FROM $tabela_boletos WHERE email_status = 'pendente'");
    $total_agendados = $wpdb->get_var("SELECT COUNT(*) FROM $tabela_boletos WHERE email_status = 'agendado'");
    $total_enviados  = $wpdb->get_var("SELECT COUNT(*) FROM $tabela_boletos WHERE email_status = 'enviado'");
    $total_falhou    = $wpdb->get_var("SELECT COUNT(*) FROM $tabela_boletos WHERE email_status = 'falhou'");
    $total_sem_email = $wpdb->get_var("SELECT COUNT(*) FROM $tabela_boletos WHERE email_status = 'sem_email'");

    // Notificações internas não lidas
    $tabela_notif = $wpdb->prefix . 'pb_notificacoes';
    $notificacoes = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '$tabela_notif'") === $tabela_notif) {
        $notificacoes = $wpdb->get_results("SELECT * FROM $tabela_notif WHERE lida = 0 ORDER BY id DESC LIMIT 10");
    }



    $filtro_mes = isset($_GET['filtro_mes']) ? sanitize_text_field($_GET['filtro_mes']) : '';
    $filtro_cliente = isset($_GET['filtro_cliente']) ? sanitize_text_field($_GET['filtro_cliente']) : '';
    $filtro_status = isset($_GET['filtro_status']) ? sanitize_text_field($_GET['filtro_status']) : '';
    $filtro_status_pgto = isset($_GET['filtro_status_pgto']) ? sanitize_text_field($_GET['filtro_status_pgto']) : '';
    
    $where = "WHERE 1=1";
    $params = [];
    
    if (!empty($filtro_mes)) {
        $where .= " AND b.mes_referencia = %s";
        $params[] = $filtro_mes;
    }
    
    if (!empty($filtro_cliente)) {
        $busca = '%' . $wpdb->esc_like($filtro_cliente) . '%';
        $where .= " AND (c.nome LIKE %s OR c.cpf LIKE %s)";
        $params[] = $busca;
        $params[] = $busca;
    }
    
    if (!empty($filtro_status)) {
        $where .= " AND b.email_status = %s";
        $params[] = $filtro_status;
    }

    if (!empty($filtro_status_pgto)) {
        $where .= " AND b.status_pagamento = %s";
        $params[] = $filtro_status_pgto;
    }
    
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $por_pagina = 50;
    $offset = ($paged - 1) * $por_pagina;
    
    $sql_total = "
        SELECT COUNT(*)
        FROM $tabela_boletos b
        LEFT JOIN $tabela_clientes c ON c.id = b.cliente_id
        $where
    ";
    
    $total_registros = !empty($params)
        ? $wpdb->get_var($wpdb->prepare($sql_total, $params))
        : $wpdb->get_var($sql_total);
    
    $total_paginas = ceil($total_registros / $por_pagina);
    
    $sql = "
        SELECT b.*, c.nome, c.cpf, c.email, c.nr_documento, u.display_name AS funcionario_envio
        FROM $tabela_boletos b
        LEFT JOIN $tabela_clientes c ON c.id = b.cliente_id
        LEFT JOIN {$wpdb->users} u ON u.ID = b.email_enviado_por
        $where
        ORDER BY b.id DESC
        LIMIT %d OFFSET %d
    ";
    
    $params_paginados = $params;
    $params_paginados[] = $por_pagina;
    $params_paginados[] = $offset;
    
    $boletos = $wpdb->get_results($wpdb->prepare($sql, $params_paginados));
    
    $meses = $wpdb->get_results("
        SELECT DISTINCT mes_referencia
        FROM $tabela_boletos
        WHERE mes_referencia IS NOT NULL AND mes_referencia != ''
        ORDER BY vencimento DESC
    ");
    $cliente_edicao = null;

    if (isset($_GET['editar_email_cliente'])) {
        $cliente_id_edicao = intval($_GET['editar_email_cliente']);
    
        if ($cliente_id_edicao) {
            $cliente_edicao = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $tabela_clientes WHERE id = %d", $cliente_id_edicao)
            );
        }
    }
    ?>
    
    <div class="wrap">
        <style>
            .pb-app {
                background:#f4f7fb;
                margin:0 0 0 -20px;
                padding:28px 14px;
                min-height:0;
                box-sizing:border-box;
                width:calc(100% + 20px);
            }
        
            .pb-hero {
                background:linear-gradient(135deg,#071f46,#0b5ed7);
                color:#fff;
                border-radius:24px;
                padding:28px;
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:20px;
                box-shadow:0 18px 45px rgba(11,44,97,.22);
                margin-bottom:22px;
            }
        
            .pb-hero h1 {
                color:#fff;
                margin:0;
                font-size:30px;
                font-weight:800;
            }
        
            .pb-hero p {
                margin:8px 0 0;
                color:rgba(255,255,255,.78);
                font-size:14px;
            }
        
            .pb-btn {
                display:inline-flex !important;
                align-items:center;
                justify-content:center;
                gap:6px;
                border-radius:12px !important;
                padding:8px 14px !important;
                font-weight:700 !important;
                text-decoration:none !important;
                min-height:36px;
            }
        
            .pb-btn-white {
                background:#fff !important;
                color:#0b2c61 !important;
                border:0 !important;
            }
        
            .pb-btn-primary,
            .button-primary {
                background:#0b5ed7 !important;
                border-color:#0b5ed7 !important;
                color:#fff !important;
                border-radius:12px !important;
                font-weight:700 !important;
            }
        
            .pb-btn-outline {
                background:#fff !important;
                border:1px solid #cbd5e1 !important;
                color:#0f172a !important;
                border-radius:12px !important;
            }
        
            .pb-metrics {
                display:grid;
                grid-template-columns:repeat(4, minmax(0, 1fr));
                gap:16px;
                margin-bottom:22px;
            }
            
            @media (max-width: 1100px) {
                .pb-metrics {
                    grid-template-columns:repeat(2, minmax(0, 1fr));
                }
            }
            
            @media (max-width: 700px) {
                .pb-metrics {
                    grid-template-columns:1fr;
                }
            }
        
            .pb-metric {
                background:#fff;
                border:1px solid #e5eaf2;
                border-radius:16px;
                padding:18px 20px;
                box-shadow:0 8px 22px rgba(15,23,42,.05);
                transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;
            }
            
            .pb-metric:hover {
                transform:translateY(-2px);
                border-color:#cbd5e1;
                box-shadow:0 14px 28px rgba(15,23,42,.08);
            }
        
            .pb-metric span {
                color:#64748b;
                font-size:12px;
                font-weight:800;
                text-transform:uppercase;
                letter-spacing:.05em;
            }
        
            .pb-metric strong {
                display:block;
                margin-top:10px;
                color:#0f172a;
                font-size:30px;
                font-weight:900;
            }
        
            .pb-panel {
                background:#fff;
                border:1px solid #e5eaf2;
                border-radius:16px;
                padding:22px;
                box-shadow:0 8px 22px rgba(15,23,42,.05);
                margin-bottom:22px;
            }
        
            .pb-panel h2 {
                margin:0 0 14px;
                font-size:20px;
                color:#0f172a;
                font-weight:800;
            }
        
            .pb-toolbar {
                display:flex;
                justify-content:space-between;
                align-items:end;
                gap:14px;
                flex-wrap:wrap;
                margin-bottom:16px;
            }
        
            .pb-filters {
                display:flex;
                gap:10px;
                align-items:end;
                flex-wrap:wrap;
            }
        
            .pb-actions {
                display:flex;
                gap:8px;
                flex-wrap:wrap;
                margin:14px 0;
            }
        
            .pb-table-wrap {
                overflow-x:auto;
                border:1px solid #e2e8f0;
                border-radius:18px;
            }

            .pb-table-wrap table {
                border:0 !important;
                margin:0;
                min-width:1200px;
            }
        
            html body .pb-app .pb-table-wrap table thead tr th,
            html body .pb-app .pb-table-wrap table tbody tr td {
                font-size:10px !important;
                padding:8px 6px !important;
            }

            html body .pb-app .pb-table-wrap table thead tr th {
                background:#f8fafc;
                color:#334155;
                font-weight:800;
                border-bottom:1px solid #e2e8f0;
                white-space:nowrap;
            }

            html body .pb-app .pb-table-wrap table tbody tr td {
                vertical-align:middle;
                border-bottom:1px solid #eef2f7;
            }

            html body .pb-app .pb-table-wrap table tbody tr:hover td {
                background:#f8fbff;
            }
        
            .pb-status {
                display:inline-flex;
                padding:5px 10px;
                border-radius:999px;
                font-size:11px;
                font-weight:900;
                text-transform:uppercase;
            }
        
            .pb-status-pendente { background:#fef3c7; color:#92400e; }
            .pb-status-agendado { background:#dbeafe; color:#1d4ed8; }
            .pb-status-enviado { background:#dcfce7; color:#166534; }
            .pb-status-falhou { background:#fee2e2; color:#991b1b; }
            .pb-status-sem_email,
            .pb-status-arquivo_nao_encontrado { background:#f1f5f9; color:#475569; }
        
            input[type="text"],
            input[type="email"],
            input[type="date"],
            select {
                border-radius:10px !important;
                border:1px solid #cbd5e1 !important;
                min-height:36px;
            }
        </style>
        <div class="pb-app">
            <div class="pb-hero">
                <div>
                    <div class="pb-page-title">Portal de Boletos</div>
                    <p>Dashboard financeiro para importação, controle e envio de boletos.</p>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <?php if (!empty($notificacoes)) : ?>
                        <div style="position:relative; display:inline-block;">
                            <button onclick="document.getElementById('pb-notif-dropdown').classList.toggle('pb-notif-open')"
                                style="position:relative; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3);
                                       color:#fff; border-radius:10px; width:38px; height:38px; cursor:pointer;
                                       font-size:18px; display:flex; align-items:center; justify-content:center;">
                                🔔
                                <span style="position:absolute; top:-4px; right:-4px; background:#ef4444; color:#fff;
                                             border-radius:999px; font-size:10px; font-weight:900; min-width:18px;
                                             height:18px; display:flex; align-items:center; justify-content:center; padding:0 4px;">
                                    <?php echo count($notificacoes); ?>
                                </span>
                            </button>
                            <div id="pb-notif-dropdown"
                                style="display:none; position:absolute; right:0; top:46px; width:320px;
                                       background:#fff; border-radius:14px; border:1px solid #e2e8f0;
                                       box-shadow:0 12px 40px rgba(15,23,42,.15); z-index:9999; overflow:hidden;">
                                <div style="padding:12px 16px; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-size:13px; font-weight:700; color:#0f172a;">Notificações</span>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                        <input type="hidden" name="action" value="pb_marcar_notificacoes_lidas">
                                        <?php wp_nonce_field('pb_notificacoes_action', 'pb_nonce'); ?>
                                        <button type="submit" style="background:none; border:none; font-size:11px; color:#0b5ed7; font-weight:700; cursor:pointer;">Marcar todas como lidas</button>
                                    </form>
                                </div>
                                <?php foreach ($notificacoes as $notif) : ?>
                                    <div style="padding:10px 16px; border-bottom:1px solid #f8fafc; display:flex; gap:10px; align-items:flex-start;">
                                        <span style="font-size:16px; margin-top:1px;"><?php echo $notif->tipo === 'erro' ? '⚠️' : 'ℹ️'; ?></span>
                                        <div>
                                            <p style="margin:0 0 2px; font-size:12px; color:#334155; line-height:1.4;"><?php echo esc_html($notif->mensagem); ?></p>
                                            <span style="font-size:10px; color:#94a3b8;"><?php echo date('d/m/Y H:i', strtotime($notif->created_at)); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos')); ?>" class="button pb-btn pb-btn-white">
                        Atualizar
                    </a>
                </div>
            </div>
            <script>
                document.addEventListener('click', function(e) {
                    var dropdown = document.getElementById('pb-notif-dropdown');
                    if (dropdown && !e.target.closest('#pb-notif-dropdown') && !e.target.closest('button[onclick*="pb-notif-dropdown"]')) {
                        dropdown.classList.remove('pb-notif-open');
                        dropdown.style.display = 'none';
                    }
                });
                var dropdown = document.getElementById('pb-notif-dropdown');
                if (dropdown) {
                    var observer = new MutationObserver(function(mutations) {
                        mutations.forEach(function(m) {
                            if (dropdown.classList.contains('pb-notif-open')) {
                                dropdown.style.display = 'block';
                            } else {
                                dropdown.style.display = 'none';
                            }
                        });
                    });
                    observer.observe(dropdown, { attributes: true, attributeFilter: ['class'] });
                }
            </script>
            
        
        <div class="pb-metrics">
            <div class="pb-metric">
                <span>Total de clientes</span>
                <strong><?php echo intval($total_clientes); ?></strong>
            </div>
        
            <div class="pb-metric">
                <span>Total de boletos</span>
                <strong><?php echo intval($total_boletos); ?></strong>
            </div>
        
            <div class="pb-metric">
                <span>Boletos do mês atual</span>
                <strong><?php echo intval($total_mes_atual); ?></strong>
            </div>
        
            <div class="pb-metric">
                <span>Valor do mês atual</span>
                <strong>R$ <?php echo esc_html(number_format((float) $valor_mes_atual, 2, ',', '.')); ?></strong>
            </div>

            <div class="pb-metric" style="border-left:4px solid #f59e0b;">
                <span>Sem boleto este mês</span>
                <strong style="color:#d97706;"><?php echo intval($total_sem_boleto_mes); ?></strong>
                <?php if ($total_sem_boleto_mes > 0) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_clientes&filtro_sem_boleto=1')); ?>"
                    style="display:block; margin-top:8px; font-size:11px; color:#0b5ed7; font-weight:600; text-decoration:none;">
                    Ver clientes →
                </a>
                <?php endif; ?>
            </div>

        </div><!-- .pb-metrics -->

        <div class="pb-notices-area">
            <?php if (isset($_GET['erro']) && $_GET['erro'] === 'ziparchive') : ?>
                <div class="pb-alert pb-alert-error">O servidor não possui suporte ao ZipArchive.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['erro']) && $_GET['erro'] === 'sem_arquivo') : ?>
                <div class="pb-alert pb-alert-error">Selecione um arquivo ZIP.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['erro']) && $_GET['erro'] === 'upload') : ?>
                <div class="pb-alert pb-alert-error">Erro no upload do arquivo.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['erro']) && $_GET['erro'] === 'tipo') : ?>
                <div class="pb-alert pb-alert-error">Envie apenas arquivo ZIP.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['erro']) && $_GET['erro'] === 'salvar_zip') : ?>
                <div class="pb-alert pb-alert-error">Não foi possível salvar o ZIP no servidor.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['erro']) && $_GET['erro'] === 'abrir_zip') : ?>
                <div class="pb-alert pb-alert-error">Não foi possível abrir o ZIP.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['importados'])) : ?>
                <div class="pb-alert pb-alert-success">
                    Importação concluída.
                    Importados: <strong><?php echo intval($_GET['importados']); ?></strong> |
                    Ignorados: <strong><?php echo intval(isset($_GET['ignorados']) ? $_GET['ignorados'] : 0); ?></strong> |
                    Erros: <strong><?php echo intval(isset($_GET['erros']) ? $_GET['erros'] : 0); ?></strong>
                </div>
            <?php endif; ?>
        
            <?php if (isset($_GET['reenvio']) && $_GET['reenvio'] === 'ok') : ?>
                <div class="pb-alert pb-alert-success">Boletos marcados como pendentes para reenvio.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['reenvio']) && $_GET['reenvio'] === 'nenhum') : ?>
                <div class="pb-alert pb-alert-warning">Nenhum boleto selecionado.</div>
            <?php endif; ?>
        
            <?php if (isset($_GET['excluidos'])) : ?>
                <div class="pb-alert pb-alert-success">
                    Boletos excluídos: <strong><?php echo intval($_GET['excluidos']); ?></strong>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['envio']) && $_GET['envio'] === 'agendado') : ?>
                <div class="pb-alert pb-alert-success">Envio de boletos agendado. A fila será processada em lotes.</div>
            <?php endif; ?>
            
            <?php if (isset($_GET['envio']) && $_GET['envio'] === 'nenhum') : ?>
                <div class="pb-alert pb-alert-warning">Nenhum boleto selecionado para envio.</div>
            <?php endif; ?>
            
            <?php if (isset($_GET['antigos_excluidos'])) : ?>
            <div class="pb-alert pb-alert-success">
                Boletos antigos excluídos: <strong><?php echo intval($_GET['antigos_excluidos']); ?></strong>
            </div>
        <?php endif; ?>
        
            
        </div>
        
        <?php if ($cliente_edicao) : ?>
            <div class="pb-panel pb-edit-email-panel" style="max-width:560px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                    <div style="width:32px; height:32px; background:#dcfce7; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px;">✉</div>
                    <div>
                        <h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">Editar e-mail do cliente</h2>
                        <p style="margin:2px 0 0; font-size:12px; color:#64748b;"><?php echo esc_html($cliente_edicao->nome); ?> &nbsp;·&nbsp; <?php echo esc_html($cliente_edicao->cpf); ?></p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pb_atualizar_email_cliente">
                    <input type="hidden" name="cliente_id" value="<?php echo esc_attr($cliente_edicao->id); ?>">
                    <?php wp_nonce_field('pb_atualizar_email_cliente_action', 'pb_nonce'); ?>
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:5px;">E-mail</label>
                        <input type="email" name="email" id="email" value="<?php echo esc_attr($cliente_edicao->email); ?>"
                            style="width:100%; height:36px; padding:0 12px; font-size:13px;" required>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 16px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                            Salvar e-mail
                        </button>
                        <button type="submit" name="remover_email" value="1"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 14px; background:#fff; border:1px solid #fca5a5; color:#ef4444; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;"
                            onclick="return confirm('Deseja remover o e-mail deste cliente?');">
                            Remover e-mail
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos')); ?>"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 14px; background:#fff; border:1px solid #e2e8f0; color:#64748b; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none;">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
                    
                    <div class="pb-panel pb-import-panel" style="max-width:820px;">
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                            <div style="width:32px; height:32px; background:#dbeafe; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:16px;">↑</div>
                            <h2 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;">Importar boletos por ZIP</h2>
                        </div>
                        <p style="margin:0 0 16px; color:#64748b; font-size:13px;">Envie um arquivo ZIP contendo os PDFs dos boletos.</p>
            
        <?php if (pb_usuario_pode('pb_importar_boletos')) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="pb_importar_zip">
                <?php wp_nonce_field('pb_importar_zip_action', 'pb_nonce'); ?>
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:4px;">
                    <input type="file" name="arquivo_zip" id="arquivo_zip" accept=".zip" required
                        style="font-size:13px; color:#334155;">
                    <button type="submit" class="button button-primary"
                        style="display:inline-flex !important; align-items:center !important; justify-content:center !important; height:36px; padding:0 18px; font-size:13px; font-weight:700; border-radius:8px !important; white-space:nowrap; line-height:1 !important;">
                        Importar ZIP
                    </button>
                </div>
            </form>
        <?php endif; ?>
        </div>
        
        <?php pb_render_admin_notices(); ?>

        
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
            <div>
                <h2 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;">Boletos importados</h2>
                <p style="margin:4px 0 0; color:#64748b; font-size:12px;">Consulte, filtre e gerencie os boletos cadastrados.</p>
            </div>
            <?php if ( pb_usuario_pode('pb_ver_boletos') ) : ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <input type="hidden" name="action"             value="pb_exportar_csv">
                    <input type="hidden" name="filtro_mes"         value="<?php echo esc_attr($filtro_mes); ?>">
                    <input type="hidden" name="filtro_cliente"     value="<?php echo esc_attr($filtro_cliente); ?>">
                    <input type="hidden" name="filtro_status"      value="<?php echo esc_attr($filtro_status); ?>">
                    <input type="hidden" name="filtro_status_pgto" value="<?php echo esc_attr($filtro_status_pgto); ?>">
                    <?php wp_nonce_field('pb_exportar_csv_action', 'pb_nonce'); ?>
                    <button type="submit"
                        style="display:inline-flex; align-items:center; gap:6px; height:34px; padding:0 14px;
                               background:#fff; border:1px solid #16a34a; color:#16a34a;
                               border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                        ↓ CSV
                    </button>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" target="_blank">
                    <input type="hidden" name="action"             value="pb_relatorio_pagamentos">
                    <input type="hidden" name="filtro_mes"         value="<?php echo esc_attr($filtro_mes); ?>">
                    <input type="hidden" name="filtro_cliente"     value="<?php echo esc_attr($filtro_cliente); ?>">
                    <input type="hidden" name="filtro_status"      value="<?php echo esc_attr($filtro_status); ?>">
                    <input type="hidden" name="filtro_status_pgto" value="<?php echo esc_attr($filtro_status_pgto); ?>">
                    <?php wp_nonce_field('pb_relatorio_pagamentos_action', 'pb_nonce'); ?>
                    <button type="submit"
                        style="display:inline-flex; align-items:center; gap:6px; height:34px; padding:0 14px;
                               background:#0b5ed7; border:1px solid #0b5ed7; color:#fff;
                               border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                        📄 Relatório
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <form method="get" class="pb-filters" style="background:#fff; border:1px solid #e5eaf2; border-radius:12px; padding:14px 16px; margin-bottom:12px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; box-shadow:0 2px 8px rgba(15,23,42,.04);">
        <input type="hidden" name="page" value="pb_boletos">
    
        <div>
            <label style="display:block; font-weight:600; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">Mês</label>
            <select name="filtro_mes" style="height:32px; font-size:12px; min-width:110px;">
                <option value="">Todos</option>
                <?php foreach ($meses as $mes) : ?>
                    <option value="<?php echo esc_attr($mes->mes_referencia); ?>" <?php selected($filtro_mes, $mes->mes_referencia); ?>>
                        <?php echo esc_html($mes->mes_referencia); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    
        <div>
            <label style="display:block; font-weight:600; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">Cliente ou CPF</label>
            <input type="text" name="filtro_cliente" value="<?php echo esc_attr($filtro_cliente); ?>" placeholder="Nome ou CPF"
                style="height:32px; font-size:12px; min-width:160px;">
        </div>
        
        <div>
            <label style="display:block; font-weight:600; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">Status e-mail</label>
            <select name="filtro_status" style="height:32px; font-size:12px; min-width:120px;">
                <option value="">Todos</option>
                <option value="pendente" <?php selected($filtro_status, 'pendente'); ?>>Pendente</option>
                <option value="agendado" <?php selected($filtro_status, 'agendado'); ?>>Agendado</option>
                <option value="enviado"  <?php selected($filtro_status, 'enviado'); ?>>Enviado</option>
                <option value="falhou"   <?php selected($filtro_status, 'falhou'); ?>>Falhou</option>
                <option value="sem_email" <?php selected($filtro_status, 'sem_email'); ?>>Sem e-mail</option>
                <option value="arquivo_nao_encontrado" <?php selected($filtro_status, 'arquivo_nao_encontrado'); ?>>Arq. não encontrado</option>
            </select>
        </div>

        <div>
            <label style="display:block; font-weight:600; font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px;">Pagamento</label>
            <select name="filtro_status_pgto" style="height:32px; font-size:12px; min-width:100px;">
                <option value="">Todos</option>
                <option value="pendente" <?php selected($filtro_status_pgto, 'pendente'); ?>>Pendente</option>
                <option value="pago"     <?php selected($filtro_status_pgto, 'pago'); ?>>Pago</option>
            </select>
        </div>

        <div style="display:flex; gap:6px; align-items:flex-end;">
            <button type="submit"
                style="height:32px; padding:0 16px; background:#0b5ed7; border:none; color:#fff;
                       border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                Filtrar
            </button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos')); ?>"
                style="display:inline-flex; align-items:center; height:32px; padding:0 14px;
                       background:#fff; border:1px solid #e2e8f0; color:#64748b;
                       border-radius:8px; font-size:12px; font-weight:600; text-decoration:none;">
                Limpar
            </a>
        </div>
    </form>
            
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            
            <?php wp_nonce_field('pb_excluir_boletos_action', 'pb_nonce'); ?>
            
            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; align-items:center;">
                <?php if (pb_usuario_pode('pb_excluir_boletos')) : ?>
                    <button type="submit" name="action" value="pb_excluir_boletos"
                        style="height:30px; padding:0 12px; background:#fff; border:1px solid #e2e8f0;
                               color:#ef4444; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer;"
                        onclick="return confirm('Tem certeza que deseja excluir os boletos selecionados? Essa ação apagará os PDFs do servidor e não poderá ser desfeita.');">
                        Excluir selecionados
                    </button>
                    <button type="submit" name="action" value="pb_excluir_boletos_antigos"
                        style="height:30px; padding:0 12px; background:#fff; border:1px solid #e2e8f0;
                               color:#ef4444; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer;"
                        onclick="return confirm('Tem certeza que deseja excluir todos os boletos com vencimento anterior a 6 meses? Essa ação apagará os PDFs do servidor e não poderá ser desfeita.');">
                        Excluir antigos
                    </button>
                <?php endif; ?>

                <div style="width:1px; height:20px; background:#e2e8f0; margin:0 2px;"></div>
            
                <?php if (pb_usuario_pode('pb_enviar_boletos')) : ?>
                    <button type="submit" name="action" value="pb_agendar_envio_boletos"
                        style="height:30px; padding:0 12px; background:#0b5ed7; border:none;
                               color:#fff; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer;"
                        onclick="return confirm('Deseja agendar o envio dos boletos selecionados?');">
                        Enviar selecionados
                    </button>
                
                    <?php
                    $preview_envio_todos = $wpdb->get_row("
                        SELECT 
                            COUNT(b.id) AS total_boletos,
                            COUNT(DISTINCT b.cliente_id) AS total_clientes
                        FROM $tabela_boletos b
                        INNER JOIN $tabela_clientes c ON c.id = b.cliente_id
                        WHERE b.email_status = 'pendente'
                        AND c.email IS NOT NULL
                        AND c.email != ''
                    ");
                    ?>
                    
                    <button type="submit" name="action" value="pb_agendar_envio_boletos"
                        style="height:30px; padding:0 12px; background:#0b5ed7; border:none;
                               color:#fff; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer;"
                        onclick="document.getElementById('pb_enviar_todos').value='1'; return confirm('Serão agendados <?php echo intval($preview_envio_todos->total_boletos); ?> boletos para <?php echo intval($preview_envio_todos->total_clientes); ?> clientes. Deseja continuar?');">
                        Enviar todos pendentes
                    </button>

                    <button type="submit" name="action" value="pb_marcar_nao_enviado"
                        style="height:30px; padding:0 12px; background:#fff; border:1px solid #e2e8f0;
                               color:#334155; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer;"
                        onclick="return confirm('Deseja marcar os boletos selecionados como não enviados?');">
                        Marcar não enviado
                    </button>
                <?php endif; ?>
                
                <input type="hidden" name="enviar_todos" id="pb_enviar_todos" value="0">
            </div>
        <div class="pb-table-wrap">
            <table class="widefat striped" style="font-size:12px !important;">
                <thead>
                    <tr>
                        <th style="padding:10px 6px !important; white-space:nowrap;"><input type="checkbox" onclick="document.querySelectorAll('.pb-check-boleto').forEach(cb => cb.checked = this.checked);"></th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">ID</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">NR BANCO</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Cliente</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">CPF</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">E-mail</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Mês</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Vencimento</th>
                        <th style="padding:10px 6px !important; white-space:nowrap; text-align:center;">Valor R$</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Data importação</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Status e-mail</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Data Envio</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Pagamento</th>
                        <th style="padding:10px 6px !important; white-space:nowrap;">Observação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($boletos)) : ?>
                        <?php foreach ($boletos as $boleto) : ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="pb-check-boleto" name="boletos[]" value="<?php echo esc_attr($boleto->id); ?>">
                                </td>
                                <td><?php echo esc_html($boleto->id); ?></td>
                                <td style="font-weight:600; color:#475569;"><?php echo !empty($boleto->nr_documento) ? esc_html($boleto->nr_documento) : '-'; ?></td>
                                <td><?php echo esc_html($boleto->nome); ?></td>
                                <td><?php echo esc_html($boleto->cpf); ?></td>
                                <td>
                                    <?php echo !empty($boleto->email) ? esc_html($boleto->email) : '<span style="color:#9ca3af; font-style:italic;">Sem e-mail</span>'; ?>
                                    <br>
                                    <?php if (pb_usuario_pode('pb_editar_email_cliente')) : ?>
                                        <br>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos&editar_email_cliente=' . intval($boleto->cliente_id))); ?>">
                                            Editar
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($boleto->mes_referencia ?: '-'); ?></td>
                                <td><?php echo !empty($boleto->vencimento) ? esc_html(date('d/m/Y', strtotime($boleto->vencimento))) : '-'; ?></td>
                                <td style="text-align:center;"><?php echo !empty($boleto->valor) ? esc_html(number_format($boleto->valor, 2, ',', '.')) : '-'; ?></td>
                                <td><?php echo !empty($boleto->created_at) ? esc_html(date('d/m/Y H:i', strtotime($boleto->created_at))) : '-'; ?></td>
                                <td>
                                    <?php
                                    $status = $boleto->email_status ?: 'pendente';
                                    ?>
                                    <span class="pb-status pb-status-<?php echo esc_attr($status); ?>">
                                        <?php echo esc_html($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($boleto->funcionario_envio) || !empty($boleto->email_enviado_em)) : ?>
                                        <?php if (!empty($boleto->funcionario_envio)) : ?>
                                            <span style="display:block; font-weight:600; color:#334155;"><?php echo esc_html($boleto->funcionario_envio); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($boleto->email_enviado_em)) : ?>
                                            <span style="display:block; font-size:10px; color:#94a3b8;"><?php echo esc_html(date('d/m/Y H:i', strtotime($boleto->email_enviado_em))); ?></span>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center; vertical-align:middle;">
                                    <?php if ( ! empty( $boleto->status_pagamento ) && $boleto->status_pagamento === 'pago' ) : ?>

                                        <div style="display:flex; flex-direction:column; align-items:center; gap:4px;">
                                            <span style="
                                                background:#dcfce7; color:#166534;
                                                padding:3px 10px; border-radius:999px;
                                                font-size:11px; font-weight:900; text-transform:uppercase;
                                                white-space:nowrap;">
                                                ✓ <?php echo esc_html( date( 'd/m/Y', strtotime( $boleto->pago_em ) ) ); ?>
                                            </span>

                                            <?php if ( pb_usuario_pode( 'pb_registrar_pagamento' ) ) : ?>
                                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                                    <input type="hidden" name="action"    value="pb_reverter_pagamento">
                                                    <input type="hidden" name="boleto_id" value="<?php echo esc_attr( $boleto->id ); ?>">
                                                    <?php wp_nonce_field( 'pb_reverter_pagamento_action', 'pb_nonce' ); ?>
                                                    <button type="submit"
                                                        style="background:none; border:none; color:#ef4444; font-size:11px;
                                                               cursor:pointer; padding:0; text-decoration:underline;"
                                                        onclick="return confirm('Reverter pagamento? O boleto voltará para pendente.');">
                                                        Reverter
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>

                                    <?php elseif ( pb_usuario_pode( 'pb_registrar_pagamento' ) ) : ?>

                                        <button
                                            type="button"
                                            onclick="pbAbrirModalPagamento(<?php echo esc_attr( $boleto->id ); ?>)"
                                            title="Marcar como pago"
                                            style="
                                                background:#f0fdf4; border:1px solid #86efac; color:#16a34a;
                                                border-radius:999px; width:32px; height:32px;
                                                font-size:16px; cursor:pointer; line-height:1;
                                                display:inline-flex; align-items:center; justify-content:center;
                                                transition: background .15s;">
                                            ✓
                                        </button>

                                    <?php else : ?>
                                        <span style="color:#d1d5db; font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( pb_usuario_pode('pb_ver_boletos') ) : ?>
                                    <div style="display:flex; align-items:center; gap:4px;">
                                        <?php if ( !empty($boleto->observacao) ) : ?>
                                            <span title="<?php echo esc_attr($boleto->observacao); ?>"
                                                style="display:inline-block; max-width:100px; overflow:hidden;
                                                       text-overflow:ellipsis; white-space:nowrap; font-size:10px; color:#64748b; cursor:help;">
                                                <?php echo esc_html($boleto->observacao); ?>
                                            </span>
                                        <?php endif; ?>
                                        <button type="button"
                                            onclick="pbAbrirModalObs(<?php echo esc_attr($boleto->id); ?>, <?php echo htmlspecialchars(json_encode(isset($boleto->observacao) ? $boleto->observacao : ''), ENT_QUOTES); ?>)"
                                            style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:13px; padding:2px; line-height:1;"
                                            title="<?php echo empty($boleto->observacao) ? 'Adicionar observação' : 'Editar observação'; ?>">
                                            ✏️
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="12">Nenhum boleto importado ainda.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
            <?php if ($total_paginas > 1) : ?>
                <div style="margin-top:20px;">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $paged,
                        'total' => $total_paginas,
                        'prev_text' => '« Anterior',
                        'next_text' => 'Próxima »',
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </form>

    <?php if ( pb_usuario_pode( 'pb_registrar_pagamento' ) ) : ?>
    <div id="pb-modal-pagamento"
         style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
                z-index:99999; align-items:center; justify-content:center;">

        <div style="background:#fff; border-radius:18px; padding:28px 32px;
                    box-shadow:0 20px 60px rgba(0,0,0,.2); min-width:320px; max-width:400px; width:90%;">

            <h3 style="margin:0 0 6px; font-size:18px; color:#0f172a;">Marcar como pago</h3>
            <p style="margin:0 0 20px; color:#64748b; font-size:13px;">
                Informe a data em que o pagamento foi realizado.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pb-form-pagamento">
                <input type="hidden" name="action"    value="pb_registrar_pagamento">
                <input type="hidden" name="boleto_id" id="pb-modal-boleto-id" value="">
                <?php wp_nonce_field( 'pb_registrar_pagamento_action', 'pb_nonce' ); ?>

                <label style="display:block; font-weight:700; font-size:13px; color:#334155; margin-bottom:6px;">
                    Data do pagamento
                </label>
                <input type="date" name="pago_em" id="pb-modal-data"
                       value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
                       required
                       style="width:100%; padding:10px 12px; border:1px solid #cbd5e1;
                              border-radius:10px; font-size:15px; margin-bottom:20px; box-sizing:border-box;">

                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="pbFecharModalPagamento()"
                        style="padding:9px 18px; border:1px solid #e2e8f0; border-radius:10px;
                               background:#fff; color:#334155; font-weight:700; cursor:pointer; font-size:13px;">
                        Cancelar
                    </button>
                    <button type="submit"
                        style="padding:9px 18px; border:none; border-radius:10px;
                               background:#16a34a; color:#fff; font-weight:700; cursor:pointer; font-size:13px;">
                        Confirmar pagamento
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function pbAbrirModalPagamento(boletoId) {
            document.getElementById('pb-modal-boleto-id').value = boletoId;
            var modal = document.getElementById('pb-modal-pagamento');
            modal.style.display = 'flex';
        }

        function pbFecharModalPagamento() {
            document.getElementById('pb-modal-pagamento').style.display = 'none';
        }

        document.getElementById('pb-modal-pagamento').addEventListener('click', function(e) {
            if (e.target === this) pbFecharModalPagamento();
        });

        function pbAbrirModalObs(boletoId, obsAtual) {
            document.getElementById('pb-modal-obs-boleto-id').value = boletoId;
            document.getElementById('pb-modal-obs-texto').value = obsAtual || '';
            var modal = document.getElementById('pb-modal-obs');
            modal.style.display = 'flex';
        }

        function pbFecharModalObs() {
            document.getElementById('pb-modal-obs').style.display = 'none';
        }

        document.getElementById('pb-modal-obs').addEventListener('click', function(e) {
            if (e.target === this) pbFecharModalObs();
        });

        // Forçar font-size e padding — aplica imediatamente e monitora mudanças
        function pbAplicarEstiloTabela() {
            document.querySelectorAll('.pb-table-wrap td, .pb-table-wrap th').forEach(function(el) {
                el.setAttribute('style', (el.getAttribute('style') || '') + '; font-size:10px !important; padding:8px 6px !important;');
            });
        }

        pbAplicarEstiloTabela();
        window.addEventListener('load', pbAplicarEstiloTabela);

        // Colapsar menu lateral para ganhar espaço horizontal
        if (document.body && !document.body.classList.contains('folded')) {
            document.body.classList.add('folded');
        }
    </script>

    <!-- Modal Observação -->
    <div id="pb-modal-obs"
         style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
                z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:18px; padding:28px 32px;
                    box-shadow:0 20px 60px rgba(0,0,0,.2); min-width:320px; max-width:440px; width:90%;">
            <h3 style="margin:0 0 6px; font-size:18px; color:#0f172a;">Observação</h3>
            <p style="margin:0 0 16px; color:#64748b; font-size:13px;">Nota interna visível apenas para funcionários.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action"    value="pb_salvar_observacao">
                <input type="hidden" name="boleto_id" id="pb-modal-obs-boleto-id" value="">
                <?php wp_nonce_field( 'pb_salvar_observacao_action', 'pb_nonce' ); ?>
                <textarea name="observacao" id="pb-modal-obs-texto" rows="4"
                    placeholder="Digite uma observação sobre este boleto..."
                    style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px;
                           font-size:13px; margin-bottom:16px; resize:vertical; box-sizing:border-box;"></textarea>
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="pbFecharModalObs()"
                        style="padding:9px 18px; border:1px solid #e2e8f0; border-radius:10px;
                               background:#fff; color:#334155; font-weight:700; cursor:pointer; font-size:13px;">
                        Cancelar
                    </button>
                    <button type="submit"
                        style="padding:9px 18px; border:none; border-radius:10px;
                               background:#0b5ed7; color:#fff; font-weight:700; cursor:pointer; font-size:13px;">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

        </div>
    </div>
</div>
<?php
    
}

add_action('init', 'pb_baixar_boleto_protegido');

add_action('init', 'pb_logout_cliente');

add_shortcode('portal_boletos', 'pb_shortcode_portal_boletos');

function pb_render_area_autenticada($cliente_id) {
    global $wpdb;

    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    $tabela_boletos  = $wpdb->prefix . 'pb_boletos';

    $cliente = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $tabela_clientes WHERE id = %d", $cliente_id)
    );

    if (!$cliente) {
        return '<div style="max-width:760px; margin:40px auto; padding:24px; background:#fff; border:1px solid #e5e7eb; border-radius:18px;">Cliente não encontrado.</div>';
    }

    $boletos = $wpdb->get_results(
        $wpdb->prepare("
            SELECT * FROM $tabela_boletos 
            WHERE cliente_id = %d 
            ORDER BY vencimento DESC, id DESC 
            LIMIT 6
        ", $cliente_id)
    );

    $pagina_atual = get_permalink();
    $logout_url = add_query_arg(['pb_logout' => 1], $pagina_atual);

    ob_start();

    echo '<div style="max-width:760px; margin:40px auto; padding:32px; background:#ffffff; border:1px solid #e5e7eb; border-radius:18px; box-shadow:0 10px 30px rgba(0,0,0,0.06);">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap; margin-bottom:20px;">';
    echo '<div>';
    echo '<h2 style="margin:0; color:#0b2c61; font-size:32px;">Seus boletos</h2>';
    echo '<p style="margin:8px 0 0; color:#6b7280;">Olá, <strong>' . esc_html($cliente->nome) . '</strong>.</p>';
    echo '</div>';
    echo '<a href="' . esc_url($logout_url) . '" style="display:inline-block; padding:10px 18px; background:#ef4444; color:#fff; text-decoration:none; border-radius:10px; font-weight:600;">Sair</a>';
    echo '</div>';

    if (empty($boletos)) {
        echo '<div style="padding:18px; background:#f9fafb; border-radius:12px; color:#374151;">Nenhum boleto encontrado.</div>';
        echo '</div>';
        return ob_get_clean();
    }

    foreach ($boletos as $boleto) {
        $url_abrir = wp_nonce_url(
            add_query_arg(['pb_download' => $boleto->id], home_url('/')),
            'pb_download_' . $boleto->id,
            'pb_download_nonce'
        );
        
        $url_baixar = wp_nonce_url(
            add_query_arg([
                'pb_download' => $boleto->id,
                'download' => 1
            ], home_url('/')),
            'pb_download_' . $boleto->id,
            'pb_download_nonce'
        );
        echo '<div style="padding:18px 20px; border:1px solid #e5e7eb; border-radius:14px; margin-bottom:14px; background:#f8fbff;">';
        echo '<div style="display:flex; justify-content:space-between; align-items:center; gap:20px; flex-wrap:wrap;">';
        echo '<div>';
        $mes = !empty($boleto->mes_referencia) ? $boleto->mes_referencia : 'Boleto';
        $vencimento = !empty($boleto->vencimento) ? date('d/m/Y', strtotime($boleto->vencimento)) : '-';
        $valor = !empty($boleto->valor) ? 'R$ ' . number_format($boleto->valor, 2, ',', '.') : '-';
        
        echo '<div style="font-weight:700; color:#111827; margin-bottom:6px;">Boleto ' . esc_html($mes) . '</div>';
        echo '<div style="font-size:14px; color:#6b7280;">Vencimento: ' . esc_html($vencimento) . ' • Valor: ' . esc_html($valor) . '</div>';
        
        // Badge de pagamento
        if ( ! empty( $boleto->status_pagamento ) && $boleto->status_pagamento === 'pago' ) {
            $pago_em_fmt = ! empty( $boleto->pago_em )
                ? date( 'd/m/Y', strtotime( $boleto->pago_em ) )
                : '';
            echo '<div style="display:inline-flex; align-items:center; gap:5px;
                              background:#dcfce7; color:#166534; margin-top:8px;
                              padding:4px 12px; border-radius:999px;
                              font-size:12px; font-weight:700;">';
            echo '✓ PAGAMENTO CONFIRMADO';
            echo '</div>';
        }
        
        echo '</div>';
        echo '<div style="display:flex; gap:10px;">';

        echo '<a href="' . esc_url($url_abrir) . '" target="_blank"
        style="display:inline-block; padding:10px 14px; background:#0b2c61; color:#fff; text-decoration:none; border-radius:10px; font-weight:600;">
        Abrir
        </a>';
        
        echo '<a href="' . esc_url($url_baixar) . '"
        style="display:inline-block; padding:10px 14px; background:#16a34a; color:#fff; text-decoration:none; border-radius:10px; font-weight:600;">
        Baixar
        </a>';
        
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';

    return ob_get_clean();
}

function pb_enviar_codigo_email($email, $codigo) {
    $assunto = 'Seu código de acesso - ASSC Saúde';

    $mensagem = "Olá,\n\n";
    $mensagem .= "Seu código de acesso é: {$codigo}\n\n";
    $mensagem .= "Este código expira em 5 minutos.\n\n";
    $mensagem .= "Se você não solicitou este acesso, ignore este e-mail.\n\n";
    $mensagem .= "ASSC Saúde";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    return wp_mail($email, $assunto, $mensagem, $headers);
}

function pb_mascarar_email($email) {
    if (!is_email($email)) {
        return $email;
    }

    [$usuario, $dominio] = explode('@', $email);

    $inicio = substr($usuario, 0, 2);

    return $inicio . '****@' . $dominio;
}

function pb_shortcode_portal_boletos() {
    ob_start();
    global $wpdb;

    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    $tabela_codigos  = $wpdb->prefix . 'pb_codigos';

    $etapa = isset($_POST['etapa']) ? sanitize_text_field($_POST['etapa']) : '';
    $pagina_atual = get_permalink();

    if (!empty($_SESSION['pb_cliente_autenticado'])) {
        return pb_render_area_autenticada(intval($_SESSION['pb_cliente_autenticado']));
    }

    echo '<div style="max-width:560px; margin:40px auto; padding:32px; background:#ffffff; border:1px solid #e5e7eb; border-radius:18px; box-shadow:0 10px 30px rgba(0,0,0,0.06);">';

    if (empty($etapa)) {
        ?>
        <h2 style="margin:0 0 10px; color:#0b2c61; font-size:32px;">Acessar boleto</h2>
        <p style="margin:0 0 24px; color:#6b7280;">Informe seu CPF para continuar.</p>

        <form method="post">
            <input type="hidden" name="etapa" value="cpf">

            <label for="cpf" style="display:block; font-weight:600; margin-bottom:8px; color:#111827;">CPF</label>
            <input type="text" name="cpf" id="cpf" required
                style="width:100%; padding:14px 16px; border:1px solid #d1d5db; border-radius:12px; margin-bottom:18px; font-size:16px;">

            <button type="submit"
                style="width:100%; padding:14px 18px; background:#0b2c61; color:#fff; border:none; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer;">
                Continuar
            </button>
        </form>
        <?php
        echo '</div>';
        return ob_get_clean();
    }

    if ($etapa === 'cpf') {
        $cpf = isset($_POST['cpf']) ? preg_replace('/\D/', '', $_POST['cpf']) : '';

        $cliente = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tabela_clientes WHERE cpf = %s", $cpf)
        );

        if (!$cliente) {
            echo '<h2 style="margin-top:0; color:#0b2c61;">Acessar boleto</h2>';
            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px;">CPF não encontrado.</div>';
            echo '<p style="margin-top:18px;"><a href="' . esc_url($pagina_atual) . '" style="color:#0b2c61; font-weight:600;">Voltar</a></p>';
            echo '</div>';
            return ob_get_clean();
        }

        if (empty($cliente->email)) {
            ?>
            <h2 style="margin:0 0 10px; color:#0b2c61; font-size:30px;">Confirmar e-mail</h2>
            <p style="margin:0 0 24px; color:#6b7280;">Informe o e-mail que receberá o código de acesso.</p>

            <form method="post">
                <input type="hidden" name="etapa" value="email">
                <input type="hidden" name="cliente_id" value="<?php echo esc_attr($cliente->id); ?>">

                <label for="email" style="display:block; font-weight:600; margin-bottom:8px; color:#111827;">E-mail</label>
                <input type="email" name="email" id="email" required
                    style="width:100%; padding:14px 16px; border:1px solid #d1d5db; border-radius:12px; margin-bottom:18px; font-size:16px;">

                <button type="submit"
                    style="width:100%; padding:14px 18px; background:#0b2c61; color:#fff; border:none; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer;">
                    Receber código
                </button>
            </form>
            <?php
            echo '</div>';
            return ob_get_clean();
        }

        $codigo_bloqueado = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $tabela_codigos
                 WHERE cliente_id = %d
                 AND bloqueado_ate IS NOT NULL
                 AND bloqueado_ate > %s
                 ORDER BY id DESC
                 LIMIT 1",
                $cliente->id,
                current_time('mysql')
            )
        );
        
        if ($codigo_bloqueado) {
            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px; margin-bottom:18px;">Por segurança, após várias tentativas inválidas, aguarde alguns minutos antes de solicitar um novo código.</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $codigo = (string) rand(100000, 999999);

        $wpdb->insert(
            $tabela_codigos,
            [
                'cliente_id' => $cliente->id,
                'codigo'     => $codigo,
                'expira_em'  => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                'usado'      => 0,
                'tentativas' => 0,
            ],
            ['%d', '%s', '%s', '%d', '%d']
        );

        pb_enviar_codigo_email($cliente->email, $codigo);

        echo '<h2 style="margin:0 0 10px; color:#0b2c61; font-size:30px;">Código de verificação</h2>';
        echo '<p style="margin:0 0 12px; color:#6b7280;">Enviamos um código para o e-mail <strong>' . esc_html(pb_mascarar_email($cliente->email)) . '</strong>.</p>';
        echo '<div style="padding:12px 14px; background:#eff6ff; color:#1d4ed8; border-radius:10px; margin-bottom:20px;">Verifique sua caixa de e-mail para acessar o código.</div>';
        ?>
        <form method="post">
            <input type="hidden" name="etapa" value="validar">
            <input type="hidden" name="cliente_id" value="<?php echo esc_attr($cliente->id); ?>">

            <label for="codigo" style="display:block; font-weight:600; margin-bottom:8px; color:#111827;">Digite o código</label>
            <input type="text" name="codigo" id="codigo" required
                style="width:100%; padding:14px 16px; border:1px solid #d1d5db; border-radius:12px; margin-bottom:18px; font-size:16px;">

            <button type="submit"
                style="width:100%; padding:14px 18px; background:#0b2c61; color:#fff; border:none; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer;">
                Entrar
            </button>
        </form>
        <?php
        echo '</div>';
        return ob_get_clean();
    }

    if ($etapa === 'email') {
        $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (!$cliente_id || empty($email) || !is_email($email)) {
            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px;">E-mail inválido.</div>';
            echo '<p style="margin-top:18px;"><a href="' . esc_url($pagina_atual) . '" style="color:#0b2c61; font-weight:600;">Voltar</a></p>';
            echo '</div>';
            return ob_get_clean();
        }

        $wpdb->update(
            $tabela_clientes,
            ['email' => $email],
            ['id' => $cliente_id],
            ['%s'],
            ['%d']
        );
        
        $codigo_bloqueado = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $tabela_codigos
                 WHERE cliente_id = %d
                 AND bloqueado_ate IS NOT NULL
                 AND bloqueado_ate > %s
                 ORDER BY id DESC
                 LIMIT 1",
                $cliente_id,
                current_time('mysql')
            )
        );
        
        if ($codigo_bloqueado) {
            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px; margin-bottom:18px;">Por segurança, após várias tentativas inválidas, aguarde alguns minutos antes de solicitar um novo código.</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $codigo = (string) rand(100000, 999999);

        $wpdb->insert(
            $tabela_codigos,
            [
                'cliente_id' => $cliente_id,
                'codigo'     => $codigo,
                'expira_em'  => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                'usado'      => 0,
                'tentativas' => 0,
            ],
            ['%d', '%s', '%s', '%d', '%d']
        );

        pb_enviar_codigo_email($email, $codigo);

        echo '<h2 style="margin:0 0 10px; color:#0b2c61; font-size:30px;">Código de verificação</h2>';
        echo '<p style="margin:0 0 12px; color:#6b7280;">Enviamos um código para o e-mail <strong>' . esc_html(pb_mascarar_email($email)) . '</strong>.</p>';
        echo '<div style="padding:12px 14px; background:#eff6ff; color:#1d4ed8; border-radius:10px; margin-bottom:20px;">Verifique sua caixa de e-mail para acessar o código.</div>';
        ?>
        <form method="post">
            <input type="hidden" name="etapa" value="validar">
            <input type="hidden" name="cliente_id" value="<?php echo esc_attr($cliente_id); ?>">

            <label for="codigo" style="display:block; font-weight:600; margin-bottom:8px; color:#111827;">Digite o código</label>
            <input type="text" name="codigo" id="codigo" required
                style="width:100%; padding:14px 16px; border:1px solid #d1d5db; border-radius:12px; margin-bottom:18px; font-size:16px;">

            <button type="submit"
                style="width:100%; padding:14px 18px; background:#0b2c61; color:#fff; border:none; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer;">
                Entrar
            </button>
        </form>
        <?php
        echo '</div>';
        return ob_get_clean();
    }

    if ($etapa === 'validar') {
        $cliente_id = isset($_POST['cliente_id']) ? intval($_POST['cliente_id']) : 0;
        $codigo = isset($_POST['codigo']) ? trim(sanitize_text_field($_POST['codigo'])) : '';

        if (!$cliente_id || empty($codigo)) {
            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px; margin-bottom:18px;">Código inválido.</div>';
            echo '</div>';
            return ob_get_clean();
        }

        $codigo_ativo = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $tabela_codigos 
                 WHERE cliente_id = %d 
                 AND usado = 0 
                 AND expira_em >= %s
                 ORDER BY id DESC
                 LIMIT 1",
                $cliente_id,
                current_time('mysql')
            )
        );

        if (!$codigo_ativo) {
            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px; margin-bottom:18px;">Código expirado. Solicite um novo acesso.</div>';
            echo '<a href="' . esc_url($pagina_atual) . '" style="display:block; text-align:center; padding:14px 18px; background:#0b2c61; color:#fff; text-decoration:none; border-radius:12px; font-weight:700;">Solicitar novo código</a>';
            echo '</div>';
            return ob_get_clean();
        }

        if (!empty($codigo_ativo->bloqueado_ate) && strtotime($codigo_ativo->bloqueado_ate) > time()) {
            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px; margin-bottom:18px;">Muitas tentativas incorretas. Tente novamente em alguns minutos.</div>';
            echo '<a href="' . esc_url($pagina_atual) . '" style="display:block; text-align:center; padding:14px 18px; background:#0b2c61; color:#fff; text-decoration:none; border-radius:12px; font-weight:700;">Voltar</a>';
            echo '</div>';
            return ob_get_clean();
        }

        if ($codigo_ativo->codigo !== $codigo) {
            $tentativas = intval($codigo_ativo->tentativas) + 1;

            $dados_update = ['tentativas' => $tentativas];
            $formatos = ['%d'];

            if ($tentativas >= 5) {
                $dados_update['bloqueado_ate'] = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $formatos[] = '%s';
            }

            $wpdb->update(
                $tabela_codigos,
                $dados_update,
                ['id' => $codigo_ativo->id],
                $formatos,
                ['%d']
            );

            echo '<div style="padding:14px 16px; background:#fef2f2; color:#991b1b; border-radius:12px; margin-bottom:18px;">Código inválido. Tentativa ' . intval($tentativas) . ' de 5.</div>';

            if ($tentativas >= 5) {
                echo '<p style="margin:0 0 18px; color:#6b7280;">Por segurança, este código foi bloqueado por 10 minutos.</p>';
                echo '<a href="' . esc_url($pagina_atual) . '" style="display:block; text-align:center; padding:14px 18px; background:#0b2c61; color:#fff; text-decoration:none; border-radius:12px; font-weight:700;">Solicitar novo código</a>';
            } else {
                ?>
                <form method="post">
                    <input type="hidden" name="etapa" value="validar">
                    <input type="hidden" name="cliente_id" value="<?php echo esc_attr($cliente_id); ?>">

                    <label for="codigo" style="display:block; font-weight:600; margin-bottom:8px; color:#111827;">Digite o código novamente</label>
                    <input type="text" name="codigo" id="codigo" required
                        style="width:100%; padding:14px 16px; border:1px solid #d1d5db; border-radius:12px; margin-bottom:18px; font-size:16px;">

                    <button type="submit"
                        style="width:100%; padding:14px 18px; background:#0b2c61; color:#fff; border:none; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer;">
                        Tentar novamente
                    </button>
                </form>
                <?php
            }

            echo '</div>';
            return ob_get_clean();
        }

        $wpdb->update(
            $tabela_codigos,
            ['usado' => 1],
            ['id' => $codigo_ativo->id],
            ['%d'],
            ['%d']
        );

        $_SESSION['pb_cliente_autenticado'] = $cliente_id;
        $_SESSION['pb_cliente_autenticado_tempo'] = time();

        ob_end_clean();
        return pb_render_area_autenticada($cliente_id);
    }

    echo '</div>';
    return ob_get_clean();
}

function pb_baixar_boleto_protegido() {
    if (!isset($_GET['pb_download'])) {
        return;
    }

    if (!isset($_SESSION['pb_cliente_autenticado'])) {
        wp_die('Acesso não autorizado.');
    }

    $boleto_id = intval($_GET['pb_download']);
    $cliente_id = intval($_SESSION['pb_cliente_autenticado']);
    
    if (
        empty($_GET['pb_download_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_GET['pb_download_nonce'])),
            'pb_download_' . $boleto_id
        )
    ) {
        wp_die('Link inválido ou expirado.');
    }

    if (!$boleto_id || !$cliente_id) {
        wp_die('Acesso inválido.');
    }

    global $wpdb;
    $tabela_boletos = $wpdb->prefix . 'pb_boletos';

    $boleto = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $tabela_boletos WHERE id = %d AND cliente_id = %d LIMIT 1",
            $boleto_id,
            $cliente_id
        )
    );

    if (!$boleto) {
        wp_die('Boleto não encontrado.');
    }

    $arquivo = $boleto->caminho_arquivo;

    if (!file_exists($arquivo)) {
        wp_die('Arquivo não encontrado.');
    }

    header('Content-Type: application/pdf');
    $download = isset($_GET['download']) ? intval($_GET['download']) : 0;

        if ($download) {
            header('Content-Disposition: attachment; filename="' . basename($arquivo) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . basename($arquivo) . '"');
        }
    header('Content-Length: ' . filesize($arquivo));
    readfile($arquivo);
    exit;
}

function pb_mascarar_telefone($telefone) {
    $numeros = preg_replace('/\D/', '', $telefone);

    if (strlen($numeros) < 4) {
        return $telefone;
    }

    return '(**) *****-' . substr($numeros, -4);
}

function pb_logout_cliente() {
    if (!isset($_GET['pb_logout'])) {
        return;
    }

    unset($_SESSION['pb_cliente_autenticado']);
    unset($_SESSION['pb_cliente_autenticado_tempo']);

    $redirect = remove_query_arg('pb_logout');
    wp_redirect($redirect);
    exit;
}

function pb_processar_fila_envio_boletos() {
    global $wpdb;

    $tabela_boletos = $wpdb->prefix . 'pb_boletos';
    $tabela_clientes = $wpdb->prefix . 'pb_clientes';

    $boletos = $wpdb->get_results("
        SELECT b.*, c.nome, c.email
        FROM $tabela_boletos b
        INNER JOIN $tabela_clientes c ON c.id = b.cliente_id
        WHERE b.email_status = 'agendado'
        ORDER BY b.id ASC
        LIMIT 20
    ");

    if (empty($boletos)) {
        return;
    }

    foreach ($boletos as $boleto) {
        if (empty($boleto->email) || !is_email($boleto->email)) {
            $wpdb->update($tabela_boletos, ['email_status' => 'sem_email'], ['id' => $boleto->id], ['%s'], ['%d']);
            continue;
        }

        if (empty($boleto->caminho_arquivo) || !file_exists($boleto->caminho_arquivo)) {
            $wpdb->update($tabela_boletos, ['email_status' => 'arquivo_nao_encontrado'], ['id' => $boleto->id], ['%s'], ['%d']);
            continue;
        }

        $mes = !empty($boleto->mes_referencia) ? $boleto->mes_referencia : 'atual';
        $vencimento = !empty($boleto->vencimento) ? date('d/m/Y', strtotime($boleto->vencimento)) : '-';
        $valor = !empty($boleto->valor) ? 'R$ ' . number_format($boleto->valor, 2, ',', '.') : '-';

        $assunto = 'Seu boleto ASSC Saúde - ' . $mes;

        $template = pb_obter_template_email_boleto();

        $mensagem = str_replace(
            ['{cliente}', '{mes}', '{vencimento}', '{valor}'],
            [$boleto->nome, $mes, $vencimento, $valor],
            $template
        );

        $anexos = [$boleto->caminho_arquivo];

        $anexo_extra = get_option('pb_anexo_extra_email_arquivo');
        
        if (!empty($anexo_extra) && file_exists($anexo_extra)) {
            $anexos[] = $anexo_extra;
        }
        
        $enviado = wp_mail(
            $boleto->email,
            $assunto,
            $mensagem,
            ['Content-Type: text/plain; charset=UTF-8'],
            $anexos
        );

        if ($enviado) {
            $wpdb->update(
                $tabela_boletos,
                [
                    'email_status' => 'enviado',
                    'email_enviado_em' => current_time('mysql'),
                ],
                ['id' => $boleto->id],
                ['%s', '%s'],
                ['%d']
            );
            
            pb_registrar_log_com_usuario(
                'boleto_enviado',
                "Boleto enviado por e-mail:\n" .
                "- Cliente: {$boleto->nome}\n" .
                "- E-mail: {$boleto->email}\n" .
                "- Vigência: {$boleto->mes_referencia}",
                $boleto->email_enviado_por
            );
            
            
            
        } else {
            $tentativas = intval($boleto->email_tentativas) + 1;

            $wpdb->update(
                $tabela_boletos,
                [
                    'email_tentativas' => $tentativas,
                    'email_status' => $tentativas >= 3 ? 'falhou' : 'agendado',
                ],
                ['id' => $boleto->id],
                ['%d', '%s'],
                ['%d']
            );
            
            pb_registrar_log_com_usuario(
                'falha_envio_boleto',
                "Falha ao enviar boleto:\n" .
                "- Cliente: {$boleto->nome}\n" .
                "- E-mail: {$boleto->email}\n" .
                "- Vigência: {$boleto->mes_referencia}\n" .
                "- Tentativa: {$tentativas}",
                $boleto->email_enviado_por
            );

            // Notificação interna após 3 falhas
            if ($tentativas >= 3) {
                pb_criar_notificacao('erro', "Falha no envio do boleto de {$boleto->nome} ({$boleto->mes_referencia}) após {$tentativas} tentativas.");
            }
        }

        sleep(3);
    }

    $pendentes = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM $tabela_boletos 
        WHERE email_status = 'agendado'
    ");

    if ($pendentes > 0) {
        wp_schedule_single_event(time() + 120, 'pb_processar_fila_envio_boletos');
    }
}

function pb_pagina_logs() {
    if (!pb_usuario_pode('pb_ver_logs')) {
        wp_die('Você não tem permissão para acessar os logs.');
    }

    global $wpdb;
    $tabela_logs = $wpdb->prefix . 'pb_logs';

    $filtro_usuario = isset($_GET['filtro_usuario']) ? intval($_GET['filtro_usuario']) : 0;
    $filtro_acao = isset($_GET['filtro_acao']) ? sanitize_text_field($_GET['filtro_acao']) : '';
    $data_inicio = isset($_GET['data_inicio']) ? sanitize_text_field($_GET['data_inicio']) : '';
    $data_fim = isset($_GET['data_fim']) ? sanitize_text_field($_GET['data_fim']) : '';

    $where = "WHERE 1=1";
    $params = [];

    if ($filtro_usuario) {
        $where .= " AND usuario_id = %d";
        $params[] = $filtro_usuario;
    }

    if (!empty($filtro_acao)) {
        $where .= " AND acao = %s";
        $params[] = $filtro_acao;
    }

    if (!empty($data_inicio)) {
        $where .= " AND created_at >= %s";
        $params[] = $data_inicio . ' 00:00:00';
    }

    if (!empty($data_fim)) {
        $where .= " AND created_at <= %s";
        $params[] = $data_fim . ' 23:59:59';
    }

    $sql = "
        SELECT *
        FROM $tabela_logs
        $where
        ORDER BY id DESC
        LIMIT 500
    ";

    $logs = !empty($params)
        ? $wpdb->get_results($wpdb->prepare($sql, $params))
        : $wpdb->get_results($sql);

    $usuarios_logs = $wpdb->get_results("
        SELECT DISTINCT usuario_id, usuario_nome
        FROM $tabela_logs
        WHERE usuario_id IS NOT NULL
        ORDER BY usuario_nome ASC
    ");

    $acoes_logs = $wpdb->get_results("
        SELECT DISTINCT acao
        FROM $tabela_logs
        ORDER BY acao ASC
    ");
    ?>

    <div class="wrap">
        <style>
            .pb-app {
                background:#f4f7fb;
                margin:0 0 0 -20px;
                padding:28px;
                min-height:0;
            }
        
            .pb-hero {
                background:linear-gradient(135deg,#071f46,#0b5ed7);
                color:#fff;
                border-radius:24px;
                padding:28px;
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:20px;
                flex-wrap:wrap;
                box-shadow:0 18px 45px rgba(11,44,97,.22);
                margin-bottom:22px;
            }
        
            .pb-page-title,
            .pb-hero-title {
                color:#fff !important;
                margin:0;
                font-size:30px;
                line-height:1.2;
                font-weight:800;
            }
            .pb-hero .notice,
            .pb-hero .updated,
            .pb-hero .error,
            .pb-hero .is-dismissible {
                display:none !important;
            }
        
            .pb-hero p {
                margin:8px 0 0;
                color:rgba(255,255,255,.78);
            }
        
            .pb-panel {
                background:#fff;
                border:1px solid #e2e8f0;
                border-radius:22px;
                padding:22px;
                box-shadow:0 10px 28px rgba(15,23,42,.06);
                margin-bottom:24px;
            }
        
            .pb-btn-white {
                background:#fff !important;
                color:#0b2c61 !important;
                border:0 !important;
                border-radius:12px !important;
                padding:8px 14px !important;
                font-weight:700 !important;
            }
            
            .pb-notices-area {
                margin: 0 0 18px 0;
                max-width: 820px;
            }
            
            .pb-notices-area:empty {
                display: none;
            }
        </style>
        <style>
            @media print {
                #adminmenumain,
                #wpadminbar,
                .notice,
                .pb-nao-imprimir,
                .page-title-action {
                    display: none !important;
                }

                #wpcontent {
                    margin-left: 0 !important;
                }

                body {
                    background: #fff !important;
                }

                table {
                    font-size: 12px;
                }

                pre {
                    white-space: pre-wrap;
                }
            }
        </style>

        <div class="pb-hero">
            
            <div>
                <div class="pb-hero-title">Logs do Portal</div>
                <p>Acompanhe ações, acessos, envios e alterações realizadas no sistema.</p>
            </div>
        
                <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_logs')); ?>" class="button pb-btn-white">
                    Atualizar
                </a>
            </div>

        <?php pb_render_admin_notices(); ?>

        <div class="pb-nao-imprimir" style="background:#fff; border:1px solid #e5eaf2; border-radius:14px; padding:16px 20px; margin-bottom:16px; box-shadow:0 2px 8px rgba(15,23,42,.04);">
            <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <input type="hidden" name="page" value="pb_boletos_logs">

                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Funcionário</label>
                    <select name="filtro_usuario" style="height:32px; font-size:12px; min-width:130px;">
                        <option value="">Todos</option>
                        <?php foreach ($usuarios_logs as $usuario_log) : ?>
                            <option value="<?php echo esc_attr($usuario_log->usuario_id); ?>" <?php selected($filtro_usuario, $usuario_log->usuario_id); ?>>
                                <?php echo esc_html($usuario_log->usuario_nome); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Ação</label>
                    <select name="filtro_acao" style="height:32px; font-size:12px; min-width:160px;">
                        <option value="">Todas</option>
                        <?php foreach ($acoes_logs as $acao_log) : ?>
                            <option value="<?php echo esc_attr($acao_log->acao); ?>" <?php selected($filtro_acao, $acao_log->acao); ?>>
                                <?php echo esc_html($acao_log->acao); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Data inicial</label>
                    <input type="date" name="data_inicio" value="<?php echo esc_attr($data_inicio); ?>" style="height:32px; font-size:12px;">
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Data final</label>
                    <input type="date" name="data_fim" value="<?php echo esc_attr($data_fim); ?>" style="height:32px; font-size:12px;">
                </div>

                <div style="display:flex; gap:6px; align-items:flex-end;">
                    <button type="submit"
                        style="display:inline-flex; align-items:center; height:32px; padding:0 14px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                        Filtrar
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_logs')); ?>"
                        style="display:inline-flex; align-items:center; height:32px; padding:0 12px; background:#fff; border:1px solid #e2e8f0; color:#64748b; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none;">
                        Limpar
                    </a>
                </div>
            </form>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
            <span style="font-size:12px; color:#64748b;"><strong><?php echo intval(count($logs)); ?></strong> logs encontrados</span>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <!-- Relatório de logs em nova guia -->
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" target="_blank">
                    <input type="hidden" name="action"          value="pb_relatorio_logs">
                    <input type="hidden" name="filtro_usuario"  value="<?php echo esc_attr($filtro_usuario); ?>">
                    <input type="hidden" name="filtro_acao"     value="<?php echo esc_attr($filtro_acao); ?>">
                    <input type="hidden" name="data_inicio"     value="<?php echo esc_attr($data_inicio); ?>">
                    <input type="hidden" name="data_fim"        value="<?php echo esc_attr($data_fim); ?>">
                    <?php wp_nonce_field('pb_relatorio_logs_action', 'pb_nonce'); ?>
                    <button type="submit"
                        style="display:inline-flex; align-items:center; height:30px; padding:0 12px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                        📄 Relatório de Logs
                    </button>
                </form>

                <?php if (current_user_can('manage_options')) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="pb-form-excluir-logs">
                        <input type="hidden" name="action" value="pb_excluir_logs_selecionados">
                        <?php wp_nonce_field('pb_excluir_logs_action', 'pb_nonce'); ?>
                        <div id="pb-logs-ids-container"></div>
                        <button type="submit" id="pb-btn-excluir-logs"
                            style="display:inline-flex; align-items:center; height:30px; padding:0 12px; background:#fff; border:1px solid #fca5a5; color:#ef4444; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; display:none;"
                            onclick="return confirm('Excluir os logs selecionados? Esta ação não pode ser desfeita.');">
                            Excluir selecionados
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="pb_limpar_logs">
                        <?php wp_nonce_field('pb_limpar_logs_action', 'pb_nonce'); ?>
                        <button type="submit"
                            style="display:inline-flex; align-items:center; height:30px; padding:0 12px; background:#fff; border:1px solid #fca5a5; color:#ef4444; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;"
                            onclick="return confirm('Limpar TODOS os logs? Esta ação não pode ser desfeita.');">
                            Limpar todos
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div style="background:#fff; border:1px solid #e5eaf2; border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(15,23,42,.04);">
        <table class="widefat" style="border:0; margin:0;">
            <thead>
                <tr style="background:#f8fafc;">
                    <?php if (current_user_can('manage_options')) : ?>
                    <th style="width:36px; padding:10px 8px; border-bottom:1px solid #e2e8f0;">
                        <input type="checkbox" id="pb-check-all-logs" onchange="pbToggleAllLogs(this)">
                    </th>
                    <?php endif; ?>
                    <th style="padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0; white-space:nowrap;">Data/Hora</th>
                    <th style="padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0; white-space:nowrap;">Funcionário</th>
                    <th style="padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0; white-space:nowrap;">Ação</th>
                    <th style="padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0;">Detalhes</th>
                    <th style="padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; border-bottom:1px solid #e2e8f0; white-space:nowrap;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)) : ?>
                    <?php foreach ($logs as $i => $log) : ?>
                        <tr style="<?php echo $i % 2 === 0 ? 'background:#fff;' : 'background:#fbfdff;'; ?> border-bottom:1px solid #f1f5f9;">
                            <?php if (current_user_can('manage_options')) : ?>
                            <td style="padding:10px 8px; vertical-align:middle;">
                                <input type="checkbox" class="pb-log-check" value="<?php echo esc_attr($log->id); ?>" onchange="pbUpdateLogSelection()">
                            </td>
                            <?php endif; ?>
                            <td style="padding:10px 12px; font-size:12px; color:#475569; white-space:nowrap; vertical-align:top;">
                                <?php echo esc_html(date('d/m/Y', strtotime($log->created_at))); ?><br>
                                <span style="font-size:11px; color:#94a3b8;"><?php echo esc_html(date('H:i:s', strtotime($log->created_at))); ?></span>
                            </td>
                            <td style="padding:10px 12px; font-size:12px; font-weight:600; color:#0f172a; vertical-align:top; white-space:nowrap;"><?php echo esc_html($log->usuario_nome); ?></td>
                            <td style="padding:10px 12px; vertical-align:top;">
                                <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700;
                                    background:#f1f5f9; color:#475569; white-space:nowrap;">
                                    <?php echo esc_html($log->acao); ?>
                                </span>
                            </td>
                            <td style="padding:10px 12px; font-size:12px; color:#475569; vertical-align:top; max-width:340px;">
                                <pre style="white-space:pre-wrap; margin:0; font-family:inherit; font-size:11px; color:#64748b; line-height:1.5;"><?php echo esc_html($log->detalhes); ?></pre>
                            </td>
                            <td style="padding:10px 12px; font-size:11px; color:#94a3b8; vertical-align:top; white-space:nowrap;"><?php echo esc_html($log->ip); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="<?php echo current_user_can('manage_options') ? 6 : 5; ?>"
                            style="padding:28px; text-align:center; color:#94a3b8; font-size:13px;">
                            Nenhum log encontrado com esses filtros.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if (current_user_can('manage_options')) : ?>
        <script>
        function pbToggleAllLogs(checkbox) {
            document.querySelectorAll('.pb-log-check').forEach(function(cb) { cb.checked = checkbox.checked; });
            pbUpdateLogSelection();
        }
        function pbUpdateLogSelection() {
            var checked = document.querySelectorAll('.pb-log-check:checked');
            var btn     = document.getElementById('pb-btn-excluir-logs');
            var container = document.getElementById('pb-logs-ids-container');
            container.innerHTML = '';
            checked.forEach(function(cb) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'logs[]'; input.value = cb.value;
                container.appendChild(input);
            });
            btn.style.display = checked.length > 0 ? 'inline-flex' : 'none';
        }
        </script>
        <?php endif; ?>

        </div><!-- .pb-app -->
    </div><!-- .wrap -->

    <?php
}


function pb_pagina_permissoes() {
    if (!pb_usuario_pode('pb_ver_permissoes')) {
        wp_die('Você não tem permissão para acessar esta página.');
    }

    $usuarios = get_users([
        'role__in' => ['pb_funcionario_boletos', 'pb_gestor_boletos'],
        'orderby' => 'display_name',
        'order'   => 'ASC',
    ]);

    $permissoes = [
        'pb_ver_boletos' => 'Acessar submenu Boletos',
        'pb_importar_boletos' => 'Importar boletos',
        'pb_excluir_boletos' => 'Excluir boletos',
        'pb_enviar_boletos' => 'Enviar boletos por e-mail',
        'pb_registrar_pagamento' => 'Registrar pagamento de boletos',
    
        'pb_ver_clientes' => 'Acessar submenu Clientes',
        'pb_editar_email_cliente' => 'Editar e-mail dos clientes',
        'pb_excluir_clientes' => 'Excluir clientes',
    
        'pb_editar_mensagem_email' => 'Acessar submenu Mensagem de E-mail',
    
        'pb_ver_logs' => 'Acessar submenu Logs',
        'pb_limpar_logs' => 'Limpar logs',
    
        'pb_ver_funcionarios' => 'Acessar submenu Funcionários',
        'pb_ver_permissoes' => 'Acessar submenu Permissões',
    
        'pb_editar_pagina_inicial' => 'Editar página inicial pelo Elementor',
    ];
    ?>
    <div class="wrap">
        <style>
            .pb-app {
                background:#f4f7fb;
                margin:0 0 0 -20px;
                padding:28px;
                min-height:0;
            }
        
            .pb-hero {
                background:linear-gradient(135deg,#082451,#0b5ed7);
                color:#fff;
                border-radius:18px;
                padding:22px 26px;
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:20px;
                box-shadow:0 14px 34px rgba(11,44,97,.20);
                margin-bottom:22px;
            }
        
            .pb-hero-title {
                color:#ffffff !important;
                margin:0 0 8px 0 !important;
                font-size:32px !important;
                line-height:1.15 !important;
                font-weight:800 !important;
                letter-spacing:-0.03em;
            }
            
            .pb-hero .notice,
            .pb-hero .updated,
            .pb-hero .error,
            .pb-hero .is-dismissible {
                display:none !important;
            }
        
            .pb-hero p {
                margin:8px 0 0;
                color:rgba(255,255,255,.78);
            }
        
            .pb-panel {
                background:#fff;
                border:1px solid #e2e8f0;
                border-radius:22px;
                padding:22px;
                box-shadow:0 10px 28px rgba(15,23,42,.06);
                margin-bottom:24px;
            }
        
            .pb-table-wrap {
                overflow:auto;
                border:1px solid #e2e8f0;
                border-radius:18px;
                background:#fff;
            }
        
            .pb-table-wrap table {
                border:0 !important;
                margin:0;
            }
        
            .widefat thead th {
                background:#f8fafc;
                color:#334155;
                font-weight:800;
            }
        
            .widefat tbody tr:hover {
                background:#f8fbff;
            }
        
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="date"],
            select,
            textarea {
                border-radius:10px !important;
                border:1px solid #cbd5e1 !important;
            }
        
            .button-primary {
                background:#0b5ed7 !important;
                border-color:#0b5ed7 !important;
                border-radius:12px !important;
                font-weight:700 !important;
            }
        
            .button-secondary,
            .button {
                border-radius:12px !important;
            }
        </style>
        <div class="pb-app">
        <div class="pb-hero">
            <div>
                <div class="pb-page-title">Permissões</div>
                <p>Defina quais áreas e ações cada funcionário pode acessar.</p>
            </div>
        </div>

        <?php pb_render_admin_notices(); ?>

        <div class="pb-panel">
        <div class="pb-table-wrap">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Funcionário</th>
                    <th>E-mail</th>
                    <th>Permissões</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $usuario) : ?>
                    <tr>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="pb_salvar_permissoes_usuario">
                            <input type="hidden" name="usuario_id" value="<?php echo esc_attr($usuario->ID); ?>">
                            <?php wp_nonce_field('pb_salvar_permissoes_usuario_action', 'pb_nonce'); ?>

                            <td><strong><?php echo esc_html($usuario->display_name); ?></strong></td>
                            <td><?php echo esc_html($usuario->user_email); ?></td>

                            <td>
                                <?php foreach ($permissoes as $cap => $label) : ?>
                                    <label style="display:block; margin-bottom:6px;">
                                        <input type="checkbox" name="permissoes[]" value="<?php echo esc_attr($cap); ?>"
                                            <?php checked(user_can($usuario, $cap)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>

                            <td>
                                <?php submit_button('Salvar', 'primary small', 'submit', false); ?>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
    <?php
}

function pb_salvar_permissoes_usuario() {
    if (!pb_usuario_pode('pb_ver_permissoes')) {
        wp_die('Você não tem permissão para alterar permissões.');
    }

    check_admin_referer('pb_salvar_permissoes_usuario_action', 'pb_nonce');

    $usuario_id = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : 0;
    $usuario = $usuario_id ? get_userdata($usuario_id) : null;

    if (!$usuario) {
        wp_die('Usuário não encontrado.');
    }

    $permissoes_disponiveis = [
        'pb_ver_boletos',
        'pb_importar_boletos',
        'pb_excluir_boletos',
        'pb_enviar_boletos',
        'pb_registrar_pagamento',

        'pb_ver_clientes',
        'pb_editar_email_cliente',
        'pb_excluir_clientes',

        'pb_editar_mensagem_email',

        'pb_ver_logs',
        'pb_limpar_logs',
        
        'pb_ver_funcionarios',
        'pb_ver_permissoes',
        'pb_editar_pagina_inicial',
    ];

    $permissoes_marcadas = isset($_POST['permissoes']) && is_array($_POST['permissoes'])
        ? array_map('sanitize_text_field', $_POST['permissoes'])
        : [];

    foreach ($permissoes_disponiveis as $permissao) {
        if (in_array($permissao, $permissoes_marcadas, true)) {
            $usuario->add_cap($permissao);
        } else {
            $usuario->remove_cap($permissao);
        }
        
    $permissoes_elementor = [
        'edit_pages',
        'edit_others_pages',
        'edit_published_pages',
        'publish_pages',
        'upload_files',
        'edit_posts',
        'edit_others_posts',
        'edit_published_posts',
    ];
    
    foreach ($permissoes_elementor as $cap_elementor) {
        if (in_array('pb_editar_pagina_inicial', $permissoes_marcadas, true)) {
            $usuario->add_cap($cap_elementor);
        } else {
            $usuario->remove_cap($cap_elementor);
        }
    }
        
    }

    pb_registrar_log(
        'alteracao_permissoes_usuario',
        "Permissões alteradas:\n" .
        "- Funcionário: {$usuario->display_name}\n" .
        "- Permissões ativas: " . (!empty($permissoes_marcadas) ? implode(', ', $permissoes_marcadas) : 'Nenhuma')
    );

    wp_redirect(admin_url('admin.php?page=pb_boletos_permissoes&permissoes=ok'));
    exit;
}

function pb_registrar_log_com_usuario($acao, $detalhes = '', $usuario_id = 0) {
    global $wpdb;

    $usuario_id = intval($usuario_id);
    $usuario = $usuario_id ? get_userdata($usuario_id) : null;

    $wpdb->insert(
        $wpdb->prefix . 'pb_logs',
        [
            'usuario_id'   => $usuario_id ?: null,
            'usuario_nome' => $usuario ? $usuario->display_name : 'Sistema',
            'acao'         => sanitize_text_field($acao),
            'detalhes'     => is_array($detalhes) ? wp_json_encode($detalhes) : sanitize_textarea_field($detalhes),
            'ip'           => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );
}

function pb_formatar_boletos_log($ids) {
    global $wpdb;

    $ids = array_filter(array_map('intval', (array) $ids));

    if (empty($ids)) {
        return 'Nenhum boleto válido informado.';
    }

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    $boletos = $wpdb->get_results(
        $wpdb->prepare("
            SELECT 
                b.id,
                b.mes_referencia,
                b.valor,
                c.nome
            FROM {$wpdb->prefix}pb_boletos b
            LEFT JOIN {$wpdb->prefix}pb_clientes c ON c.id = b.cliente_id
            WHERE b.id IN ($placeholders)
            ORDER BY c.nome ASC
        ", $ids)
    );

    if (empty($boletos)) {
        return 'Nenhum boleto encontrado.';
    }

    $linhas = [];

    foreach ($boletos as $boleto) {
        $valor = !empty($boleto->valor)
            ? 'R$ ' . number_format((float) $boleto->valor, 2, ',', '.')
            : 'Valor não informado';

        $linhas[] = '- ' . ($boleto->nome ?: 'Cliente não encontrado')
            . ' | Vigência: ' . ($boleto->mes_referencia ?: '-')
            . ' | Valor: ' . $valor;
    }

    return implode("\n", $linhas);
}

function pb_obter_template_email_boleto() {
    $template = get_option('pb_template_email_boleto');

    if (empty($template)) {
        $template = "Olá, {cliente}.\n\n";
        $template .= "Segue em anexo o seu boleto ASSC Saúde.\n\n";
        $template .= "Referência: {mes}\n";
        $template .= "Vencimento: {vencimento}\n";
        $template .= "Valor: {valor}\n\n";
        $template .= "Caso já tenha realizado o pagamento, desconsidere esta mensagem.\n\n";
        $template .= "ASSC Saúde";
    }

    return $template;
}

function pb_salvar_template_email() {
    if (!pb_usuario_pode('pb_editar_mensagem_email')) {
        wp_die('Sem permissão.');
    }

    check_admin_referer('pb_salvar_template_email_action', 'pb_nonce');

    $template = isset($_POST['template_email'])
        ? sanitize_textarea_field(wp_unslash($_POST['template_email']))
        : '';

    update_option('pb_template_email_boleto', $template);

    if (!empty($_FILES['anexo_extra_email']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload($_FILES['anexo_extra_email'], [
            'test_form' => false,
        ]);

        if (!empty($upload['file']) && empty($upload['error'])) {
            update_option('pb_anexo_extra_email_arquivo', $upload['file']);
            update_option('pb_anexo_extra_email_url', $upload['url']);
            update_option('pb_anexo_extra_email_nome', basename($upload['file']));
        }
    }

    pb_registrar_log('alteracao_template_email', 'Template/anexo de e-mail dos boletos foi atualizado.');

    wp_redirect(admin_url('admin.php?page=pb_boletos_mensagem_email&template=ok'));
    exit;
}

function pb_pagina_mensagem_email() {
    if (!pb_usuario_pode('pb_editar_mensagem_email')) {
        wp_die('Você não tem permissão para editar a mensagem de e-mail.');
    }

    ?>
    <div class="wrap">
        <style>
            .pb-app {
                background:#f4f7fb;
                margin:0 0 0 -20px;
                padding:28px;
                min-height:0;
            }
        
            .pb-hero {
                background:linear-gradient(135deg,#071f46,#0b5ed7);
                color:#fff;
                border-radius:24px;
                padding:28px;
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:20px;
                flex-wrap:wrap;
                box-shadow:0 18px 45px rgba(11,44,97,.22);
                margin-bottom:22px;
            }
        
            .pb-hero h1 {
                color:#fff;
                margin:0;
                font-size:30px;
                font-weight:800;
            }
        
            .pb-hero p {
                margin:8px 0 0;
                color:rgba(255,255,255,.78);
            }
        
            .pb-panel {
                background:#fff;
                border:1px solid #e2e8f0;
                border-radius:22px;
                padding:22px;
                box-shadow:0 10px 28px rgba(15,23,42,.06);
                margin-bottom:24px;
            }
        
            .pb-table-wrap {
                overflow:auto;
                border:1px solid #e2e8f0;
                border-radius:18px;
                background:#fff;
            }
        
            .pb-table-wrap table {
                border:0 !important;
                margin:0;
            }
        
            .widefat thead th {
                background:#f8fafc;
                color:#334155;
                font-weight:800;
            }
        
            .widefat tbody tr:hover {
                background:#f8fbff;
            }
        
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="date"],
            select,
            textarea {
                border-radius:10px !important;
                border:1px solid #cbd5e1 !important;
            }
        
            .button-primary {
                background:#0b5ed7 !important;
                border-color:#0b5ed7 !important;
                border-radius:12px !important;
                font-weight:700 !important;
            }
        
            .button-secondary,
            .button {
                border-radius:12px !important;
            }
        </style>
        <div class="pb-app">
        <div class="pb-hero">
            <div>
                <div class="pb-page-title">Mensagem de E-mail</div>
                <p>Configure o texto padrão e anexos extras enviados junto aos boletos.</p>
            </div>
        </div>

        <?php pb_render_admin_notices(); ?>


        <div class="pb-panel" style="max-width:860px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
                <div style="width:32px; height:32px; background:#dbeafe; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px;">✉</div>
                <div>
                    <h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">Mensagem padrão de envio</h2>
                    <p style="margin:2px 0 0; font-size:12px; color:#64748b;">Use: <code>{cliente}</code>, <code>{mes}</code>, <code>{vencimento}</code>, <code>{valor}</code></p>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="pb_salvar_template_email">
                <?php wp_nonce_field('pb_salvar_template_email_action', 'pb_nonce'); ?>

                <textarea name="template_email" rows="10"
                    style="width:100%; font-size:13px; padding:12px; border:1px solid #e2e8f0; border-radius:10px; resize:vertical; line-height:1.6;"><?php echo esc_textarea(pb_obter_template_email_boleto()); ?></textarea>

                <div style="margin-top:20px; padding-top:16px; border-top:1px solid #f1f5f9;">
                    <h3 style="margin:0 0 6px; font-size:13px; font-weight:700; color:#0f172a;">Anexo extra opcional</h3>
                    <p style="margin:0 0 12px; font-size:12px; color:#64748b;">Arquivo extra enviado junto com o boleto (ex: aviso, comunicado).</p>

                    <?php
                    $anexo_nome = get_option('pb_anexo_extra_email_nome');
                    $anexo_url  = get_option('pb_anexo_extra_email_url');
                    ?>

                    <?php if (!empty($anexo_nome)) : ?>
                        <div style="display:inline-flex; align-items:center; gap:8px; padding:8px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:10px; font-size:12px;">
                            📎 <a href="<?php echo esc_url($anexo_url); ?>" target="_blank" style="color:#0b5ed7; font-weight:600;"><?php echo esc_html($anexo_nome); ?></a>
                        </div><br>
                    <?php endif; ?>

                    <input type="file" name="anexo_extra_email" style="font-size:13px; margin-bottom:16px;">
                </div>

                <button type="submit"
                    style="display:inline-flex; align-items:center; height:34px; padding:0 16px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                    Salvar mensagem
                </button>
            </form>

            <?php if (!empty($anexo_nome)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                    <input type="hidden" name="action" value="pb_remover_anexo_extra_email">
                    <?php wp_nonce_field('pb_remover_anexo_extra_email_action', 'pb_nonce'); ?>
                    <button type="submit"
                        style="display:inline-flex; align-items:center; height:30px; padding:0 12px; background:#fff; border:1px solid #fca5a5; color:#ef4444; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;"
                        onclick="return confirm('Deseja remover o anexo extra atual?');">
                        Remover anexo extra
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function pb_limpar_logs() {
    if (!pb_usuario_pode('pb_limpar_logs')) {
        wp_die('Sem permissão para limpar logs.');
    }

    check_admin_referer('pb_limpar_logs_action', 'pb_nonce');

    global $wpdb;
    $tabela_logs = $wpdb->prefix . 'pb_logs';

    $wpdb->query("TRUNCATE TABLE $tabela_logs");

    wp_redirect(admin_url('admin.php?page=pb_boletos_logs&logs=limpos'));
    exit;
}

function pb_remover_anexo_extra_email() {
    if (!pb_usuario_pode('pb_editar_mensagem_email')) {
        wp_die('Sem permissão.');
    }

    check_admin_referer('pb_remover_anexo_extra_email_action', 'pb_nonce');

    $arquivo = get_option('pb_anexo_extra_email_arquivo');

    if (!empty($arquivo) && file_exists($arquivo)) {
        @unlink($arquivo);
    }

    delete_option('pb_anexo_extra_email_arquivo');
    delete_option('pb_anexo_extra_email_url');
    delete_option('pb_anexo_extra_email_nome');

    pb_registrar_log('remocao_anexo_extra_email', 'Anexo extra de e-mail foi removido.');

    wp_redirect(admin_url('admin.php?page=pb_boletos_mensagem_email&anexo=removido'));
    exit;
}

function pb_pagina_clientes() {
    if (!pb_usuario_pode('pb_ver_clientes')) {
        wp_die('Você não tem permissão para acessar esta página.');
    }

    global $wpdb;

    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    $tabela_boletos  = $wpdb->prefix . 'pb_boletos';

    $cliente_edicao = null;

    if (isset($_GET['editar_email_cliente'])) {
        $cliente_id_edicao = intval($_GET['editar_email_cliente']);
        if ($cliente_id_edicao) {
            $cliente_edicao = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $tabela_clientes WHERE id = %d", $cliente_id_edicao)
            );
        }
    }

    $filtro_nome        = isset($_GET['filtro_nome'])       ? sanitize_text_field($_GET['filtro_nome'])  : '';
    $filtro_sem_boleto  = isset($_GET['filtro_sem_boleto']) ? intval($_GET['filtro_sem_boleto'])          : 0;
    $ver_cliente_id     = isset($_GET['ver'])               ? intval($_GET['ver'])                        : 0;
    $mes_ref            = date('m/Y');

    $where  = 'WHERE 1=1';
    $params = [];

    if (!empty($filtro_nome)) {
        $busca    = '%' . $wpdb->esc_like($filtro_nome) . '%';
        $where   .= ' AND (c.nome LIKE %s OR c.cpf LIKE %s)';
        $params[] = $busca;
        $params[] = $busca;
    }

    if ($filtro_sem_boleto) {
        $where .= $wpdb->prepare(
            " AND NOT EXISTS (SELECT 1 FROM $tabela_boletos b WHERE b.cliente_id = c.id AND b.mes_referencia = %s)",
            $mes_ref
        );
    }

    $sql_clientes = "
        SELECT
            c.*,
            COUNT(b.id) AS total_boletos,
            MAX(b.created_at) AS ultimo_boleto
        FROM $tabela_clientes c
        LEFT JOIN $tabela_boletos b ON b.cliente_id = c.id
        $where
        GROUP BY c.id
        ORDER BY c.nome ASC
    ";

    $clientes = !empty($params)
        ? $wpdb->get_results($wpdb->prepare($sql_clientes, $params))
        : $wpdb->get_results($sql_clientes);

    $total_sem_boleto_mes = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $tabela_clientes c
        WHERE NOT EXISTS (
            SELECT 1 FROM $tabela_boletos b
            WHERE b.cliente_id = c.id AND b.mes_referencia = %s
        )
    ", $mes_ref));
    ?>
    <div class="wrap">
        <style>
            .pb-app {
                background:#f4f7fb;
                margin:0 0 0 -20px;
                padding:28px;
                min-height:0;
            }
        
            .pb-hero {
                background:linear-gradient(135deg,#071f46,#0b5ed7);
                color:#fff;
                border-radius:24px;
                padding:28px;
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:20px;
                flex-wrap:wrap;
                box-shadow:0 18px 45px rgba(11,44,97,.22);
                margin-bottom:22px;
            }
        
            .pb-hero h1 {
                color:#fff;
                margin:0;
                font-size:30px;
                font-weight:800;
            }
        
            .pb-hero p {
                margin:8px 0 0;
                color:rgba(255,255,255,.78);
            }
        
            .pb-panel {
                background:#fff;
                border:1px solid #e2e8f0;
                border-radius:22px;
                padding:22px;
                box-shadow:0 10px 28px rgba(15,23,42,.06);
                margin-bottom:24px;
            }
        
            .pb-table-wrap {
                overflow:auto;
                border:1px solid #e2e8f0;
                border-radius:18px;
                background:#fff;
            }
        
            .pb-table-wrap table {
                border:0 !important;
                margin:0;
            }
        
            .widefat thead th {
                background:#f8fafc;
                color:#334155;
                font-weight:800;
            }
        
            .widefat tbody tr:hover {
                background:#f8fbff;
            }
        
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="date"],
            select,
            textarea {
                border-radius:10px !important;
                border:1px solid #cbd5e1 !important;
            }
        
            .button-primary {
                background:#0b5ed7 !important;
                border-color:#0b5ed7 !important;
                border-radius:12px !important;
                font-weight:700 !important;
            }
        
            .button-secondary,
            .button {
                border-radius:12px !important;
            }
        </style>
        <div class="pb-app">
        <div class="pb-hero">
            <div>
                <div class="pb-page-title">Clientes</div>
                <p>Gerencie clientes, e-mails cadastrados e boletos vinculados.</p>
            </div>
        </div>

        <?php pb_render_admin_notices(); ?>

        <?php if ($cliente_edicao) : ?>
            <div class="pb-panel" style="max-width:560px; margin-bottom:20px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                    <div style="width:32px; height:32px; background:#dcfce7; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px;">✉</div>
                    <div>
                        <h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">Editar e-mail do cliente</h2>
                        <p style="margin:2px 0 0; font-size:12px; color:#64748b;"><?php echo esc_html($cliente_edicao->nome); ?> &nbsp;·&nbsp; <?php echo esc_html($cliente_edicao->cpf); ?></p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pb_atualizar_email_cliente">
                    <input type="hidden" name="cliente_id" value="<?php echo esc_attr($cliente_edicao->id); ?>">
                    <input type="hidden" name="retorno" value="clientes">
                    <?php wp_nonce_field('pb_atualizar_email_cliente_action', 'pb_nonce'); ?>
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:5px;">E-mail</label>
                        <input type="email" name="email" id="email" value="<?php echo esc_attr($cliente_edicao->email); ?>"
                            style="width:100%; height:36px; padding:0 12px; font-size:13px;" required>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="submit"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 16px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                            Salvar e-mail
                        </button>
                        <button type="submit" name="remover_email" value="1"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 14px; background:#fff; border:1px solid #fca5a5; color:#ef4444; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;"
                            onclick="return confirm('Deseja remover o e-mail deste cliente?');">
                            Remover e-mail
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_clientes')); ?>"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 14px; background:#fff; border:1px solid #e2e8f0; color:#64748b; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none;">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Painel ver/editar cliente -->
        <?php if ($ver_cliente_id) :
            $cliente_ver = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabela_clientes WHERE id = %d", $ver_cliente_id));
            $boletos_cliente = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $tabela_boletos WHERE cliente_id = %d ORDER BY vencimento DESC LIMIT 12",
                $ver_cliente_id
            ));
        ?>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; margin-bottom:20px; box-shadow:0 4px 14px rgba(15,23,42,.06);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:44px; height:44px; background:#dbeafe; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px;">👤</div>
                    <div>
                        <h2 style="margin:0; font-size:17px; font-weight:800; color:#0f172a;"><?php echo esc_html($cliente_ver->nome); ?></h2>
                        <p style="margin:3px 0 0; font-size:12px; color:#64748b;">CPF: <?php echo esc_html($cliente_ver->cpf); ?></p>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_clientes')); ?>"
                    style="display:inline-flex; align-items:center; height:32px; padding:0 14px; background:#fff; border:1px solid #e2e8f0; color:#64748b; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none;">
                    ← Voltar
                </a>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <!-- Formulário de edição -->
                <div>
                    <h3 style="margin:0 0 14px; font-size:13px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:.04em;">Dados cadastrais</h3>
                    <?php if (pb_usuario_pode('pb_editar_email_cliente')) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action"     value="pb_atualizar_cliente">
                        <input type="hidden" name="cliente_id" value="<?php echo esc_attr($cliente_ver->id); ?>">
                        <?php wp_nonce_field('pb_atualizar_cliente_action', 'pb_nonce'); ?>

                        <?php
                        $campos = [
                            ['email',        'email', 'E-mail',       isset($cliente_ver->email)        ? $cliente_ver->email        : ''],
                            ['whatsapp',     'text',  'WhatsApp',     isset($cliente_ver->whatsapp)     ? $cliente_ver->whatsapp     : ''],
                            ['endereco',     'text',  'Endereço',     isset($cliente_ver->endereco)     ? $cliente_ver->endereco     : ''],
                            ['nr_documento', 'text',  'Nr Documento', isset($cliente_ver->nr_documento) ? $cliente_ver->nr_documento : ''],
                        ];
                        $placeholders = [
                            'email'        => 'Sem e-mail cadastrado',
                            'whatsapp'     => 'Ex: (85) 99999-0000',
                            'endereco'     => 'Endereço extraído do boleto',
                            'nr_documento' => 'Ex: 00111/0426',
                        ];
                        foreach ($campos as $c) : ?>
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;"><?php echo $c[2]; ?></label>
                            <input type="<?php echo $c[1]; ?>" name="<?php echo $c[0]; ?>" value="<?php echo esc_attr($c[3]); ?>"
                                placeholder="<?php echo esc_attr($placeholders[$c[0]]); ?>"
                                style="width:100%; height:34px; padding:0 10px; font-size:13px; border:1px solid #e2e8f0; border-radius:8px; color:<?php echo empty($c[3]) ? '#94a3b8' : '#0f172a'; ?>;">
                        </div>
                        <?php endforeach; ?>

                        <button type="submit"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 16px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; margin-top:4px;">
                            Salvar alterações
                        </button>
                    </form>
                    <?php else : ?>
                    <div style="font-size:13px; color:#334155; line-height:2;">
                        <div><strong>E-mail:</strong>
                            <?php if (!empty($cliente_ver->email)) : ?>
                                <?php echo esc_html($cliente_ver->email); ?>
                            <?php else : ?>
                                <span style="color:#94a3b8; font-style:italic;">Sem e-mail — aguardando cadastro pelo cliente</span>
                            <?php endif; ?>
                        </div>
                        <div><strong>WhatsApp:</strong> <?php echo esc_html(!empty($cliente_ver->whatsapp) ? $cliente_ver->whatsapp : '—'); ?></div>
                        <div><strong>Endereço:</strong> <?php echo esc_html(!empty($cliente_ver->endereco) ? $cliente_ver->endereco : '—'); ?></div>
                        <div><strong>Nr Documento:</strong> <?php echo esc_html(!empty($cliente_ver->nr_documento) ? $cliente_ver->nr_documento : '—'); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Histórico de boletos -->
                <div>
                    <h3 style="margin:0 0 14px; font-size:13px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:.04em;">Histórico de boletos</h3>
                    <?php if (empty($boletos_cliente)) : ?>
                        <p style="font-size:13px; color:#94a3b8;">Nenhum boleto importado.</p>
                    <?php else : ?>
                    <div style="display:flex; flex-direction:column; gap:6px; max-height:320px; overflow-y:auto;">
                        <?php foreach ($boletos_cliente as $bc) : ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:#f8fafc; border-radius:8px; border:1px solid #f1f5f9; font-size:12px;">
                            <div>
                                <span style="font-weight:700; color:#0f172a;"><?php echo esc_html($bc->mes_referencia ?: '—'); ?></span>
                                <span style="color:#94a3b8; margin-left:8px;"><?php echo !empty($bc->vencimento) ? date('d/m/Y', strtotime($bc->vencimento)) : ''; ?></span>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="font-weight:700; color:#334155;"><?php echo !empty($bc->valor) ? number_format($bc->valor, 2, ',', '.') : '—'; ?></span>
                                <?php if ($bc->status_pagamento === 'pago') : ?>
                                    <span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:800;">✓ Pago</span>
                                <?php else : ?>
                                    <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:800;">Pendente</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card resumo + filtros -->
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
            <?php if ($total_sem_boleto_mes > 0) : ?>
            <div style="display:inline-flex; align-items:center; gap:10px; padding:10px 16px;
                        background:#fffbeb; border:1px solid #fde68a; border-radius:12px;">
                <span style="font-size:18px;">⚠️</span>
                <span style="font-size:13px; color:#92400e; font-weight:700;">
                    <strong><?php echo intval($total_sem_boleto_mes); ?></strong> cliente<?php echo $total_sem_boleto_mes > 1 ? 's' : ''; ?> sem boleto em <?php echo esc_html($mes_ref); ?>
                </span>
                <?php if (!$filtro_sem_boleto) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_clientes&filtro_sem_boleto=1')); ?>"
                    style="font-size:12px; color:#0b5ed7; font-weight:700; text-decoration:none;">
                    Filtrar →
                </a>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <div style="display:inline-flex; align-items:center; gap:10px; padding:10px 16px;
                        background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px;">
                <span style="font-size:18px;">✅</span>
                <span style="font-size:13px; color:#166534; font-weight:700;">
                    Todos os clientes têm boleto em <?php echo esc_html($mes_ref); ?>
                </span>
            </div>
            <?php endif; ?>

            <?php if ( pb_usuario_pode('pb_importar_boletos') ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="pb_reprocessar_dados_clientes">
                <?php wp_nonce_field('pb_reprocessar_dados_clientes_action', 'pb_nonce'); ?>
                <button type="submit"
                    style="display:inline-flex; align-items:center; gap:6px; height:34px; padding:0 14px;
                           background:#fff; border:1px solid #e2e8f0; color:#334155;
                           border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;"
                    onclick="return confirm('Isso vai varrer todos os PDFs salvos e atualizar endereço e Nr Documento dos clientes que ainda não têm esses dados. Pode demorar alguns segundos. Continuar?');">
                    🔄 Reprocessar dados dos PDFs
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Barra de filtros -->
        <form method="get" style="background:#fff; border:1px solid #e5eaf2; border-radius:12px; padding:14px 16px;
                                   margin-bottom:14px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
                                   box-shadow:0 2px 8px rgba(15,23,42,.04);">
            <input type="hidden" name="page" value="pb_boletos_clientes">
            <div>
                <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Nome ou CPF</label>
                <input type="text" name="filtro_nome" value="<?php echo esc_attr($filtro_nome); ?>"
                    placeholder="Buscar cliente..."
                    style="height:32px; font-size:12px; min-width:200px; padding:0 10px;">
            </div>
            <div style="display:flex; align-items:flex-end; gap:6px;">
                <label style="display:flex; align-items:center; gap:6px; height:32px; font-size:12px; font-weight:600; color:#334155; cursor:pointer;">
                    <input type="checkbox" name="filtro_sem_boleto" value="1" <?php checked($filtro_sem_boleto, 1); ?>>
                    Apenas sem boleto em <?php echo esc_html($mes_ref); ?>
                </label>
            </div>
            <div style="display:flex; gap:6px; align-items:flex-end;">
                <button type="submit"
                    style="display:inline-flex; align-items:center; height:32px; padding:0 14px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                    Filtrar
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_clientes')); ?>"
                    style="display:inline-flex; align-items:center; height:32px; padding:0 12px; background:#fff; border:1px solid #e2e8f0; color:#64748b; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none;">
                    Limpar
                </a>
            </div>
        </form>

        <p style="font-size:12px; color:#64748b; margin-bottom:10px;">
            Exibindo <strong><?php echo count($clientes); ?></strong> cliente<?php echo count($clientes) !== 1 ? 's' : ''; ?>
            <?php if ($filtro_sem_boleto) echo '<span style="color:#d97706; font-weight:700;"> · Filtro ativo: sem boleto em ' . esc_html($mes_ref) . '</span>'; ?>
            <?php if ($filtro_nome) echo '<span style="color:#0b5ed7; font-weight:700;"> · Busca: &ldquo;' . esc_html($filtro_nome) . '&rdquo;</span>'; ?>
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pb_excluir_clientes">
            <?php wp_nonce_field('pb_excluir_clientes_action', 'pb_nonce'); ?>

            <?php if (pb_usuario_pode('pb_excluir_clientes')) : ?>
                <div style="margin-bottom:12px;">
                    <button type="submit"
                        style="display:inline-flex; align-items:center; height:30px; padding:0 14px; background:#fff; border:1px solid #fca5a5; color:#ef4444; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;"
                        onclick="return confirm('Tem certeza que deseja excluir os clientes selecionados? Os boletos vinculados a eles também serão excluídos.');">
                        Excluir clientes selecionados
                    </button>
                </div>
            <?php endif; ?>

            <div class="pb-table-wrap">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><input type="checkbox" onclick="document.querySelectorAll('.pb-check-cliente').forEach(cb => cb.checked = this.checked);"></th>
                        <th>Cliente</th>
                        <th>CPF</th>
                        <th>E-mail</th>
                        <th>WhatsApp</th>
                        <th>Boletos</th>
                        <th>Último boleto</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($clientes)) : ?>
                        <?php foreach ($clientes as $cliente) : ?>
                            <tr <?php echo ($ver_cliente_id === intval($cliente->id)) ? 'style="background:#eff6ff !important;"' : ''; ?>>
                                <td>
                                    <input type="checkbox" class="pb-check-cliente" name="clientes[]" value="<?php echo esc_attr($cliente->id); ?>">
                                </td>
                                <td>
                                    <span style="font-weight:600; color:#0f172a;"><?php echo esc_html($cliente->nome); ?></span>
                                    <?php if (!empty($cliente->nr_documento)) : ?>
                                        <br><span style="font-size:10px; color:#94a3b8;">Nr: <?php echo esc_html($cliente->nr_documento); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($cliente->cpf); ?></td>
                                <td><?php echo !empty($cliente->email) ? esc_html($cliente->email) : '<span style="color:#94a3b8; font-style:italic;">Sem e-mail</span>'; ?></td>
                                <td>
                                    <?php if (!empty($cliente->whatsapp)) : ?>
                                        <a href="https://wa.me/55<?php echo preg_replace('/\D/', '', $cliente->whatsapp); ?>" target="_blank"
                                            style="color:#16a34a; font-weight:600; text-decoration:none; font-size:12px;">
                                            📱 <?php echo esc_html($cliente->whatsapp); ?>
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#94a3b8; font-style:italic;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;"><?php echo intval($cliente->total_boletos); ?></td>
                                <td><?php echo !empty($cliente->ultimo_boleto) ? esc_html(date('d/m/Y', strtotime($cliente->ultimo_boleto))) : '—'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_clientes&ver=' . intval($cliente->id))); ?>"
                                        style="display:inline-flex; align-items:center; height:26px; padding:0 10px; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; border-radius:6px; font-size:11px; font-weight:700; text-decoration:none; white-space:nowrap;">
                                        Ver / Editar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="7">Nenhum cliente cadastrado ainda.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </form>
    </div>
    <?php
}

function pb_excluir_clientes() {
    if (!pb_usuario_pode('pb_excluir_clientes')) {
        wp_die('Sem permissão para excluir clientes.');
    }

    check_admin_referer('pb_excluir_clientes_action', 'pb_nonce');

    if (empty($_POST['clientes']) || !is_array($_POST['clientes'])) {
        wp_redirect(admin_url('admin.php?page=pb_boletos_clientes&clientes_excluidos=0'));
        exit;
    }

    global $wpdb;

    $tabela_clientes = $wpdb->prefix . 'pb_clientes';
    $tabela_boletos  = $wpdb->prefix . 'pb_boletos';

    $ids = array_filter(array_map('intval', $_POST['clientes']));
    $excluidos = 0;

    foreach ($ids as $cliente_id) {
        if (!$cliente_id) {
            continue;
        }

        $cliente = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $tabela_clientes WHERE id = %d", $cliente_id)
        );

        if (!$cliente) {
            continue;
        }

        $boletos = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $tabela_boletos WHERE cliente_id = %d", $cliente_id)
        );

        foreach ($boletos as $boleto) {
            if (!empty($boleto->caminho_arquivo) && file_exists($boleto->caminho_arquivo)) {
                @unlink($boleto->caminho_arquivo);
            }
        }

        $wpdb->delete(
            $tabela_boletos,
            ['cliente_id' => $cliente_id],
            ['%d']
        );

        $wpdb->delete(
            $tabela_clientes,
            ['id' => $cliente_id],
            ['%d']
        );

        $detalhes_log = "Cliente excluído:\n";
        $detalhes_log .= "- Cliente: " . $cliente->nome . "\n";
        $detalhes_log .= "- CPF: " . $cliente->cpf . "\n";
        $detalhes_log .= "- E-mail: " . (!empty($cliente->email) ? $cliente->email : 'Sem e-mail') . "\n";
        $detalhes_log .= "- Boletos excluídos junto com o cliente: " . count($boletos);
        
        pb_registrar_log('exclusao_cliente', $detalhes_log);

        $excluidos++;
    }

    wp_redirect(admin_url('admin.php?page=pb_boletos_clientes&clientes_excluidos=' . $excluidos));
    exit;
}

function pb_pagina_funcionarios() {
    if (!pb_usuario_pode('pb_ver_funcionarios')) {
        wp_die('Você não tem permissão para acessar esta página.');
    }
    $funcionario_edicao = null;

    if (isset($_GET['editar_funcionario'])) {
        $funcionario_id = intval($_GET['editar_funcionario']);
        if ($funcionario_id) {
            $funcionario_edicao = get_userdata($funcionario_id);
        }
    }

    $funcionarios = get_users([
        'role__in' => ['pb_funcionario_boletos', 'pb_gestor_boletos', 'administrator'],
        'orderby' => 'display_name',
        'order'   => 'ASC',
    ]);
    ?>
    <div class="wrap">
        <style>
            .pb-app {
                background:#f4f7fb;
                margin:0 0 0 -20px;
                padding:28px;
                min-height:0;
            }
        
            .pb-hero {
                background:linear-gradient(135deg,#071f46,#0b5ed7);
                color:#fff;
                border-radius:24px;
                padding:28px;
                display:flex;
                justify-content:space-between;
                align-items:center;
                gap:20px;
                flex-wrap:wrap;
                box-shadow:0 18px 45px rgba(11,44,97,.22);
                margin-bottom:22px;
            }
        
            .pb-page-title,
            .pb-hero-title,
            .pb-hero h1 {
                color:#fff !important;
                margin:0;
                font-size:30px;
                line-height:1.2;
                font-weight:800;
            }
        
            .pb-hero p {
                margin:8px 0 0;
                color:rgba(255,255,255,.78);
            }
        
            .pb-panel {
                background:#fff;
                border:1px solid #e2e8f0;
                border-radius:22px;
                padding:22px;
                box-shadow:0 10px 28px rgba(15,23,42,.06);
                margin-bottom:24px;
            }
        
            .pb-table-wrap {
                overflow:auto;
                border:1px solid #e2e8f0;
                border-radius:18px;
                background:#fff;
            }
        
            .pb-table-wrap table {
                border:0 !important;
                margin:0;
            }
        
            .widefat thead th {
                background:#f8fafc;
                color:#334155;
                font-weight:800;
            }
        
            .widefat tbody tr:hover {
                background:#f8fbff;
            }
        
            input[type="text"],
            input[type="email"],
            input[type="password"],
            input[type="date"],
            select,
            textarea {
                border-radius:10px !important;
                border:1px solid #cbd5e1 !important;
            }
        
            .button-primary {
                background:#0b5ed7 !important;
                border-color:#0b5ed7 !important;
                border-radius:12px !important;
                font-weight:700 !important;
            }
        
            .button-secondary,
            .button {
                border-radius:12px !important;
            }
        </style>
        <div class="pb-app">
        <div class="pb-hero">
            <div>
                <div class="pb-page-title">Funcionários</div>
                <p>Cadastre, edite e organize os acessos dos usuários do portal.</p>
            </div>
        </div>

        <?php pb_render_admin_notices(); ?>

        <?php if ($funcionario_edicao) : ?>
            <div class="pb-panel" style="max-width:560px; margin-bottom:20px;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px;">
                    <div style="width:32px; height:32px; background:#dbeafe; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px;">✎</div>
                    <h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">Editar funcionário</h2>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pb_atualizar_funcionario">
                    <input type="hidden" name="usuario_id" value="<?php echo esc_attr($funcionario_edicao->ID); ?>">
                    <?php wp_nonce_field('pb_atualizar_funcionario_action', 'pb_nonce'); ?>
                    <?php
                    $campos = [
                        ['nome', 'text',     'Nome',       $funcionario_edicao->display_name, false, ''],
                        ['email','email',    'E-mail',     $funcionario_edicao->user_email,   false, ''],
                        ['senha','password', 'Nova senha', '',                                false, 'Deixe em branco para manter a senha atual.'],
                    ];
                    foreach ($campos as [$name, $type, $label, $val, $req, $desc]) : ?>
                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;"><?php echo $label; ?></label>
                            <input type="<?php echo $type; ?>" name="<?php echo $name; ?>" value="<?php echo esc_attr($val); ?>"
                                style="width:100%; height:36px; padding:0 12px; font-size:13px;" <?php echo $req ? 'required' : ''; ?>>
                            <?php if ($desc) echo '<p style="margin:4px 0 0; font-size:11px; color:#94a3b8;">' . $desc . '</p>'; ?>
                        </div>
                    <?php endforeach; ?>
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Tipo de usuário</label>
                        <select name="tipo_usuario" style="height:36px; font-size:13px; min-width:180px;">
                            <option value="pb_funcionario_boletos">Funcionário</option>
                            <option value="pb_gestor_boletos">Gestor</option>
                            <option value="administrator">Administrador</option>
                        </select>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 16px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                            Salvar alterações
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_funcionarios')); ?>"
                            style="display:inline-flex; align-items:center; height:34px; padding:0 14px; background:#fff; border:1px solid #e2e8f0; color:#64748b; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none;">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="pb-panel" style="max-width:560px; margin-bottom:20px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px;">
                <div style="width:32px; height:32px; background:#dcfce7; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px;">+</div>
                <h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">Cadastrar novo funcionário</h2>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                <input type="hidden" name="action" value="pb_salvar_funcionario">
                <?php wp_nonce_field('pb_salvar_funcionario_action', 'pb_nonce'); ?>
                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Nome</label>
                    <input type="text" name="nome" autocomplete="off" style="width:100%; height:36px; padding:0 12px; font-size:13px;" required>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">E-mail</label>
                    <input type="email" name="email" autocomplete="new-email" style="width:100%; height:36px; padding:0 12px; font-size:13px;" required>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Senha</label>
                    <input type="password" name="senha" autocomplete="new-password" style="width:100%; height:36px; padding:0 12px; font-size:13px;" required>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px;">Tipo de usuário</label>
                    <select name="tipo_usuario" style="height:36px; font-size:13px; min-width:180px;">
                        <option value="pb_funcionario_boletos">Funcionário</option>
                        <option value="pb_gestor_boletos">Gestor</option>
                        <option value="administrator">Administrador</option>
                    </select>
                </div>
                <button type="submit"
                    style="display:inline-flex; align-items:center; height:34px; padding:0 16px; background:#0b5ed7; border:none; color:#fff; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer;">
                    Cadastrar funcionário
                </button>
            </form>
        </div>

        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
            <h2 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">Funcionários cadastrados</h2>
        </div>
        <div class="pb-table-wrap">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Usuário</th>
                    <th>Tipo</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($funcionarios as $funcionario) : ?>
                    <tr>
                        <td><?php echo esc_html($funcionario->display_name); ?></td>
                        <td><?php echo esc_html($funcionario->user_email); ?></td>
                        <td><?php echo esc_html($funcionario->user_login); ?></td>
                        <td>
                            <?php
                            if (in_array('administrator', $funcionario->roles, true)) {
                                echo 'Administrador';
                            } elseif (in_array('pb_gestor_boletos', $funcionario->roles, true)) {
                                echo 'Gestor';
                            } else {
                                echo 'Funcionário';
                            }
                            ?>
                        </td>
                        <td>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=pb_boletos_funcionarios&editar_funcionario=' . intval($funcionario->ID))); ?>">
                                Editar
                            </a>

                            <?php if (!in_array('administrator', $funcionario->roles, true)) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="pb_excluir_funcionario">
                                    <input type="hidden" name="usuario_id" value="<?php echo esc_attr($funcionario->ID); ?>">
                                    <?php wp_nonce_field('pb_excluir_funcionario_action', 'pb_nonce'); ?>

                                    <button type="submit" class="button button-secondary"
                                        onclick="return confirm('Tem certeza que deseja excluir este funcionário?');">
                                        Excluir
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
}



function pb_salvar_funcionario() {
    if (!current_user_can('manage_options')) {
        wp_die('Apenas administradores podem cadastrar funcionários.');
    }

    check_admin_referer('pb_salvar_funcionario_action', 'pb_nonce');

    $nome  = isset($_POST['nome']) ? sanitize_text_field($_POST['nome']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $senha = isset($_POST['senha']) ? $_POST['senha'] : '';

    if (empty($nome) || empty($email) || !is_email($email) || empty($senha)) {
        wp_die('Dados inválidos.');
    }

    if (email_exists($email)) {
        wp_die('Já existe um usuário com este e-mail.');
    }

    $tipo_usuario = isset($_POST['tipo_usuario']) ? sanitize_text_field($_POST['tipo_usuario']) : 'pb_funcionario_boletos';

    if (!in_array($tipo_usuario, ['pb_funcionario_boletos', 'pb_gestor_boletos', 'administrator'], true)) {
        $tipo_usuario = 'pb_funcionario_boletos';
    }
    
    if ($tipo_usuario === 'administrator' && !current_user_can('manage_options')) {
        $tipo_usuario = 'pb_funcionario_boletos';
    }
    
    $user_login = sanitize_user(remove_accents(strtolower($nome)), true);
    
    if (empty($user_login)) {
        $user_login = sanitize_user(current(explode('@', $email)), true);
    }
    
    if (username_exists($user_login)) {
        $user_login .= '_' . wp_generate_password(4, false, false);
    }

    $tipo_usuario = isset($_POST['tipo_usuario']) ? sanitize_text_field($_POST['tipo_usuario']) : 'pb_funcionario_boletos';

    // Segurança: só permite esses dois
    if (!in_array($tipo_usuario, ['pb_funcionario_boletos', 'pb_gestor_boletos', 'administrator'], true)) {
        $tipo_usuario = 'pb_funcionario_boletos';
    }
    
    // 🔒 Proteção extra (ESSA PARTE QUE VOCÊ PERGUNTOU)
    if ($tipo_usuario === 'administrator' && !current_user_can('manage_options')) {
        $tipo_usuario = 'pb_funcionario_boletos';
    }
    
    // Aqui continua normal
    $usuario_id = wp_insert_user([
        'user_login'   => $user_login,
        'user_pass'    => $senha,
        'user_email'   => $email,
        'display_name' => $nome,
        'first_name'   => $nome,
        'role'         => $tipo_usuario,
    ]);

    if (is_wp_error($usuario_id)) {
        wp_die($usuario_id->get_error_message());
    }

    pb_registrar_log(
        'cadastro_funcionario',
        "Funcionário cadastrado:\n- Nome: {$nome}\n- E-mail: {$email}"
    );

    wp_redirect(admin_url('admin.php?page=pb_boletos_funcionarios&funcionario=ok'));
    exit;
}

function pb_excluir_funcionario() {
    if (!current_user_can('manage_options')) {
        wp_die('Apenas administradores podem excluir funcionários.');
    }

    check_admin_referer('pb_excluir_funcionario_action', 'pb_nonce');

    $usuario_id = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : 0;
    $usuario = $usuario_id ? get_userdata($usuario_id) : null;

    if (!$usuario || user_can($usuario, 'manage_options')) {
        wp_die('Usuário inválido.');
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    pb_registrar_log(
        'exclusao_funcionario',
        "Funcionário excluído:\n- Nome: {$usuario->display_name}\n- E-mail: {$usuario->user_email}"
    );

    wp_delete_user($usuario_id);

    wp_redirect(admin_url('admin.php?page=pb_boletos_funcionarios&funcionario=excluido'));
    exit;
}

function pb_atualizar_funcionario() {
    if (!current_user_can('manage_options')) {
        wp_die('Apenas administradores podem editar funcionários.');
    }

    check_admin_referer('pb_atualizar_funcionario_action', 'pb_nonce');

    $usuario_id = isset($_POST['usuario_id']) ? intval($_POST['usuario_id']) : 0;
    $nome = isset($_POST['nome']) ? sanitize_text_field($_POST['nome']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $senha = isset($_POST['senha']) ? sanitize_text_field($_POST['senha']) : '';

    $usuario_antigo = $usuario_id ? get_userdata($usuario_id) : null;

    if (!$usuario_antigo || empty($nome) || empty($email) || !is_email($email)) {
        wp_redirect(admin_url('admin.php?page=pb_boletos_funcionarios&funcionario=erro'));
        exit;
    }

    $dados_update = [
        'ID'           => $usuario_id,
        'display_name' => $nome,
        'user_email'   => $email,
        'nickname'     => $nome,
    ];

    if (!empty($senha)) {
        $dados_update['user_pass'] = $senha;
    }

    $resultado = wp_update_user($dados_update);

    if (is_wp_error($resultado)) {
        wp_redirect(admin_url('admin.php?page=pb_boletos_funcionarios&funcionario=erro'));
        exit;
    }

    $detalhes_log = "Funcionário atualizado:\n";
    $detalhes_log .= "- Nome anterior: {$usuario_antigo->display_name}\n";
    $detalhes_log .= "- Novo nome: {$nome}\n";
    $detalhes_log .= "- E-mail anterior: {$usuario_antigo->user_email}\n";
    $detalhes_log .= "- Novo e-mail: {$email}\n";
    $detalhes_log .= "- Senha alterada: " . (!empty($senha) ? 'Sim' : 'Não');
    
    $tipo_usuario = isset($_POST['tipo_usuario']) ? sanitize_text_field($_POST['tipo_usuario']) : '';

    if (in_array($tipo_usuario, ['pb_funcionario_boletos', 'pb_gestor_boletos', 'administrator'], true)) {
        $usuario_obj = new WP_User($usuario_id);
        $usuario_obj->set_role($tipo_usuario);
    }

    pb_registrar_log('alteracao_funcionario', $detalhes_log);

    wp_redirect(admin_url('admin.php?page=pb_boletos_funcionarios&funcionario=atualizado'));
    exit;
}

add_action('admin_menu', 'pb_ocultar_menus_para_funcionarios', 999);

function pb_ocultar_menus_para_funcionarios() {
    if (current_user_can('manage_options')) {
        return;
    }

    if (!current_user_can('pb_ver_boletos')) {
        return;
    }

    global $menu;

    foreach ($menu as $item) {
        if (!isset($item[2])) {
            continue;
        }

        if ($item[2] !== 'pb_boletos') {
            remove_menu_page($item[2]);
        }
    }

    remove_menu_page('index.php');
    remove_menu_page('profile.php');
}

add_action('admin_init', 'pb_proteger_admin_funcionario');

function pb_proteger_admin_funcionario() {
    if (current_user_can('manage_options')) {
        return;
    }

    if (!current_user_can('pb_ver_boletos')) {
        return;
    }

    global $pagenow;

    $pagina_plugin = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

    // Permitir Elementor para quem tiver a permissão personalizada
    if (current_user_can('pb_editar_pagina_inicial')) {
        if (
            $pagenow === 'post.php' ||
            $pagenow === 'admin-ajax.php' ||
            $pagenow === 'async-upload.php' ||
            $pagenow === 'media-upload.php' ||
            $pagina_plugin === 'elementor' ||
            strpos($pagina_plugin, 'elementor') === 0
        ) {
            return;
        }
    }

    $paginas_permitidas = [
        'pb_boletos',
    ];

    if (current_user_can('pb_ver_clientes')) {
        $paginas_permitidas[] = 'pb_boletos_clientes';
    }

    if (current_user_can('pb_ver_logs')) {
        $paginas_permitidas[] = 'pb_boletos_logs';
    }

    if (current_user_can('pb_editar_mensagem_email')) {
        $paginas_permitidas[] = 'pb_boletos_mensagem_email';
    }

    if (current_user_can('pb_ver_funcionarios')) {
        $paginas_permitidas[] = 'pb_boletos_funcionarios';
    }

    if (current_user_can('pb_ver_permissoes')) {
        $paginas_permitidas[] = 'pb_boletos_permissoes';
    }

    $actions_permitidas = [
        'pb_importar_zip'               => 'pb_importar_boletos',
        'pb_excluir_boletos'            => 'pb_excluir_boletos',
        'pb_excluir_boletos_antigos'    => 'pb_excluir_boletos',
        'pb_agendar_envio_boletos'      => 'pb_enviar_boletos',
        'pb_marcar_nao_enviado'         => 'pb_enviar_boletos',
        'pb_registrar_pagamento'        => 'pb_registrar_pagamento',
        'pb_reverter_pagamento'         => 'pb_registrar_pagamento',
        'pb_exportar_csv'               => 'pb_ver_boletos',
        'pb_relatorio_pagamentos'       => 'pb_ver_boletos',
        'pb_salvar_observacao'          => 'pb_ver_boletos',
        'pb_marcar_notificacoes_lidas'  => 'pb_ver_boletos',
        'pb_excluir_logs_selecionados'  => 'pb_ver_logs',
        'pb_relatorio_logs'             => 'pb_ver_logs',
        'pb_atualizar_email_cliente'    => 'pb_editar_email_cliente',
        'pb_atualizar_cliente'          => 'pb_editar_email_cliente',
        'pb_reprocessar_dados_clientes' => 'pb_importar_boletos',
        'pb_salvar_template_email'      => 'pb_editar_mensagem_email',
        'pb_remover_anexo_extra_email'  => 'pb_editar_mensagem_email',
        'pb_excluir_clientes'           => 'pb_excluir_clientes',
        'pb_limpar_logs'                => 'pb_limpar_logs',
        'pb_salvar_funcionario'         => 'pb_ver_funcionarios',
        'pb_atualizar_funcionario'      => 'pb_ver_funcionarios',
        'pb_excluir_funcionario'        => 'pb_ver_funcionarios',
        'pb_salvar_permissoes_usuario'  => 'pb_ver_permissoes',
    ];

    if ($pagenow === 'index.php') {
        wp_redirect(admin_url('admin.php?page=pb_boletos'));
        exit;
    }

    if ($pagenow === 'admin.php') {
        if (empty($pagina_plugin) || !in_array($pagina_plugin, $paginas_permitidas, true)) {
            wp_redirect(admin_url('admin.php?page=pb_boletos'));
            exit;
        }

        return;
    }

    if ($pagenow === 'admin-post.php') {
        $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';

        if (
            empty($action) ||
            !isset($actions_permitidas[$action]) ||
            !current_user_can($actions_permitidas[$action])
        ) {
            wp_die('Você não tem permissão para executar esta ação.');
        }

        return;
    }

    wp_redirect(admin_url('admin.php?page=pb_boletos'));
    exit;
}

add_action('admin_bar_menu', 'pb_limpar_barra_admin_funcionario', 999);

function pb_limpar_barra_admin_funcionario($wp_admin_bar) {
    if (current_user_can('manage_options')) {
        return;
    }

    if (!current_user_can('pb_ver_boletos')) {
        return;
    }

    $wp_admin_bar->remove_node('wp-logo');
    $wp_admin_bar->remove_node('comments');
    $wp_admin_bar->remove_node('new-content');
    $wp_admin_bar->remove_node('updates');
    $wp_admin_bar->remove_node('site-name');
    $wp_admin_bar->remove_node('customize');
    $wp_admin_bar->remove_node('search');
}