<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Base64 值包装器
 */
class TelegramPost_Base64
{
    public $data;
    public function __construct($data) { $this->data = $data; }
}

/**
 * Telegram Webhook 处理器
 */
class TelegramPost_Action extends Typecho_Widget
{
    private $dedupeFile  = '';
    private $logFile     = '';
    private $historyFile = '';
    private $logEnabled  = false;
    private $imageError  = '';

    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $base = __TYPECHO_ROOT_DIR__ . '/usr/plugins/TelegramPost/';
        $this->dedupeFile  = $base . 'last_update.txt';
        $this->logFile     = $base . 'debug.log';
        $this->historyFile = $base . 'history.txt';

        try {
            $config = Typecho_Widget::widget('Widget_Options')->plugin('TelegramPost');
            $this->logEnabled = isset($config->logEnabled) && $config->logEnabled == '1';
        } catch (Exception $e) {
            $this->logEnabled = false;
        }
    }

    private function log($msg)
    {
        if (!$this->logEnabled) return;
        @file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }

    private function logAlways($msg)
    {
        @file_put_contents($this->logFile, date('Y-m-d H:i:s') . ' [ERROR] ' . $msg . "\n", FILE_APPEND);
    }

    public function webhook()
    {
        $this->log('--- webhook received ---');
        $this->imageError = '';

        $config = Typecho_Widget::widget('Widget_Options')->plugin('TelegramPost');
        $secret = $config->webhookSecret ?? '';

        if (!empty($secret)) {
            $headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
            if ($headerSecret !== $secret) {
                $this->log('Secret mismatch');
                http_response_code(403);
                echo json_encode(['ok' => false]);
                exit;
            }
        }

        $rawInput = file_get_contents('php://input');
        $update = json_decode($rawInput, true);
        if (!$update || !isset($update['message'])) {
            $this->respond(['ok' => true, 'msg' => 'no message']);
        }

        $message = $update['message'];
        $chatId  = $message['chat']['id'] ?? '';
        $text    = $message['text'] ?? '';
        $this->log('update_id=' . ($update['update_id'] ?? 'n/a') . ' chat_id=' . $chatId);

        $allowedChatId = trim($config->allowedChatId ?? '');
        if (!empty($allowedChatId) && (string)$chatId !== $allowedChatId) {
            $this->log('Chat not allowed');
            $this->respond(['ok' => true, 'msg' => 'chat not allowed']);
        }

        $updateId = $update['update_id'] ?? 0;
        if ($updateId && $this->isDuplicate($updateId)) {
            $this->respond(['ok' => true, 'msg' => 'duplicate']);
        }

        // 命令处理
        if (!empty($text) && strpos(ltrim($text), '/') === 0) {
            $this->handleCommand($text, $chatId, $config);
            $this->saveLastUpdateId($updateId);
            $this->respond(['ok' => true, 'msg' => 'command handled']);
        }

        // 提取内容
        $content = '';
        if (isset($message['text'])) {
            $content = $message['text'];
        } elseif (isset($message['caption'])) {
            $content = $message['caption'];
        }

        // 处理图片
        if (isset($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']);
            $fileId = $photo['file_id'] ?? '';
            if (!empty($fileId)) {
                $this->log('Photo detected, file_id=' . $fileId);
                $imgResult = $this->uploadImageToTypecho($fileId, $config);
                if ($imgResult['ok']) {
                    $content = '<img src="' . htmlspecialchars($imgResult['url']) . '" alt="" style="max-width:100%;" />' . "\n" . $content;
                    $this->log('Image uploaded: ' . $imgResult['url']);
                } else {
                    $this->imageError = $imgResult['error'];
                    $this->logAlways('Image upload failed: ' . $imgResult['error']);
                }
            }
        }

        // 也处理 document 形式的图片（作为文件发送时）
        if (isset($message['document'])) {
            $doc = $message['document'];
            $mime = $doc['mime_type'] ?? '';
            if (strpos($mime, 'image/') === 0) {
                $fileId = $doc['file_id'] ?? '';
                if (!empty($fileId)) {
                    $this->log('Document image detected, file_id=' . $fileId);
                    $imgResult = $this->uploadImageToTypecho($fileId, $config);
                    if ($imgResult['ok']) {
                        $content = '<img src="' . htmlspecialchars($imgResult['url']) . '" alt="" style="max-width:100%;" />' . "\n" . $content;
                        $this->log('Document image uploaded: ' . $imgResult['url']);
                    } else {
                        $this->imageError = $imgResult['error'];
                        $this->logAlways('Document image upload failed: ' . $imgResult['error']);
                    }
                }
            }
        }

        if (empty($content)) {
            $this->respond(['ok' => true, 'msg' => 'no content']);
        }

        $postId = $this->publishToTypecho($content, $config);

        if ($postId > 0) {
            $this->appendHistory($postId);
            $title = $this->makeTitle($content);
            $reply = "✅ 发布成功\n"
                   . "文章ID：{$postId}\n"
                   . "分类：{$config->categoryName}\n"
                   . "标题：" . ($title !== '' ? $title : '(无)');
            if (!empty($this->imageError)) {
                $reply .= "\n\n⚠️ 图片上传失败\n" . $this->imageError;
            }
            $reply .= "\n\n撤销：/undo\n删除：/delete {$postId}";
            $this->sendTelegramMessage($chatId, $reply, $config);
        } else {
            $reply = "❌ 发布失败\n分类：{$config->categoryName}";
            if (!empty($this->imageError)) {
                $reply .= "\n图片错误：" . $this->imageError;
            }
            $this->sendTelegramMessage($chatId, $reply, $config);
        }

        $this->saveLastUpdateId($updateId);
        $this->respond(['ok' => true, 'postId' => $postId]);
    }

    /* -------------------- 命令 -------------------- */

    private function handleCommand($text, $chatId, $config)
    {
        $text = preg_replace('/@\w+/', '', trim($text));
        $parts = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $arg = isset($parts[1]) ? trim($parts[1]) : '';

        switch ($command) {
            case '/start':
            case '/help':
                $help = "🤖 TelegramPost 命令：\n\n"
                      . "/undo — 撤销最近发布\n"
                      . "/delete <ID> — 删除指定文章\n"
                      . "/last — 查看最近发布的 ID\n"
                      . "/list — 查看最近 10 条记录\n"
                      . "/help — 帮助\n\n"
                      . "直接发文字或图片即可发布。";
                $this->sendTelegramMessage($chatId, $help, $config);
                break;

            case '/last':
                $history = $this->readHistory();
                if (empty($history)) {
                    $this->sendTelegramMessage($chatId, "📭 暂无发布记录", $config);
                } else {
                    $last = end($history);
                    $this->sendTelegramMessage($chatId, "最近发布的文章 ID：{$last}", $config);
                }
                break;

            case '/list':
                $history = $this->readHistory();
                if (empty($history)) {
                    $this->sendTelegramMessage($chatId, "📭 暂无发布记录", $config);
                } else {
                    $recent = array_slice(array_reverse($history), 0, 10);
                    $lines = ["📋 最近发布的 ID："];
                    foreach ($recent as $i => $pid) {
                        $lines[] = ($i + 1) . ". {$pid}";
                    }
                    $this->sendTelegramMessage($chatId, implode("\n", $lines), $config);
                }
                break;

            case '/undo':
            case '/u':
                $history = $this->readHistory();
                if (empty($history)) {
                    $this->sendTelegramMessage($chatId, "❌ 没有可撤销的文章", $config);
                    break;
                }
                $postId = array_pop($history);
                if ($this->deletePost($postId, $config)) {
                    $this->writeHistory($history);
                    $this->sendTelegramMessage($chatId, "✅ 已撤销并删除文章 ID：{$postId}", $config);
                } else {
                    $this->sendTelegramMessage($chatId, "❌ 撤销失败，ID：{$postId}", $config);
                }
                break;

            case '/delete':
            case '/d':
                if (!ctype_digit($arg)) {
                    $this->sendTelegramMessage($chatId, "❌ 用法：/delete <文章ID>", $config);
                    break;
                }
                $postId = (int)$arg;
                if ($this->deletePost($postId, $config)) {
                    $history = $this->readHistory();
                    $history = array_values(array_filter($history, function($v) use ($postId) {
                        return $v !== $postId;
                    }));
                    $this->writeHistory($history);
                    $this->sendTelegramMessage($chatId, "✅ 已删除文章 ID：{$postId}", $config);
                } else {
                    $this->sendTelegramMessage($chatId, "❌ 删除失败，ID：{$postId}", $config);
                }
                break;

            default:
                $this->sendTelegramMessage($chatId, "❓ 未知命令：{$command}", $config);
        }
    }

    /* -------------------- 历史 -------------------- */

    private function readHistory()
    {
        if (!file_exists($this->historyFile)) return [];
        $lines = file($this->historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map('intval', $lines);
    }

    private function writeHistory($list)
    {
        @file_put_contents($this->historyFile, implode("\n", $list));
    }

    private function appendHistory($postId)
    {
        @file_put_contents($this->historyFile, $postId . "\n", FILE_APPEND);
    }

    /* -------------------- 去重 -------------------- */

    private function isDuplicate($updateId)
    {
        if (!file_exists($this->dedupeFile)) return false;
        $last = (int)@file_get_contents($this->dedupeFile);
        return $updateId <= $last;
    }

    private function saveLastUpdateId($updateId)
    {
        if ($updateId > 0) {
            @file_put_contents($this->dedupeFile, (string)$updateId);
        }
    }

    /* -------------------- 标题 -------------------- */

    private function makeTitle($content)
    {
        $text = strip_tags($content);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') return '';
        return mb_substr($text, 0, 12, 'UTF-8');
    }

    /* -------------------- 图片上传 -------------------- */

    /**
     * 上传图片，返回 ['ok'=>bool, 'url'=>string, 'error'=>string]
     */
    private function uploadImageToTypecho($fileId, $config)
    {
        $result = ['ok' => false, 'url' => '', 'error' => ''];

        $botToken = $config->botToken ?? '';
        if (empty($botToken)) {
            $result['error'] = '未配置 Bot Token';
            return $result;
        }

        // 1. getFile
        $url = "https://api.telegram.org/bot{$botToken}/getFile?file_id=" . urlencode($fileId);
        $response = $this->httpGet($url);
        $data = json_decode($response, true);
        if (empty($data['result']['file_path'])) {
            $result['error'] = 'getFile 失败: ' . mb_substr($response, 0, 150);
            return $result;
        }

        $filePath = $data['result']['file_path'];

        // 2. 下载
        $downloadUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        $imageData = $this->httpGet($downloadUrl);
        if (empty($imageData)) {
            $result['error'] = '下载图片失败';
            return $result;
        }

        // 3. 根据 file_path 判断扩展名和 MIME
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (empty($ext)) $ext = 'jpg';
        $mimeType = 'image/jpeg';
        if ($ext === 'png')  $mimeType = 'image/png';
        if ($ext === 'gif')  $mimeType = 'image/gif';
        if ($ext === 'webp') $mimeType = 'image/webp';

        $filename = 'tg_' . date('YmdHis') . '_' . substr(md5($fileId), 0, 6) . '.' . $ext;

        // 4. 上传
        $up = $this->uploadMedia($filename, $imageData, $mimeType, $config);
        if ($up['ok']) {
            $result['ok'] = true;
            $result['url'] = $up['url'];
        } else {
            $result['error'] = $up['error'];
        }

        return $result;
    }

    private function uploadMedia($filename, $data, $mimeType, $config)
    {
        $result = ['ok' => false, 'url' => '', 'error' => ''];

        $params = [
            0,
            $config->username,
            $config->password,
            [
                'name'      => $filename,
                'type'      => $mimeType,
                'bits'      => new TelegramPost_Base64($data),
                'overwrite' => false,
            ]
        ];

        $errCode = 0; $errMsg = '';
        $resp = $this->xmlrpcCall($config->xmlrpcUrl, 'metaWeblog.newMediaObject', $params, $errCode, $errMsg);
        if ($resp === null) {
            $result['error'] = 'XML-RPC 错误 ' . $errCode . ': ' . $errMsg;
            return $result;
        }

        if (is_array($resp) && !empty($resp['url'])) {
            $result['ok'] = true;
            $result['url'] = $resp['url'];
            return $result;
        }

        $result['error'] = '返回结构异常: ' . json_encode($resp, JSON_UNESCAPED_UNICODE);
        return $result;
    }

    /* -------------------- 文章发布 / 删除 -------------------- */

    private function publishToTypecho($content, $config)
    {
        $categoryName = $config->categoryName ?? '';
        if (empty($categoryName)) {
            $this->logAlways('categoryName is empty');
            return 0;
        }

        $title = $this->makeTitle($content);
        $xmlrpcUrl = $config->xmlrpcUrl ?? '';
        $this->log('XMLRPC URL: ' . $xmlrpcUrl . ' | category=' . $categoryName . ' | title=' . $title);

        $postData = [
            'title'       => $title,
            'description' => $content,
            'categories'  => [$categoryName],
        ];

        $params = [0, $config->username, $config->password, $postData, true];

        $errCode = 0; $errMsg = '';
        $result = $this->xmlrpcCall($xmlrpcUrl, 'metaWeblog.newPost', $params, $errCode, $errMsg);
        if ($result === null) {
            $this->logAlways('newPost Error: ' . $errCode . ' - ' . $errMsg);
            return 0;
        }

        $this->log('newPost success, response=' . var_export($result, true));
        return (int)$result;
    }

    private function deletePost($postId, $config)
    {
        $params = ['TelegramPost', (string)$postId, $config->username, $config->password, true];
        $errCode = 0; $errMsg = '';
        $result = $this->xmlrpcCall($config->xmlrpcUrl, 'blogger.deletePost', $params, $errCode, $errMsg);
        if ($result === null) {
            $this->logAlways('deletePost Error: ' . $errCode . ' - ' . $errMsg);
            return false;
        }
        $this->log('deletePost success');
        if (is_bool($result)) return $result;
        if (is_int($result))  return $result > 0;
        return true;
    }

    /* -------------------- Telegram 消息发送 -------------------- */

    private function sendTelegramMessage($chatId, $text, $config)
    {
        $botToken = $config->botToken ?? '';
        if (empty($botToken) || empty($chatId)) return false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/sendMessage");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['chat_id' => $chatId, 'text' => $text]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return !empty($data['ok']);
    }

    /* -------------------- XML-RPC -------------------- */

    private function xmlrpcCall($url, $method, $params, &$errorCode, &$errorMessage)
    {
        $requestXml = $this->buildRequest($method, $params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestXml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml; charset=utf-8']);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            $errorCode = $errno;
            $errorMessage = $errmsg;
            $this->log('XMLRPC curl error: ' . $errmsg);
            return null;
        }

        $this->log('XMLRPC HTTP ' . $httpCode . ' len=' . strlen($response));

        if (empty($response)) {
            $errorCode = -1;
            $errorMessage = 'Empty response (HTTP ' . $httpCode . ')';
            return null;
        }

        return $this->parseResponse($response, $errorCode, $errorMessage);
    }

    private function buildRequest($method, $params)
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<methodCall><methodName>' . htmlspecialchars($method) . '</methodName><params>';
        foreach ($params as $p) {
            $xml .= '<param>' . $this->encodeValue($p) . '</param>';
        }
        $xml .= '</params></methodCall>';
        return $xml;
    }

    private function encodeValue($value)
    {
        if ($value === null) return '<value><nil/></value>';
        if (is_bool($value)) return '<value><boolean>' . ($value ? '1' : '0') . '</boolean></value>';
        if (is_int($value))  return '<value><int>' . $value . '</int></value>';
        if (is_float($value)) return '<value><double>' . $value . '</double></value>';
        if (is_string($value)) return '<value><string>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string></value>';
        if ($value instanceof TelegramPost_Base64) return '<value><base64>' . base64_encode($value->data) . '</base64></value>';
        if (is_array($value)) {
            if (empty($value)) return '<value><array><data></data></array></value>';
            $keys = array_keys($value);
            $isList = $keys === range(0, count($value) - 1);
            if ($isList) {
                $xml = '<value><array><data>';
                foreach ($value as $v) $xml .= $this->encodeValue($v);
                $xml .= '</data></array></value>';
                return $xml;
            } else {
                $xml = '<value><struct>';
                foreach ($value as $k => $v) {
                    $xml .= '<member><name>' . htmlspecialchars($k, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</name>';
                    $xml .= $this->encodeValue($v);
                    $xml .= '</member>';
                }
                $xml .= '</struct></value>';
                return $xml;
            }
        }
        return '<value><string>' . htmlspecialchars((string)$value) . '</string></value>';
    }

    private function parseResponse($xml, &$errorCode, &$errorMessage)
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) {
            $errorCode = -2;
            $errorMessage = 'XML parse failed: ' . substr($xml, 0, 200);
            return null;
        }

        if (isset($doc->fault)) {
            $code = 0; $string = 'Unknown fault';
            if (isset($doc->fault->value->struct->member)) {
                foreach ($doc->fault->value->struct->member as $member) {
                    $name = (string)$member->name;
                    if ($name === 'faultCode')   $code = (int)$member->value->int;
                    if ($name === 'faultString') $string = (string)$member->value->string;
                }
            }
            $errorCode = $code;
            $errorMessage = $string;
            return null;
        }

        if (isset($doc->params->param->value)) {
            return $this->decodeValue($doc->params->param->value);
        }
        return null;
    }

    private function decodeValue($value)
    {
        if (isset($value->string)) return (string)$value->string;
        if (isset($value->int))    return (int)$value->int;
        if (isset($value->i4))     return (int)$value->i4;
        if (isset($value->boolean)) return ((string)$value->boolean === '1');
        if (isset($value->double)) return (float)$value->double;
        if (isset($value->base64)) return base64_decode((string)$value->base64);

        if (isset($value->struct)) {
            $result = [];
            if (isset($value->struct->member)) {
                foreach ($value->struct->member as $member) {
                    $result[(string)$member->name] = $this->decodeValue($member->value);
                }
            }
            return $result;
        }

        if (isset($value->array)) {
            $result = [];
            if (isset($value->array->data->value)) {
                foreach ($value->array->data->value as $v) {
                    $result[] = $this->decodeValue($v);
                }
            }
            return $result;
        }
        return null;
    }

    /* -------------------- 工具 -------------------- */

    private function respond($data)
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function httpGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}