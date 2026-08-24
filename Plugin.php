<?php

/**
 * Typecho 数据库自动备份插件
 *
 * @package AutoBackup
 * @author Ryan
 * @version 1.4.0
 * @link https://doufu.ru
 */
class AutoBackup_Plugin implements Typecho_Plugin_Interface
{
    /**
     * @return string
     * @throws Typecho_Db_Exception
     */
    public static function activate()
    {
        $db = Typecho_Db::get();
        if (!$db->fetchRow($db->select()->from('table.options')->where('name = ?', 'AutoBackup'))) {
            $insertQuery = $db->insert('table.options')->rows([
                'name' => 'AutoBackup',
                'user' => '0',
                'value' => serialize(['token' => md5(uniqid(mt_rand(), true))])
            ]);
            $db->query($insertQuery);
        }
        Helper::addAction('backup', 'AutoBackup_Action');
        return _t('<a href="%s">点此</a>前往配置插件，插件配置后方可使用', Typecho_Common::url('options-plugin.php?config=AutoBackup', Helper::options()->adminUrl));
    }

    /**
     * @return void
     * @throws Typecho_Db_Exception
     */
    public static function deactivate()
    {
        $db = Typecho_Db::get();
        $db->query($db->delete('table.options')->where('name = ?', 'AutoBackup'));
        Helper::removeAction('backup');
    }

    /**
     * @return array
     * @throws Typecho_Db_Exception
     */
    public static function listTables()
    {
        $rows = Typecho_Db::get()->fetchAll(Typecho_Db::get()->query('SHOW TABLES'));
        $tables = [];
        foreach ($rows as $row) {
            $table = array_values($row)[0];
            $tables[$table] = $table;
        }
        return $tables;
    }

    /**
     * @param string $directory
     * @return bool
     */
    public static function validateWebdavDirectory($directory)
    {
        $segments = preg_split('#[\\\\/]+#', trim($directory, " \\t\\n\\r\\0\\x0B/\\\\"));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     * @throws Typecho_Db_Exception
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        self::renderSettingsShell();

        $tables = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'tables',
            self::listTables(),
            [],
            _t('需要备份的数据表'),
            _t('选择需要写入备份文件的数据表。')
        );
        $tables->setAttribute('class', 'typecho-option fix-for-tables autobackup-common-field');
        $form->addInput($tables);

