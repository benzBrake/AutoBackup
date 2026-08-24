<?php

/**
 * Minimal WebDAV client for creating a directory tree and uploading backups.
 */
class AutoBackup_WebDavClient
{
    private $baseUrl;
    private $username;
    private $password;
    private $verifyTls;

    public function __construct($baseUrl, $username, $password, $verifyTls = true)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('服务器未启用 PHP cURL 扩展');
        }

        $parts = parse_url($baseUrl);
        if (!$parts || !isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('WebDAV 地址必须使用 HTTP 或 HTTPS');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('WebDAV 地址不能包含查询参数或锚点');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->verifyTls = (bool) $verifyTls;
    }

    /**
     * @param string $localFile
     * @param string $directory
     * @param string $remoteName
     * @return void
     */
    public function upload($localFile, $directory, $remoteName)
    {
        if (!is_file($localFile) || !is_readable($localFile)) {
            throw new RuntimeException('本地备份文件不存在或不可读');
        }

        $segments = $this->pathSegments($directory);
        $this->ensureDirectories($segments);

        $remoteSegments = array_merge($segments, [$remoteName]);
        $response = $this->request('PUT', $this->buildUrl($remoteSegments), $localFile);
        if (!in_array($response['status'], [200, 201, 204], true)) {
            throw new RuntimeException($this->formatHttpError('上传备份失败', $response));
        }
    }

    /**
     * @param array $segments
     * @return void
     */
    private function ensureDirectories(array $segments)
    {
        $current = [];
        foreach ($segments as $segment) {
            $current[] = $segment;
            $url = $this->buildUrl($current, true);
            $response = $this->request('MKCOL', $url);

            if (in_array($response['status'], [200, 201, 204], true)) {
                continue;
            }

            if ($response['status'] === 405) {
                $probe = $this->request('PROPFIND', $url, null, ['Depth: 0']);
                if (in_array($probe['status'], [200, 207], true)) {
                    continue;
                }
                throw new RuntimeException($this->formatHttpError('无法确认 WebDAV 目录是否存在', $probe));
            }

            throw new RuntimeException($this->formatHttpError('创建 WebDAV 目录失败', $response));
        }
    }

    /**
     * @param string $path
     * @return array
     */
    private function pathSegments($path)
    {
        $path = trim($path, " \t\n\r\0\x0B/\\");
        if ($path === '') {
            throw new InvalidArgumentException('WebDAV 远端目录不能为空');
        }

        $segments = preg_split('#[\\\\/]+#', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('WebDAV 远端目录包含无效路径段');
            }
        }
        return $segments;
    }

    /**
     * @param array $segments
     * @param bool $directory
     * @return string
     */
    private function buildUrl(array $segments, $directory = false)
    {
        $encoded = array_map('rawurlencode', $segments);
        $url = $this->baseUrl . '/' . implode('/', $encoded);
        return $directory ? $url . '/' : $url;
    }

    /**
     * @param string $method
     * @param string $url
     * @param string|null $filePath
     * @param array $headers
     * @return array
     */
    private function request($method, $url, $filePath = null, array $headers = [])
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('无法初始化 WebDAV 连接');
        }

        $file = null;
        $headers[] = 'Expect:';
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers
        ];

        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($filePath !== null) {
            $file = fopen($filePath, 'rb');
            if ($file === false) {
                curl_close($curl);
                throw new RuntimeException('无法读取本地备份文件');
            }
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $file;
            $options[CURLOPT_INFILESIZE] = filesize($filePath);
        }

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        if ($body === false) {
            $message = curl_error($curl);
            if (is_resource($file)) {
                fclose($file);
            }
            curl_close($curl);
            throw new RuntimeException('WebDAV 请求失败：' . $message);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (is_resource($file)) {
            fclose($file);
        }
        curl_close($curl);

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * @param string $prefix
     * @param array $response
     * @return string
     */
    private function formatHttpError($prefix, array $response)
    {
        $body = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags($response['body'])));
        if (strlen($body) > 240) {
            $body = substr($body, 0, 240) . '...';
        }
        return $prefix . '（HTTP ' . $response['status'] . '）' . ($body === '' ? '' : '：' . $body);
    }
}
