<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once 'vendor/autoload.php';

class AutoBackup_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    private $plugin;

    /**
     * AutoBackup_Action constructor.
     * @throws Typecho_Db_Exception
     * @throws Typecho_Plugin_Exception
     */
    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
        $this->options = Helper::options();
        $this->plugin = Helper::options()->plugin('AutoBackup');
    }

    /**
     * 这个函数不能少
     */
    public function execute()
    {

    }

    /**
     * 抛出 JSON 信息
     * @param string $message
     * @param int $code 状态码
     */
    public function throwMsg($message = '', $code = 200)
    {
        $this->response->throwJson(['status' => $code, 'msg' => $message]);
    }

    /**
     * 检查 Token
     * @param $token
     * @return bool
     */
    public function checkToken($token)
    {
        return $token == unserialize(Helper::options()->AutoBackup)['token'];
    }

    /**
     * 接口入口
     */
    public function action()
    {
        if ($this->plugin->debug == 'on') {
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
        }
        // 检查 Token
        $db = $this->db;
        $params = [
            'token' => Typecho_Request::getInstance()->get('token')
        ];
        $validator = new Typecho_Validate();
        $validator->addRule('token', 'required', _t("无 Token 不工作"));
        $validator->addRule('token', 'xssCheck', _t("请不要手贱"));
        $validator->addRule('token', array($this, 'checkToken'), _t("我要报警了"));
        if ($error = $validator->run($params)) {
            $this->throwMsg(implode(';', $error), '403');
        }
        $current = Typecho_Date::time();
        $filePath = $this->createSql();

        //将备份文件发送至设置的邮箱
        $smtp = array();
        $smtp['site'] = $this->options->title;
        $smtp['attach'] = $filePath;
        $smtp['attach_name'] = "AutoBackup" . date("Ymd", $current) . ".zip";
        if (!function_exists('gzopen')) {
            $smtp['attach_name'] = "AutoBackup" . date("Ymd", $current) . ".sql";
        }
        //获取SMTP设置
        $smtp['user'] = $this->plugin->user;
        $smtp['pass'] = $this->plugin->pass;
        $smtp['host'] = $this->plugin->host;
        $smtp['port'] = $this->plugin->port;

        if (empty($this->plugin->subject != "")) {
            $smtp['subject'] = _t('%s-数据库备份文件', date("Ymd") . '-' . $this->plugin->subject);
        } else {
            $smtp['subject'] = _t($this->plugin->subject, date("Ymd"));
        }

        $smtp['AltBody'] = "";
        $smtp['body'] = '<div><div style="position: relative;color:#555;letter-spacing: 2px;font:12px/1.5 PingFangSC-Light,Microsoft YaHei,Tahoma,Helvetica,Arial,sans-serif;max-width:600px;margin:50px auto;border-top: 1px solid #d8d8d863;border-right:1px solid rgb(224 224 224);border-left:1px solid #d8d8d863;box-shadow: rgb(203, 208, 218) 0px 2px, rgba(48, 52, 63, 0.2) 0px 3px, rgba(48, 52, 63, 0.2) 0px 7px 7px, rgb(255, 255, 255) 0px 0px 0px 1px inset;border-radius: 5px;background: 0 0 repeat-x #FFF;background-image: -webkit-repeating-linear-gradient(135deg, #6c5b92, #4882CE 20px, #FFF 20px, #FFF 35px, #00769a 35px, #00769a 55px, #FFF 55px, #FFF 70px);background-image: repeating-linear-gradient(-45deg, #6c5b92, #6c5b92 20px, #FFF 20px, #FFF 35px, #00769a 35px, #00769a 55px, #FFF 55px, #FFF 70px);background-size: 100% 10px;"><div style="padding: 0 15px 8px;"><h2 style="border-bottom:1px solid #e9e9e9;font-size:18px;font-weight:normal;padding:10px 0 10px;"><span style="color: #12ADDB"><br>❀</span>&nbsp;' . date("Y年m月d日") . '</h2><div class="content"><div style="font-size:14px;color:#777;padding:0 10px;margin-top:10px"><p style="background-color: #f5f5f5;border: 0px solid #DDD;padding: 10px 15px;margin:18px 0">这是从' . $smtp["site"] . '由Typecho AutoBackup插件自动发送的数据库备份文件，备份文件详见邮件附件！</p></div></div><div align="center" style="text-align: center; font-size: 12px; line-height: 14px; color: rgb(163, 163, 163); padding: 5px 0px;"><div style="color:#888;padding:10px;"><p style="margin:0;padding:0;letter-spacing: 1px;line-height: 2;">该邮件由您的Typecho博客<a href="' . $this->options->siteUrl . '">' . $smtp["site"] . '</a>使用的插件AutoBackup发出<br />如果你没有做相关设置，请联系邮件来源地址' . $smtp["user"] . '</p></div></div></div></div></div>';

        if ($this->plugin->mail != "") {
            $email_to = $this->plugin->mail;
        } else {
            $email_to = $db->fetchObject($db->query($db->select()->from('table.users')->where('uid', 1)))->mail;
        }

        $smtp['to'] = $email_to;
        $smtp['from'] = $email_to;

        $message = $this->SendMail($smtp);
        $filePath = str_replace("/", DIRECTORY_SEPARATOR, $filePath);
        unlink($filePath);
        if ($message['status'] != 0) {
            $this->throwMsg($message['msg'], $message['status']);
        }
        $this->throwMsg($message['msg']);
    }

    /**
     * 获取备份 SQL 语句
     * @return string
     */
    public function createSql()
    {
        $tables = $this->plugin->tables;
        if (!is_array($tables)) $this->throwMsg(_t("你没有选择任何表"), 500);
        $sql = "-- Typecho AutoBackup\r\n-- version 1.2.0\r\n-- 生成日期: " . date("Y年m月d日 H:i:s") . "\r\n-- 使用说明：创建一个数据库，然后导入文件\r\n\r\n";

        for ($i = 0; $i < count($tables); $i++) {        //循环获取数据库中数据
            $table = $tables[$i];
            $sql .= "\r\nDROP TABLE IF EXISTS " . $table . ";\r\n";
            $createSql = $this->db->fetchRow($this->db->query("SHOW CREATE TABLE `" . $table . "`"));
            $sql .= $createSql['Create Table'] . ";\r\n";
            $result = $this->db->query($this->db->select()->from($table));
            while ($row = $this->db->fetchRow($result)) {
                foreach ($row as $key => $value) {    //每次取一行数据
                    $keys[] = "`" . $key . "`";        //字段存入数组
                    $values[] = "'" . addslashes($value) . "'";        //值存入数组
                }
                $sql .= "INSERT INTO `" . $table . "` (" . implode(",", $keys) . ") VALUES (" . implode(",", $values) . ");\r\n";    //生成插入语句

                //清空字段和值数组
                unset($keys);
                unset($values);
            }
        }

        if (!is_dir(dirname(__FILE__) . "/files")) {
            mkdir(dirname(__FILE__) . "/files");
        }
        $filePath = dirname(__FILE__) . "/files/" . md5($this->plugin->pass . time()) . ".sql";

        file_put_contents($filePath, $sql);
        if (!function_exists('gzopen')) {
            return $filePath;
        }

        $zip = new PclZip(dirname(__FILE__) . "/files/" . md5($this->plugin->pass . time()) . ".zip");
        $zip->create($filePath, PCLZIP_OPT_REMOVE_PATH, dirname(__FILE__) . "/files/");
        unlink($filePath);
        return $zip->zipname;
    }

    /**
     * 发送邮件
     *
     * @access public
     * @param array $smtp 邮件信息
     * @return Array
     */
    private function SendMail($smtp)
    {
        // 获取插件配置
        try {
            $STMPHost = $smtp['host']; //SMTP服务器地址
            $SMTPPort = $smtp['port']; //端口

            $SMTPUserName = $smtp['user']; //用户名
            $SMTPPassword = $smtp['pass']; //邮箱秘钥
            $fromMail = $smtp['user']; //发件邮箱
            $fromName = '备份小助手'; //发件人名字
            $fromMailr = $smtp['from']; //收件人邮箱

            // Server settings
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->isSMTP();
            $mail->Host = $STMPHost; // SMTP 服务地址
            $mail->Username = $SMTPUserName; // SMTP 用户名
            $mail->Password = $SMTPPassword; // SMTP 密码
            if ($this->plugin->SMTPSecure == 'ssl' || $this->plugin->SMTPSecure == 'tls') {
                $mail->SMTPAuth = true; // 开启认证
                $mail->SMTPSecure = $this->plugin->SMTPSecure; // SMTP 加密类型
            }

            $mail->Port = $SMTPPort; // SMTP 端口
            $mail->setFrom($fromMail, $fromName); //发件人
            $mail->addAddress($fromMailr);

            if ($this->plugin->debug == 'on') {
                $mail->SMTPDebug = SMTP::DEBUG_CLIENT;
            }

            $mail->Subject = $smtp['subject'];
            $mail->isHTML(); // 邮件为HTML格式
            // 邮件内容
            $mail->Body = $smtp['AltBody'] . $smtp['body'];
            $mail->AddAttachment($smtp['attach'], $smtp['attach_name']);
            $mail->send();
            $message = ['status' => '200'];
            $message['msg'] = _t("发送成功");
        } catch (Exception $e) {
            $message = ['status' => '500'];
            $message['msg'] = $e->getMessage();
        }
        return $message;
    }
}
