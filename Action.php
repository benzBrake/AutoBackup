<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/WebDavClient.php';

class AutoBackup_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    private $plugin;

    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
        $this->options = Helper::options();
        $this->plugin = $this->options->plugin('AutoBackup');
    }

    public function execute()
    {
    }

    /**
     * @param string $message
     * @param int $code
     * @return void
     */
    public function throwMsg($message = '', $code = 200)
    {
        $this->response->throwJson(['status' => $code, 'msg' => $message]);
        exit;
    }

    /**
     * @param string $token
     * @return bool
     */
    public function checkToken($token)
    {
        $options = unserialize($this->options->AutoBackup);
        return isset($options['token']) && $token == $options['token'];
    }

    /**
     * 接口入口。
     */
    public function action()
    {
        if ($this->plugin->debug === 'on') {
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
        }

        $this->validateToken();

        // Missing emailEnabled means this is an installation upgraded from 1.3.x.
        $emailEnabled = $this->plugin->emailEnabled === null || $this->plugin->emailEnabled === 'on';
        $webdavEnabled = $this->plugin->webdavEnabled === 'on';
        if (!$emailEnabled && !$webdavEnabled) {
            $this->throwMsg(_t('请至少启用一个备份目标'), 500);
        }

        $filePath = null;
        $results = [];
        try {
            $current = Typecho_Date::time();
            $filePath = $this->createSql();

            if ($emailEnabled) {
                $results['邮件'] = $this->deliverEmail($filePath, $current);
            }
            if ($webdavEnabled) {
                $results['WebDAV'] = $this->deliverWebdav($filePath, $current);
            }
        } catch (\Exception $exception) {
            $this->cleanupBackupFile($filePath);
            $this->throwMsg($exception->getMessage(), 500);
        }

        $this->cleanupBackupFile($filePath);

        $success = true;
        $messages = [];
        foreach ($results as $target => $result) {
            $success = $success && $result['success'];
            $messages[] = $target . '：' . $result['message'];
        }
        $this->throwMsg(implode('；', $messages), $success ? 200 : 500);
    }

    /**
     * @return void
     */
    private function validateToken()
    {
        $params = ['token' => Typecho_Request::getInstance()->get('token')];
        $validator = new Typecho_Validate();
        $validator->addRule('token', 'required', _t('无 Token 不工作'));
        $validator->addRule('token', 'xssCheck', _t('Token 格式无效'));
        $validator->addRule('token', [$this, 'checkToken'], _t('Token 验证失败'));
        if ($error = $validator->run($params)) {
            $this->throwMsg(implode(';', $error), 403);
        }
    }

    /**
     * @param string $filePath
     * @param int $current
     * @return array
     */
    private function deliverEmail($filePath, $current)
    {
        $recipient = $this->plugin->mail;
        if ($recipient === null || $recipient === '') {
            $recipient = $this->db->fetchObject(
                $this->db->query($this->db->select()->from('table.users')->where('uid', 1))
            )->mail;
        }

        $subject = $this->plugin->subject;
        if ($subject === null || $subject === '') {
            $subject = _t('%s-数据库备份文件', date('Ymd', $current));
        } else {
            $subject = _t($subject, date('Ymd', $current));
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $smtp = [
            'site' => $this->options->title,
            'attach' => $filePath,
            'attach_name' => 'AutoBackup' . date('Ymd', $current) . '.' . $extension,
            'user' => (string) $this->plugin->user,
            'pass' => (string) $this->plugin->pass,
            'host' => (string) $this->plugin->host,
            'port' => (string) $this->plugin->port,
            'to' => $recipient,
            'subject' => $subject
        ];

        return $this->sendMail($smtp);
    }

    /**
     * @param string $filePath
     * @param int $current
     * @return array
     */
    private function deliverWebdav($filePath, $current)
    {
        try {
            $url = trim((string) $this->plugin->webdavUrl);
            $username = (string) $this->plugin->webdavUsername;
            $password = (string) $this->plugin->webdavPassword;
            $directory = trim((string) $this->plugin->webdavDirectory);

            if ($url === '' || $username === '' || $password === '' || $directory === '') {
                throw new InvalidArgumentException('WebDAV 已启用，但地址、用户名、密码或远端目录未填写完整');
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $remoteName = 'AutoBackup' . date('Ymd-His', $current) . '.' . $extension;
            $client = new AutoBackup_WebDavClient(
                $url,
                $username,
                $password,
                $this->plugin->webdavVerifyTls !== 'off'
            );
            $client->upload($filePath, $directory, $remoteName);
            return ['success' => true, 'message' => _t('上传成功')];
        } catch (\Exception $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return string
     * @throws RuntimeException
     */
    public function createSql()
    {
        $tables = $this->plugin->tables;
        if (!is_array($tables) || count($tables) === 0) {
            throw new RuntimeException(_t('你没有选择任何表'));
        }

        $sql = "-- Typecho AutoBackup\r\n-- version 1.4.0\r\n-- 生成日期: "
            . date('Y年m月d日 H:i:s')
            . "\r\n-- 使用说明：创建一个数据库，然后导入文件\r\n\r\n";

        foreach ($tables as $table) {
            $quotedTable = str_replace('`', '``', $table);
            $sql .= "\r\nDROP TABLE IF EXISTS `" . $quotedTable . "`;\r\n";
            $createSql = $this->db->fetchRow($this->db->query('SHOW CREATE TABLE `' . $quotedTable . '`'));
            $sql .= $createSql['Create Table'] . ";\r\n";
            $result = $this->db->query($this->db->select()->from($table));
            while ($row = $this->db->fetchRow($result)) {
                $keys = [];
                $values = [];
                foreach ($row as $key => $value) {
                    $keys[] = '`' . str_replace('`', '``', $key) . '`';
                    $values[] = $value === null ? 'NULL' : "'" . addslashes($value) . "'";
                }
                $sql .= 'INSERT INTO `' . $quotedTable . '` (' . implode(',', $keys) . ') VALUES ('
                    . implode(',', $values) . ");\r\n";
            }
        }

        $directory = dirname(__FILE__) . '/files';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(_t('无法创建本地备份目录'));
        }

        $baseName = md5(uniqid('', true) . mt_rand());
        $sqlPath = $directory . '/' . $baseName . '.sql';
        if (file_put_contents($sqlPath, $sql) === false) {
            throw new RuntimeException(_t('无法写入本地备份文件'));
        }
        if (!function_exists('gzopen')) {
            return $sqlPath;
        }

        $zip = new PclZip($directory . '/' . $baseName . '.zip');
        if ($zip->create($sqlPath, PCLZIP_OPT_REMOVE_PATH, $directory . '/') === 0) {
            @unlink($sqlPath);
            throw new RuntimeException(_t('压缩备份文件失败'));
        }
        @unlink($sqlPath);
        return $zip->zipname;
    }

    /**
     * @param array $smtp
     * @return array
     */
    private function sendMail(array $smtp)
    {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->Port = $smtp['port'];
            $mail->Username = $smtp['user'];
            $mail->Password = $smtp['pass'];
            $mail->SMTPAuth = $smtp['user'] !== '';
            if ($this->plugin->SMTPSecure === 'ssl' || $this->plugin->SMTPSecure === 'tls') {
                $mail->SMTPSecure = $this->plugin->SMTPSecure;
            }
            $mail->setFrom($smtp['user'], _t('备份小助手'));
            $mail->addAddress($smtp['to']);
            if ($this->plugin->debug === 'on') {
                $mail->SMTPDebug = SMTP::DEBUG_CLIENT;
            }
            $mail->Subject = $smtp['subject'];
            $mail->isHTML(true);
            $mail->Body = '<p>这是由 ' . htmlspecialchars($smtp['site'], ENT_QUOTES, 'UTF-8')
                . ' 的 AutoBackup 插件自动生成的数据库备份文件，备份文件详见附件。</p>';
            $mail->AltBody = 'AutoBackup 数据库备份文件，备份文件详见附件。';
            $mail->addAttachment($smtp['attach'], $smtp['attach_name']);
            $mail->send();
            return ['success' => true, 'message' => _t('发送成功')];
        } catch (\Exception $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param string|null $filePath
     * @return void
     */
    private function cleanupBackupFile($filePath)
    {
        if ($filePath !== null && is_file($filePath)) {
            @unlink($filePath);
        }
    }
}
