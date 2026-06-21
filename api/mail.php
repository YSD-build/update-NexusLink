<?php
/**
 * 邮件发送类
 * 支持SMTP和mail()两种方式
 */

class Mailer {
    
    /**
     * 发送邮件
     * @param string $to 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body 邮件内容（HTML）
     * @return bool 是否发送成功
     */
    public static function send($to, $subject, $body) {
        // 如果未启用邮件功能，直接返回成功（模拟发送）
        if (!MAIL_ENABLE) {
            return true;
        }
        
        // 优先使用SMTP
        if (MAIL_HOST && MAIL_USER && MAIL_PASS) {
            return self::sendSMTP($to, $subject, $body);
        }
        
        // 否则使用mail()函数
        return self::sendMail($to, $subject, $body);
    }
    
    /**
     * 使用mail()函数发送
     */
    private static function sendMail($to, $subject, $body) {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
        $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        return mail($to, $subject, $body, $headers);
    }
    
    /**
     * 使用SMTP发送邮件
     */
    private static function sendSMTP($to, $subject, $body) {
        $host = MAIL_HOST;
        $port = MAIL_PORT;
        $user = MAIL_USER;
        $pass = MAIL_PASS;
        $from = MAIL_FROM;
        $fromName = MAIL_FROM_NAME;
        $secure = MAIL_SECURE;
        
        // 构建邮件内容
        $boundary = md5(time());
        $headers = array();
        $headers[] = "Date: " . date('r');
        $headers[] = "To: <{$to}>";
        $headers[] = "From: {$fromName} <{$from}>";
        $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: base64";
        
        $data = chunk_split(base64_encode($body));
        
        // 连接SMTP服务器
        $context = stream_context_create();
        if ($secure == 'ssl') {
            $host = 'ssl://' . $host;
        }
        
        $socket = @fsockopen($host, $port, $errno, $errstr, 30);
        if (!$socket) {
            return false;
        }
        
        stream_set_timeout($socket, 30);
        
        // 读取欢迎信息
        $response = self::readResponse($socket);
        if (strpos($response, '220') === false) {
            fclose($socket);
            return false;
        }
        
        // EHLO
        $hostname = function_exists('gethostname') ? gethostname() : 'localhost';
        self::sendCommand($socket, "EHLO " . $hostname);
        $response = self::readResponse($socket);
        if (strpos($response, '250') === false) {
            fclose($socket);
            return false;
        }
        
        // STARTTLS（如果是tls加密）
        if ($secure == 'tls') {
            self::sendCommand($socket, "STARTTLS");
            $response = self::readResponse($socket);
            if (strpos($response, '220') === false) {
                fclose($socket);
                return false;
            }
            @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            
            // 重新EHLO
            self::sendCommand($socket, "EHLO " . $hostname);
            $response = self::readResponse($socket);
            if (strpos($response, '250') === false) {
                fclose($socket);
                return false;
            }
        }
        
        // 认证登录
        self::sendCommand($socket, "AUTH LOGIN");
        $response = self::readResponse($socket);
        if (strpos($response, '334') === false) {
            fclose($socket);
            return false;
        }
        
        self::sendCommand($socket, base64_encode($user));
        $response = self::readResponse($socket);
        if (strpos($response, '334') === false) {
            fclose($socket);
            return false;
        }
        
        self::sendCommand($socket, base64_encode($pass));
        $response = self::readResponse($socket);
        if (strpos($response, '235') === false) {
            fclose($socket);
            return false;
        }
        
        // 发件人
        self::sendCommand($socket, "MAIL FROM: <{$from}>");
        $response = self::readResponse($socket);
        if (strpos($response, '250') === false) {
            fclose($socket);
            return false;
        }
        
        // 收件人
        self::sendCommand($socket, "RCPT TO: <{$to}>");
        $response = self::readResponse($socket);
        if (strpos($response, '250') === false) {
            fclose($socket);
            return false;
        }
        
        // 数据
        self::sendCommand($socket, "DATA");
        $response = self::readResponse($socket);
        if (strpos($response, '354') === false) {
            fclose($socket);
            return false;
        }
        
        // 发送邮件内容
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $data . "\r\n.\r\n";
        fwrite($socket, $message);
        
        $response = self::readResponse($socket);
        if (strpos($response, '250') === false) {
            fclose($socket);
            return false;
        }
        
        // 退出
        self::sendCommand($socket, "QUIT");
        fclose($socket);
        
        return true;
    }
    
    /**
     * 发送SMTP命令
     */
    private static function sendCommand($socket, $command) {
        fwrite($socket, $command . "\r\n");
    }
    
