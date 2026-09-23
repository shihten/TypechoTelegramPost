<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Telegram 发布到 Typecho 指定分类插件
 *
 * @package TelegramPost
 * @author SHI 記
 * @version 0.0.1
 * @link https://blog.stoh.cc
 */
class TelegramPost_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        Helper::addRoute('telegram_webhook', '/action/telegram-post-webhook', 'TelegramPost_Action', 'webhook');
        return _t('TelegramPost 插件已激活，请到插件设置中填写配置。');
    }

    /**
     * 停用插件
     */
    public static function deactivate()
    {
        Helper::removeRoute('telegram_webhook');
        return _t('TelegramPost 插件已停用。');
    }

    /**
     * 插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        /** Bot Token */
        $botToken = new Typecho_Widget_Helper_Form_Element_Text(
            'botToken',
            NULL,
            '',
            _t('Telegram Bot Token'),
            _t('从 @BotFather 获取的 Bot Token，例如 123456:ABC-DEF...')
        );
        $form->addInput($botToken);

        /** XML-RPC 地址 */
        $xmlrpcUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'xmlrpcUrl',
            NULL,
            '',
            _t('XML-RPC 地址'),
            _t('你的 Typecho XML-RPC 接口地址，格式为 https://你的域名/action/xmlrpc')
        );
        $form->addInput($xmlrpcUrl);

        /** Typecho 用户名 */
        $username = new Typecho_Widget_Helper_Form_Element_Text(
            'username',
            NULL,
            '',
            _t('Typecho 用户名'),
            _t('用于 XML-RPC 认证的登录用户名')
        );
        $form->addInput($username);

        /** Typecho 密码 */
        $password = new Typecho_Widget_Helper_Form_Element_Password(
            'password',
            NULL,
            '',
            _t('Typecho 密码'),
            _t('用于 XML-RPC 认证的登录密码')
        );
        $form->addInput($password);

        /** 目标分类：从数据库中拉取 */
        $categoryOptions = ['' => _t('请选择分类')];
        try {
            $db = Typecho_Db::get();
            $categories = $db->fetchAll(
                $db->select('mid', 'name', 'slug')
                   ->from('table.metas')
                   ->where('type = ?', 'category')
                   ->order('order', Typecho_Db::SORT_ASC)
            );
            foreach ($categories as $cat) {
                // 存储分类「名称」，因为 Typecho XML-RPC 用 name 匹配
                $categoryOptions[$cat['name']] = $cat['name'] . ' (' . $cat['slug'] . ')';
            }
        } catch (Exception $e) {
            // 忽略，分类可能为空
        }

        $categoryName = new Typecho_Widget_Helper_Form_Element_Select(
            'categoryName',
            $categoryOptions,
            '',
            _t('目标分类'),
            _t('Telegram 内容将发布到此分类')
        );
        $form->addInput($categoryName);

        /** 允许的 Chat ID */
        $allowedChatId = new Typecho_Widget_Helper_Form_Element_Text(
            'allowedChatId',
            NULL,
            '',
            _t('允许的 Chat ID（可选）'),
            _t('仅接受来自该 Chat ID 的消息。留空则不限制。可通过 @userinfobot 获取你的 Chat ID。')
        );
        $form->addInput($allowedChatId);

        /** Webhook Secret */
        $webhookSecret = new Typecho_Widget_Helper_Form_Element_Text(
            'webhookSecret',
            NULL,
            '',
            _t('Webhook Secret（可选）'),
            _t('用于校验 Webhook 请求来源的令牌，建议填写一段随机字符串。留空则不校验。')
        );
        $form->addInput($webhookSecret);

        /** 调试日志开关 */
        $logEnabled = new Typecho_Widget_Helper_Form_Element_Radio(
            'logEnabled',
            [
                '0' => _t('关闭'),
                '1' => _t('开启'),
            ],
            '0',
            _t('调试日志'),
            _t('开启后，插件会在 usr/plugins/TelegramPost/debug.log 中记录处理过程。排查问题时开启，平时建议关闭。')
        );
        $form->addInput($logEnabled);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 不需要个人配置
    }
}