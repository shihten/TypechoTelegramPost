# TypechoTelegramPost
从 Telegram 发布内容到 Typecho 指定分类

## 基础功能

- 发布内容到指定分类，并取发布内容的前 12 个字符作为标题（虽然发布说说之类的内容不需要标题）
- 发图片，图片自动上传至附件
- 可删除通过 Telegram 发布的内容

## 准备工作

1. 一个 Telegram Bot
2. 一个 Typecho 分类
3. 开启 XML-RPC（好像系统默认开启）

## 安装插件

[下载插件](https://github.com/shihten/TypechoTelegramPost/archive/refs/tags/0.0.1.zip)，把插件文件放进 Typecho 的插件目录：

```text
usr/plugins/TelegramPost/
├── Plugin.php
└── Action.php
```

进入后台 → 控制台 → 插件，找到 TelegramPost，点击启用。然后点「设置」，填入基础内容。

## 设置 Webhook

在浏览器里访问下面这个网址（替换成你自己的 Token、域名和 Secret）：

```text
https://api.telegram.org/bot{你的BOT_TOKEN}/setWebhook?url=https://{你的域名}/action/telegram-post-webhook&secret_token={你填的WEBHOOK_SECRET}
```

访问后如果返回 `{"ok":true,"result":true,"description":"Webhook was set"}`，说明设置成功。

可以再访问一次这个地址确认状态：

```text
https://api.telegram.org/bot{你的BOT_TOKEN}/getWebhookInfo
```

看到 `"pending_update_count":0` 就正常。

## 开始使用

到这里已经完成了全部设置，可以开始使用了。

直接给 Bot 发消息即可发布，发文字或图片都行。发布成功后 Bot 会回复文章 ID、分类和标题。

### 常用命令

```text
/undo              撤销最近发布的一篇
/delete <文章ID>   删除指定文章
/last              查看最近发布的文章 ID
/list              查看最近 10 条发布记录
/help              显示所有命令
```

## 注意事项

- 以 `/` 开头的消息会被当成命令。如果你想发布的内容本身以 `/` 开头，比如 `/etc/hosts`，在开头加一个空格再发就行。
- 调试日志尽可能保持关闭，避免 `debug.log` 越写越大。出问题时再打开。
- 用 `/undo` 或 `/delete` 删文章，图片文件还留在服务器上，不会一并删除。

## 环境

我使用的环境是 Typecho 1.3.0，PHP 8.2。