        $emailEnabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'emailEnabled',
            ['on' => _t('启用'), 'off' => _t('关闭')],
            'on',
            _t('邮件备份')
        );
        self::addTabInput($form, $emailEnabled, 'email');

        $subject = new Typecho_Widget_Helper_Form_Element_Text(
            'subject', null, null, _t('自定义邮件标题'),
            _t('格式：%s-数据库备份文件（%s 将替换为备份日期）')
        );
        self::addTabInput($form, $subject, 'email');

        $host = new Typecho_Widget_Helper_Form_Element_Text(
            'host', null, null, _t('SMTP 地址'),
            _t('例如 smtp.163.com、smtp.qq.com 或 smtp.exmail.qq.com。')
        );
        self::addTabInput($form, $host, 'email');

        $port = new Typecho_Widget_Helper_Form_Element_Text(
            'port', null, null, _t('SMTP 端口'), _t('常见端口为 25、465 或 587。')
        );
        $port->input->setAttribute('class', 'mini');
        $port->addRule('isInteger', _t('端口号必须是纯数字'));
        self::addTabInput($form, $port, 'email');

        $user = new Typecho_Widget_Helper_Form_Element_Text(
            'user', null, null, _t('SMTP 用户'), _t('SMTP 服务验证用户名，一般为完整邮箱地址。')
        );
        self::addTabInput($form, $user, 'email');

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', null, null, _t('SMTP 密码'));
        self::addTabInput($form, $pass, 'email');

        $smtpSecure = new Typecho_Widget_Helper_Form_Element_Radio(
            'SMTPSecure',
            ['none' => _t('无安全加密'), 'ssl' => _t('SSL 加密'), 'tls' => _t('TLS 加密')],
            'none',
            _t('SMTP 加密模式')
        );
        self::addTabInput($form, $smtpSecure, 'email');

        $mail = new Typecho_Widget_Helper_Form_Element_Text(
            'mail', null, null, _t('接收邮箱'), _t('留空时发送至站点 UID 为 1 的管理员邮箱。')
        );
        $mail->addRule('email', _t('请填写正确的邮箱！'));
        self::addTabInput($form, $mail, 'email');

        $debug = new Typecho_Widget_Helper_Form_Element_Radio(
            'debug',
            ['off' => _t('关闭（默认）'), 'on' => _t('开启')],
            'off',
            _t('调试模式'),
            _t('排查备份或投递问题时开启，调试输出可能影响接口 JSON 响应。')
        );
        self::addTabInput($form, $debug, 'common');

        $webdavEnabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'webdavEnabled',
            ['on' => _t('启用'), 'off' => _t('关闭')],
            'off',
            _t('WebDAV 备份')
        );
        self::addTabInput($form, $webdavEnabled, 'webdav');

        $webdavUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'webdavUrl', null, null, _t('WebDAV 地址'),
            _t('例如 https://dav.example.com/remote.php/dav/files/user。')
        );
        $webdavUrl->addRule('url', _t('请填写正确的 WebDAV 地址。'));
        self::addTabInput($form, $webdavUrl, 'webdav', true);

        $webdavUsername = new Typecho_Widget_Helper_Form_Element_Text(
            'webdavUsername', null, null, _t('WebDAV 用户名')
        );
        self::addTabInput($form, $webdavUsername, 'webdav', true);

        $webdavPassword = new Typecho_Widget_Helper_Form_Element_Password(
            'webdavPassword', null, null, _t('WebDAV 密码')
        );
        self::addTabInput($form, $webdavPassword, 'webdav', true);

        $webdavDirectory = new Typecho_Widget_Helper_Form_Element_Text(
            'webdavDirectory', null, 'AutoBackup', _t('远端目录'),
            _t('目录不存在时自动逐级创建，不允许使用 . 或 .. 路径段。')
        );
        $webdavDirectory->addRule([__CLASS__, 'validateWebdavDirectory'], _t('WebDAV 目录不能包含 . 或 .. 路径段。'));
        self::addTabInput($form, $webdavDirectory, 'webdav', true);

        $webdavVerifyTls = new Typecho_Widget_Helper_Form_Element_Radio(
            'webdavVerifyTls',
            ['on' => _t('校验证书（推荐）'), 'off' => _t('跳过证书校验')],
            'on',
            _t('HTTPS 证书校验'),
            _t('仅在使用可信的自签名证书时关闭，关闭校验会降低连接安全性。')
        );
        self::addTabInput($form, $webdavVerifyTls, 'webdav');
    }

    /**
     * @param Typecho_Widget_Helper_Form $form
     * @param Typecho_Widget_Helper_Form_Element $input
     * @param string $tab
     * @param bool $requiredWhenEnabled
     */
    private static function addTabInput($form, $input, $tab, $requiredWhenEnabled = false)
    {
        $className = 'typecho-option autobackup-tab-field autobackup-' . $tab . '-field';
        if ($requiredWhenEnabled) {
            $className .= ' autobackup-webdav-required';
        }
        $input->setAttribute('class', $className);
        $form->addInput($input);
    }

    /**
     * 设置页外壳会在 Typecho 表单之前输出，脚本在渲染后组织 Tab 面板。
     */
    private static function renderSettingsShell()
    {
        $token = unserialize(Helper::options()->AutoBackup)['token'];
        $backupUrl = Typecho_Common::url('action/backup?token=' . $token, Helper::options()->index);
        ?>
        <style>
            .autobackup-settings-shell,
            .autobackup-form {
                --ab-bg: #f6f8fa;
                --ab-surface: #fff;
                --ab-border: #d9e1e8;
                --ab-border-strong: #b9c7d3;
                --ab-text: #1f2933;
                --ab-muted: #687684;
                --ab-accent: #1769aa;
                --ab-accent-dark: #0f4f83;
                --ab-accent-soft: #e8f2fb;
                color: var(--ab-text);
                letter-spacing: 0;
            }

            .autobackup-settings-shell,
            .autobackup-form {
                background: var(--ab-bg);
                border: 1px solid var(--ab-border);
                border-radius: 4px;
                box-sizing: border-box;
                padding: 24px;
                width: 100%;
            }

            .autobackup-settings-shell { margin: 20px 0 26px; }
            .autobackup-settings-shell h2 { color: var(--ab-text); font-size: 17px; line-height: 1.35; margin: 0 0 10px; }
            .autobackup-settings-shell p { color: var(--ab-muted); line-height: 1.7; margin: 0; }

            .autobackup-cron-links {
                display: flex;
                flex-wrap: wrap;
                gap: 9px;
                margin-top: 14px;
            }

            .autobackup-cron-links a {
                background: var(--ab-surface);
                border: 1px solid var(--ab-border);
                border-radius: 3px;
                color: var(--ab-accent-dark);
                padding: 7px 10px;
                text-decoration: none;
            }

            .autobackup-cron-links a:hover {
                background: var(--ab-accent-soft);
                border-color: #9fc4e2;
            }

            .autobackup-intro {
                display: grid;
                gap: 16px;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1.35fr);
                margin-bottom: 0;
            }

            .autobackup-notice,
            .autobackup-usage {
                background: var(--ab-surface);
                border: 1px solid var(--ab-border);
                border-radius: 4px;
                box-sizing: border-box;
                min-width: 0;
                padding: 18px;
            }

            .autobackup-backup-url {
                align-items: stretch;
                display: flex;
                gap: 8px;
                margin: 12px 0 18px;
                min-width: 0;
            }

            .autobackup-settings-shell pre {
                background: #f2f5f7;
                border: 1px solid var(--ab-border);
                border-radius: 3px;
                box-sizing: border-box;
                color: var(--ab-text);
                flex: 1;
                font-size: 12px;
                margin: 0;
                min-width: 0;
                overflow-wrap: anywhere;
                padding: 12px;
                white-space: pre-wrap;
                max-width: 100%;
            }

            .autobackup-copy-button {
                background: var(--ab-surface);
                border: 1px solid var(--ab-border-strong);
                border-radius: 3px;
                color: var(--ab-accent-dark);
                cursor: pointer;
                flex: 0 0 auto;
                font-weight: 600;
                min-width: 68px;
                padding: 8px 12px;
            }

            .autobackup-copy-button:hover { background: var(--ab-accent-soft); }
            .autobackup-copy-button:active { background: #dbeaf6; }

            .autobackup-visually-hidden {
                clip: rect(0 0 0 0);
                clip-path: inset(50%);
                height: 1px;
                overflow: hidden;
                position: absolute;
                white-space: nowrap;
                width: 1px;
            }

            .autobackup-tabs {
                display: grid;
                gap: 10px;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                list-style: none;
                margin: 4px 0 20px;
                padding: 0;
                min-width: 0;
            }

            .autobackup-tabs button {
                background: var(--ab-surface);
                border: 1px solid var(--ab-border);
                border-radius: 3px;
                color: var(--ab-muted);
                cursor: pointer;
                font-size: 14px;
                font-weight: 600;
                min-height: 44px;
                padding: 10px 14px;
                transition: background-color .16s ease, border-color .16s ease, color .16s ease;
                width: 100%;
                min-width: 0;
            }

            .autobackup-tabs button + button { border-left: 0; }
            .autobackup-tabs button:hover { background: var(--ab-accent-soft); color: var(--ab-accent-dark); }
            .autobackup-tabs button[aria-selected="true"] {
                background: var(--ab-accent);
                border-color: var(--ab-accent);
                color: #fff;
                transform: none;
            }

            .autobackup-tabs button:focus-visible,
            .autobackup-copy-button:focus-visible,
            .autobackup-form input:focus-visible {
                outline: 3px solid rgba(23, 105, 170, .24);
                outline-offset: 2px;
            }

            .autobackup-form .autobackup-tab-panel[hidden] { display: none; }
            .autobackup-form .typecho-option {
                background: var(--ab-surface);
                border: 1px solid var(--ab-border);
                border-radius: 4px;
                box-sizing: border-box;
                margin: 0 0 18px;
                padding: 0;
                min-width: 0;
                width: 100%;
            }

            .autobackup-form .typecho-option > li { padding: 15px; }
            .autobackup-form .autobackup-tab-panel { min-width: 0; }
            .autobackup-form .typecho-label { color: var(--ab-text); font-size: 14px; font-weight: 600; }
            .autobackup-form .description {
                color: var(--ab-muted);
                line-height: 1.65;
                overflow-wrap: anywhere;
            }

            .autobackup-form input[type="text"],
            .autobackup-form input[type="password"],
            .autobackup-form input:not([type]) {
                background: var(--ab-surface);
                border: 1px solid var(--ab-border-strong);
                border-radius: 3px;
                box-sizing: border-box;
                color: var(--ab-text);
                min-height: 42px;
                padding: 9px 12px;
                width: 100%;
                max-width: 100%;
            }

            .autobackup-form input[type="radio"],
            .autobackup-form input[type="checkbox"] { accent-color: var(--ab-accent); }
            .autobackup-form .fix-for-tables { position: relative; }
            .autobackup-form .fix-for-tables .multiline {
                box-sizing: border-box;
                display: inline-block;
                min-width: 50%;
                padding: 4px 8px 4px 0;
                white-space: nowrap;
            }
            .autobackup-form .fix-for-tables .multiline label,
            .autobackup-form .fix-for-tables .multiline span { white-space: nowrap; }
            .autobackup-form .fix-for-tables > li > span { white-space: nowrap; }

            .autobackup-table-actions {
                display: flex;
                gap: 9px;
                position: absolute;
                right: 16px;
                top: 12px;
            }

            .autobackup-small-button {
                background: var(--ab-surface);
                border: 1px solid var(--ab-border-strong);
                border-radius: 3px;
                color: var(--ab-accent-dark);
                cursor: pointer;
                min-height: 30px;
                padding: 5px 10px;
            }

            .autobackup-small-button:active {
                background: #edf2f6;
            }

            .autobackup-form .typecho-option-submit { background: transparent; box-shadow: none; margin: 4px 0 0; padding: 0; }
            .autobackup-form .typecho-option-submit li { padding: 0; }
            .autobackup-form .typecho-option-submit .primary {
                background: var(--ab-accent);
                border: 1px solid var(--ab-accent);
                border-radius: 3px;
                color: #fff;
                font-weight: 600;
                min-height: 42px;
                padding: 9px 24px;
            }

            .autobackup-form .typecho-option-submit .primary:hover { background: var(--ab-accent-dark); border-color: var(--ab-accent-dark); }
            .autobackup-form .typecho-option-submit .primary:active {
                background: #0b426d;
            }

            @media (max-width: 48em) {
                .autobackup-settings-shell,
                .autobackup-form { padding: 16px; }
                .autobackup-intro { grid-template-columns: 1fr; }
                .autobackup-backup-url { align-items: stretch; flex-direction: column; }
                .autobackup-copy-button { min-height: 40px; width: 100%; }
                .autobackup-form .fix-for-tables .multiline { min-width: 100%; }
                .autobackup-table-actions { margin-top: 12px; position: static; }
                .autobackup-form .typecho-option-submit .primary { width: 100%; }
            }

            @media (prefers-reduced-motion: reduce) {
                .autobackup-tabs button { transition: none; }
            }
        </style>
        <div class="autobackup-settings-shell">
            <div class="autobackup-intro">
                <section class="autobackup-notice" aria-labelledby="autobackup-notice-title">
                    <h2 id="autobackup-notice-title"><?php _e('公告'); ?></h2>
                    <p class="autobackup-promo"><?php _e('正在加载最新公告...'); ?></p>
                </section>
                <section class="autobackup-usage" aria-labelledby="autobackup-usage-title">
                    <h2 id="autobackup-usage-title"><?php _e('备份调用地址'); ?></h2>
                    <p><?php _e('访问以下地址会生成数据库备份，并投递到所有已启用的目标。'); ?></p>
                    <div class="autobackup-backup-url">
                        <pre id="autobackup-backup-url"><?php echo htmlspecialchars($backupUrl, ENT_QUOTES, 'UTF-8'); ?></pre>
                        <button type="button" class="autobackup-copy-button" aria-label="<?php _e('复制备份调用地址'); ?>"><?php _e('复制'); ?></button>
                        <span class="autobackup-copy-status autobackup-visually-hidden" aria-live="polite"></span>
                    </div>
                    <h2><?php _e('免费 Cron Job 网站推荐'); ?></h2>
                    <div class="autobackup-cron-links">
                        <a href="https://uptimerobot.com/" target="_blank" rel="noopener noreferrer">UptimeRobot</a>
                        <a href="https://cron-job.org" target="_blank" rel="noopener noreferrer">Cron-Job.org</a>
                        <a href="https://callmyapp.com" target="_blank" rel="noopener noreferrer">Call my app</a>
                    </div>
                </section>
            </div>
            <div class="autobackup-tabs" role="tablist" aria-label="<?php _e('备份目标设置'); ?>">
                <button type="button" id="autobackup-tab-email" role="tab" aria-selected="true" aria-controls="autobackup-panel-email" data-tab="email"><?php _e('发送邮件'); ?></button>
                <button type="button" id="autobackup-tab-webdav" role="tab" aria-selected="false" aria-controls="autobackup-panel-webdav" data-tab="webdav" tabindex="-1"><?php _e('WebDAV'); ?></button>
            </div>
        </div>
        <script type="text/javascript">
            (function () {
                var promo = document.querySelector('.autobackup-promo');
                if (!promo || !window.fetch) return;
                fetch('https://api.vvhan.com/api/qqsc?key=d1d0607336b55286a021d3ce5e0ac19e')
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        if (json && json.content) promo.innerHTML = json.content;
                    })
                    .catch(function () {
                        promo.textContent = 'AutoBackup 已支持邮件与 WebDAV 双目标备份。';
                    });
            }());
        </script>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                var shell = document.querySelector('.autobackup-settings-shell');
                if (!shell) return;

                var copyButton = shell.querySelector('.autobackup-copy-button');
                var backupUrl = shell.querySelector('#autobackup-backup-url');
                var copyStatus = shell.querySelector('.autobackup-copy-status');
                if (copyButton && backupUrl) {
                    var copyStatusTimer;
                    copyButton.addEventListener('click', function () {
                        var textarea = document.createElement('textarea');
                        textarea.value = backupUrl.textContent || backupUrl.innerText;
                        textarea.setAttribute('readonly', 'readonly');
                        textarea.style.position = 'fixed';
                        textarea.style.left = '-9999px';
                        textarea.style.top = '0';
                        document.body.appendChild(textarea);
                        textarea.focus();
                        textarea.select();
                        if (textarea.setSelectionRange) textarea.setSelectionRange(0, textarea.value.length);

                        var copied = false;
                        try {
                            copied = document.execCommand('copy');
                        } catch (error) {}
                        document.body.removeChild(textarea);
                        copyButton.focus();
                        copyButton.textContent = copied ? '已复制' : '复制失败';
                        if (copyStatus) copyStatus.textContent = copied ? '备份调用地址已复制' : '备份调用地址复制失败';
                        window.clearTimeout(copyStatusTimer);
                        copyStatusTimer = window.setTimeout(function () {
                            copyButton.textContent = '复制';
                            if (copyStatus) copyStatus.textContent = '';
                        }, 1600);
                    });
                }

                var form = shell.nextElementSibling;
                while (form && form.tagName !== 'FORM') form = form.nextElementSibling;
                if (!form) return;
                form.classList.add('autobackup-form');

                var submit = form.querySelector('.typecho-option-submit');
                var tabList = shell.querySelector('.autobackup-tabs');
                var commonFields = form.querySelectorAll('.autobackup-common-field');
                if (tabList && commonFields.length) {
                    var lastCommonField = commonFields[commonFields.length - 1];
                    form.insertBefore(tabList, lastCommonField.nextSibling);
                }
                var panels = {};
                ['email', 'webdav'].forEach(function (name) {
                    var panel = document.createElement('section');
                    panel.id = 'autobackup-panel-' + name;
                    panel.className = 'autobackup-tab-panel';
                    panel.setAttribute('role', 'tabpanel');
                    panel.setAttribute('aria-labelledby', 'autobackup-tab-' + name);
                    panel.tabIndex = 0;
                    form.insertBefore(panel, submit || null);
                    Array.prototype.forEach.call(form.querySelectorAll('.autobackup-' + name + '-field'), function (field) {
                        panel.appendChild(field);
                    });
                    panels[name] = panel;
                });

                var tablesCheck = form.querySelector('.fix-for-tables');
                if (tablesCheck) {
                    var actions = document.createElement('div');
                    actions.className = 'autobackup-table-actions';
                    actions.innerHTML = '<button type="button" class="autobackup-small-button select-all">全选</button><button type="button" class="autobackup-small-button unselect-all">全不选</button>';
                    tablesCheck.appendChild(actions);
                    actions.querySelector('.select-all').addEventListener('click', function () {
                        Array.prototype.forEach.call(tablesCheck.querySelectorAll('input[type="checkbox"]'), function (input) { input.checked = true; });
                    });
                    actions.querySelector('.unselect-all').addEventListener('click', function () {
                        Array.prototype.forEach.call(tablesCheck.querySelectorAll('input[type="checkbox"]'), function (input) { input.checked = false; });
                    });
                }

                var tabs = Array.prototype.slice.call(tabList.querySelectorAll('[role="tab"]'));
                function activateTab(name, focus) {
                    tabs.forEach(function (tab) {
                        var tabName = tab.getAttribute('data-tab');
                        var active = tabName === name;
                        tab.setAttribute('aria-selected', active ? 'true' : 'false');
                        tab.tabIndex = active ? 0 : -1;
                        panels[tabName].hidden = !active;
                    });
                    try { window.sessionStorage.setItem('autobackup-active-tab', name); } catch (error) {}
                    if (focus) document.getElementById('autobackup-tab-' + name).focus();
                }

                tabs.forEach(function (tab, index) {
                    tab.addEventListener('click', function () { activateTab(tab.getAttribute('data-tab'), false); });
                    tab.addEventListener('keydown', function (event) {
                        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                        event.preventDefault();
                        var next = event.key === 'ArrowRight' ? index + 1 : index - 1;
                        if (next < 0) next = tabs.length - 1;
                        if (next >= tabs.length) next = 0;
                        activateTab(tabs[next].getAttribute('data-tab'), true);
                    });
                });

                function updateWebdavRequired() {
                    var enabled = form.querySelector('input[name="webdavEnabled"]:checked');
                    var required = enabled && enabled.value === 'on';
                    Array.prototype.forEach.call(form.querySelectorAll('.autobackup-webdav-required input'), function (input) {
                        input.required = required;
                    });
                }

                Array.prototype.forEach.call(form.querySelectorAll('input[name="webdavEnabled"]'), function (input) {
                    input.addEventListener('change', updateWebdavRequired);
                });
                updateWebdavRequired();

                var activeTab = 'email';
                if (panels.webdav.querySelector('.message.error')) {
                    activeTab = 'webdav';
                } else {
                    try {
                        var savedTab = window.sessionStorage.getItem('autobackup-active-tab');
                        if (savedTab === 'email' || savedTab === 'webdav') activeTab = savedTab;
                    } catch (error) {}
                }
                activateTab(activeTab, false);
            });
        </script>
        <?php
    }

    /**
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }
}
