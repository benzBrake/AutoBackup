<?php

/**
 * Typecho 自动备份插件，插件原作者zhoumiao(2012年停更的)<br>
 * 2021年 Jrotty 修复使用<br>
 * 同样是 2021年 Ryan 修改为访问接口就备份，方便添加计划任务
 *
 * @package AutoBackup
 * @author Ryan
 * @version 1.3.1
 * @link https://doufu.ru
 */
class AutoBackup_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return string
     * @throws Typecho_Db_Exception
     */
    public static function activate()
    {
        $db = Typecho_Db::get();
        if (!$db->fetchRow($db->select()->from('table.options')->where('name = ?', "AutoBackup"))) {
            $insertQuery = $db->insert('table.options')->rows(['name' => "AutoBackup", 'user' => '0', 'value' => serialize(['token' => md5(uniqid(mt_rand(), true))])]);
            $db->query($insertQuery);
        }
        Helper::addAction('backup', 'AutoBackup_Action');
        return _t('<a href="%s">点此</a>前往配置插件，插件配置后方可使用', Typecho_Common::url('options-plugin.php?config=AutoBackup', Helper::options()->adminUrl));
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Db_Exception
     */
    public static function deactivate()
    {
        $db = Typecho_Db::get();
        $deleteQuery = $db->delete('table.options')->where('name = ?', "AutoBackup");
        $db->query($deleteQuery);
        Helper::removeAction('backup');
    }


    /**
     * 获取数据表
     * @return array
     * @throws Typecho_Db_Exception
     */
    public static function listTables()
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->query("SHOW TABLES"));
        $tables = [];
        foreach ($rows as $row) {
            $tables[array_values($row)[0]] = array_values($row)[0];
        }
        return $tables;
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     * @throws Typecho_Db_Exception
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        ?>
        <style>
            pre, .promo {
                background: #FFF;
                border: 1px solid #D9D9D6;
                padding: 7px;
                border-radius: 2px;
                box-sizing: border-box;
            }

            .fix-for-tables {
                position: relative;
            }

            .fix-for-tables span {
                display: inline-block;
                white-space: nowrap;
            }

            .fix-for-tables div.p-tr {
                position: absolute;
                top: 0;
                right: 0;
            }

            .f-btn {
                display: inline;
                margin: 0 5px;
                border: 1px solid #ccc;
                text-align: center;
                padding: 3px 5px;
                cursor: pointer;
                color: #555555;
            }

            .f-btn:hover {
                background: #2095f2;
                border-color: #2095f2;
                color: #fff;
                text-decoration: none;
            }
        </style>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', () => {
                let tablesCheck = document.querySelector('.fix-for-tables'),
                    divEl = document.createElement('div');
                divEl.classList.add('p-tr');
                divEl.innerHTML = `<span class="f-btn select-all">全选</span><span class="f-btn un-select-all">全不选</span>`
                tablesCheck.appendChild(divEl);
                divEl.querySelector('.select-all').addEventListener('click', () => {
                    [].forEach.call(tablesCheck.querySelectorAll('input[type="checkbox"]'), el => {
                        el.checked = 'checked';
                    });
                });
                divEl.querySelector('.un-select-all').addEventListener('click', () => {
                    [].forEach.call(tablesCheck.querySelectorAll('input[type="checkbox"]'), el => {
                        el.checked = '';
                    });
                });
                // 加载日志
                fetch('https://api.vvhan.com/api/qqsc?key=d1d0607336b55286a021d3ce5e0ac19e')
                    .then(response => response.json())
                    .then(json => {
                        let promo = document.querySelector('.usage .promo');
                        promo.innerHTML = json.content;
                    })
                    .catch(err => console.log('Request Failed', err));
            });
        </script>
        <div class="usage">
            <h2>推广</h2>
            <div class="promo">

            </div>
            <h2>使用说明</h2>
            <div class="description">
                访问以下地址就可以把数据库备份并发送到指定邮箱，你可以添加到计划任务执行。
                <pre><?php Helper::options()->index('action/backup?token=' . unserialize(Helper::options()->AutoBackup)['token']); ?></pre>

            </div>
            <h2>免费 Cron Job 网站推荐</h2>
            <div class="cron-website">
                <a class="f-btn" href="https://uptimerobot.com/">UptimeRobot</a>
                <a class="f-btn" href="https://cron-job.org">Cron-Job.org</a>
                <a class="f-btn" href="https://callmyapp.com">Call my app</a>
            </div>

        </div>
        <?php
        $tables = new Typecho_Widget_Helper_Form_Element_Checkbox('tables', self::listTables(), null, _t('需要备份的数据表'), _t('选择你需要备份的数据表'));
        $tables->setAttribute('class', 'typecho-option fix-for-tables');
        $form->addInput($tables);

        $subject = new Typecho_Widget_Helper_Form_Element_Text('subject', null, null, _t('自定义邮件标题'), _t('格式：%s-数据库备份文件（%s将会替换为备份日期）'));
        $form->addInput($subject);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, null, _t('SMTP地址'), _t('如:smtp.163.com,smtp.gmail.com,smtp.qq.com,smtp.exmail.qq.com,smtp.sohu.com,smtp.sina.com'));
        $form->addInput($host);

        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, null, _t('SMTP端口'), _t('SMTP服务端口,一般为25;gmail和qq的465。'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('isInteger', _t('端口号必须是纯数字')));

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', NULL, null, _t('SMTP用户'), _t('SMTP服务验证用户名,一般为邮箱名如：youname@domain.com'));
        $form->addInput($user);

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', NULL, NULL, _t('SMTP密码'));
        $form->addInput($pass);

        // 服务器安全模式
        $SMTPSecure = new Typecho_Widget_Helper_Form_Element_Radio('SMTPSecure', array('' => _t('无安全加密'), 'ssl' => _t('SSL加密'), 'tls' => _t('TLS加密')), 'none', _t('SMTP加密模式'));
        $form->addInput($SMTPSecure);

        $mail = new Typecho_Widget_Helper_Form_Element_Text('mail', NULL, null, _t('接收邮箱'), _t('接收邮件用的信箱，此项必填！'));
        $form->addInput($mail->addRule('email', _t('请填写正确的邮箱！')));
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }
}
