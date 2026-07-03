# 🧾 Portal de Boletos — Plugin WordPress

Plugin WordPress customizado para **gestão e consulta de boletos por CPF**, desenvolvido sob encomenda para uma clínica de saúde (~800 clientes ativos, ~800 boletos processados por mês).

O plugin substitui um processo manual de envio de boletos por um portal onde os próprios clientes consultam, baixam e acompanham a situação dos seus boletos — com um painel administrativo completo para a equipe da clínica gerir tudo.

---

## ✨ Funcionalidades

### Área do cliente (autopagamento/consulta)
- **Autenticação por CPF + código enviado por email**, com sessão de 15 minutos e mascaramento de email/telefone na interface.
- Consulta de boletos em aberto, pagos e vencidos.
- Download protegido de boletos em PDF.

### Painel administrativo
- **Importação em massa** de boletos via upload de `.zip`, com extração automática de nome, CPF, valor, vencimento e número de documento diretamente do conteúdo do PDF (usando `smalot/pdfparser`).
- **Confirmação e reversão de pagamentos**, com trilha de auditoria (log de todas as ações, com usuário responsável).
- **Relatórios** de pagamentos e de logs, com exportação para CSV.
- **Gestão de clientes**: perfis com endereço, WhatsApp (link direto para conversa via `wa.me`) e número de documento.
- **Notas por boleto** e sistema de notificações internas para a equipe.
- **Controlo de permissões por função** (ex: cargo "Funcionário de Boletos" com acesso restrito, separado do admin do WordPress).
- Envio de boletos por email com template customizável.
- Agendamento e fila de processamento para envios em lote.

---

## 🛠️ Stack técnica

| Item | Detalhe |
|---|---|
| Plataforma | Plugin nativo para WordPress (PHP + hooks do WP) |
| Banco de dados | Tabelas próprias via `$wpdb` (`pb_clientes`, `pb_boletos`, logs, notificações) — migração automática de schema na ativação |
| Extração de PDF | [`smalot/pdfparser`](https://github.com/smalot/pdfparser), via Composer |
| Segurança | Todas as queries com input do usuário usam `$wpdb->prepare()`; sessão de cliente expira automaticamente; downloads de boletos passam por verificação de posse antes de servir o arquivo |

---

## ⚙️ Instalação / desenvolvimento local

Este repositório não inclui a pasta `vendor/` (dependências do Composer) — ela é reconstruída localmente:

```bash
composer install
```

Depois, copie a pasta do plugin para `wp-content/plugins/portal-boletos/` de uma instalação WordPress e ative-o no painel administrativo.

---

## 📌 Contexto

Projeto de cliente real, entregue e em uso em produção. Este repositório é uma versão para portfólio — sem dados de clientes, credenciais ou qualquer informação da instalação de produção.

---

## 👤 Autor

Desenvolvido por **Nicolas Kaiky** — desenvolvedor independente.

💼 [LinkedIn](https://www.linkedin.com/in/nicolas-kaiky/) · 🐙 [GitHub](https://github.com/NicolasKCost) · ✉️ [nicolaskcost@gmail.com](mailto:nicolaskcost@gmail.com)