    /**
     * 读取SMTP响应
     */
    private static function readResponse($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }
        return $response;
    }
    
    /**
     * 发送注册验证邮件
     * @param string $email 收件人邮箱
     * @param string $username 用户名
     * @param string $token 验证token
     * @return bool 是否发送成功
     */
    public static function sendVerifyEmail($email, $username, $token) {
        $subject = '【' . SITE_NAME . '】请验证您的邮箱';
        $verifyUrl = SITE_URL . '/?verify=' . $token;
        
        $body = '
        <div style="max-width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h2 style="color: white; margin: 0; font-size: 24px;">欢迎加入 ' . SITE_NAME . '</h2>
                <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0 0;">' . $username . '，您好！</p>
            </div>
            <div style="background: #ffffff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;">
                <p style="color: #374151; font-size: 16px; line-height: 1.6;">
                    感谢您注册 ' . SITE_NAME . '！请点击下方按钮验证您的邮箱地址，以激活您的账号。
                </p>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . $verifyUrl . '" style="display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: 500;">
                        验证邮箱地址
                    </a>
                </div>
                <p style="color: #6b7280; font-size: 14px; line-height: 1.6;">
                    如果按钮无法点击，请复制以下链接到浏览器中打开：<br>
                    <a href="' . $verifyUrl . '" style="color: #667eea; word-break: break-all;">' . $verifyUrl . '</a>
                </p>
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <p style="color: #9ca3af; font-size: 12px; margin: 0;">
                        此邮件由系统自动发送，请勿直接回复。<br>
                        如果您没有注册过 ' . SITE_NAME . '，请忽略此邮件。
                    </p>
                </div>
            </div>
        </div>';
        
        return self::send($email, $subject, $body);
    }
    
    /**
     * 发送重置密码邮件
     * @param string $email 收件人邮箱
     * @param string $username 用户名
     * @param string $token 重置token
     * @return bool 是否发送成功
     */
    public static function sendResetPasswordEmail($email, $username, $token) {
        $subject = '【' . SITE_NAME . '】重置您的密码';
        $resetUrl = SITE_URL . '/?reset=' . $token;
        
        $body = '
        <div style="max-width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;">
            <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h2 style="color: white; margin: 0; font-size: 24px;">重置密码</h2>
                <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0 0;">' . $username . '，您好！</p>
            </div>
            <div style="background: #ffffff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;">
                <p style="color: #374151; font-size: 16px; line-height: 1.6;">
                    我们收到了您的重置密码请求。请点击下方按钮重置您的密码，此链接有效期为24小时。
                </p>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . $resetUrl . '" style="display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: 500;">
                        重置密码
                    </a>
                </div>
                <p style="color: #6b7280; font-size: 14px; line-height: 1.6;">
                    如果按钮无法点击，请复制以下链接到浏览器中打开：<br>
                    <a href="' . $resetUrl . '" style="color: #f5576c; word-break: break-all;">' . $resetUrl . '</a>
                </p>
                <div style="background: #fef3c7; padding: 15px; border-radius: 6px; margin-top: 20px;">
                    <p style="color: #92400e; font-size: 14px; margin: 0;">
                        ⚠️ 安全提示：如果您没有发起此请求，请忽略此邮件并及时修改您的密码，确保账号安全。
                    </p>
                </div>
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <p style="color: #9ca3af; font-size: 12px; margin: 0;">
                        此邮件由系统自动发送，请勿直接回复。
                    </p>
                </div>
            </div>
        </div>';
        
        return self::send($email, $subject, $body);
    }
    
    /**
     * 发送欢迎邮件（邮箱验证成功后）
     * @param string $email 收件人邮箱
     * @param string $username 用户名
     * @return bool 是否发送成功
     */
    public static function sendWelcomeEmail($email, $username) {
        $subject = '【' . SITE_NAME . '】欢迎加入！';
        
        $body = '
        <div style="max-width: 600px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;">
            <div style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h2 style="color: white; margin: 0; font-size: 24px;">🎉 恭喜！邮箱验证成功</h2>
                <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0 0;">' . $username . '，欢迎加入我们！</p>
            </div>
            <div style="background: #ffffff; padding: 30px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 10px 10px;">
                <p style="color: #374151; font-size: 16px; line-height: 1.6;">
                    您的账号已成功激活！现在您可以开始使用 ' . SITE_NAME . ' 的所有功能了。
                </p>
                <div style="background: #f0fdf4; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="color: #166534; margin: 0 0 15px 0; font-size: 16px;">✨ 您可以：</h3>
                    <ul style="color: #15803d; margin: 0; padding-left: 20px; line-height: 2;">
                        <li>创建您的第一条隧道</li>
                        <li>选择不同的节点服务器</li>
                        <li>管理您的端口映射</li>
                        <li>查看实时流量统计</li>
                    </ul>
                </div>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . SITE_URL . '" style="display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: 500;">
                        立即开始使用
                    </a>
                </div>
                <p style="color: #6b7280; font-size: 14px; line-height: 1.6;">
                    如果您在使用过程中有任何问题，欢迎随时联系我们的客服团队。
                </p>
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <p style="color: #9ca3af; font-size: 12px; margin: 0;">
                        感谢您选择 ' . SITE_NAME . '，祝您使用愉快！
                    </p>
                </div>
            </div>
        </div>';
        
        return self::send($email, $subject, $body);
    }
}
