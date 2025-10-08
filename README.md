# Woo Alipay - WooCommerce 支付宝支付网关插件

[![WordPress 版本](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce 版本](https://img.shields.io/badge/WooCommerce-10.2.2%2B-green.svg)](https://woocommerce.com/)
[![许可证](https://img.shields.io/badge/License-GPL%20v2%2B-brightgreen.svg)](https://opensource.org/licenses/GPL-2.0+)

为 WooCommerce 提供全面的支付宝支付网关集成，支持中国大陆支付方式，具备先进功能和现代 WooCommerce 兼容性。

* [总体描述](#user-content-总体描述)
	* [系统要求](#user-content-系统要求)
	* [功能特性](#user-content-功能特性)
	* [安装说明](#user-content-安装说明)
	* [功能概述](#user-content-功能概述)
	* [设置说明](#user-content-设置说明)
	* [网关设置](#user-content-网关设置)
	* [网关配置帮助](#user-content-网关配置帮助)
	* [配置流程概述](#user-content-配置流程概述)
	* [注册功能、网站URL和回调](#user-content-注册功能网站url和回调)
	* [密钥管理](#user-content-密钥管理)
* [模板文件](#user-content-模板文件)
* [常见问题](#user-content-常见问题)
* [技术支持](#user-content-技术支持)

## 总体描述

Woo Alipay 为 WooCommerce 驱动的网站添加了支付宝中国支付网关。
这个中国大陆网关允许客户在移动浏览器或电脑上进行支付。

### 系统要求

* [中国大陆支付宝商户账户](https://b.alipay.com/)
* 已启用支付产品"电脑网站支付"和"手机网站支付"

### 功能特性

此插件提供全面的支付宝支付集成，具备以下功能：

#### 支付方式
- **移动网页支付：** 通过支付宝移动应用提供无缝支付体验
- **电脑网页支付：** 在台式机/笔记本电脑上通过凭据或二维码进行身份验证
- **分期付款：** 支持支付宝花呗分期付款
- **当面付：** 零售场景下的二维码扫描支付

#### 高级功能
- **WooCommerce 区块支持：** 完全兼容 WooCommerce 结账区块
- **HPOS 兼容性：** 支持 WooCommerce 高性能订单存储
- **订单管理：** 高级订单查询、超时处理和状态同步
- **智能退款：** 手动和自动退款处理，支持失败交易恢复
- **Webhook 可靠性：** 处理 webhook 失败的重试机制
- **多货币支持：** 使用可配置汇率自动货币转换

#### 集成与兼容性
- **多语言就绪：** 兼容 WPML 和 WooCommerce 多语言
- **插件兼容性：** 适用于 Ultimate Member 和 WooCommerce 兼容的缓存插件
- **现代架构：** 基于支付宝 Easy SDK 构建，提供改进的性能和可靠性

### 安装说明

1. **下载插件**
   从 [Wenpai.org](https://wenpai.org/plugins/woo-alipay) 或 GitHub 仓库下载插件 zip 文件。

2. **通过 WordPress 管理后台安装**
   - 导航到 **插件 → 安装插件 → 上传插件**
   - 选择下载的 zip 文件并点击"立即安装"
   - 安装后激活插件

3. **配置支付宝设置**
   - 前往 **WooCommerce → 设置 → 付款 → 支付宝**
   - 启用支付网关并配置您的支付宝凭据
   - 按照下面的配置指南设置您的支付宝账户

### 功能概述

Woo Alipay 与 WooCommerce 无缝集成，为中国客户提供强大的支付解决方案。插件处理从订单创建到付款确认和退款处理的完整支付生命周期，确保为商家和客户提供顺畅的体验。

## 设置说明

插件激活后，以下设置将被添加到 WooCommerce 和 WP Weixin 中。

### 网关设置

以下设置可在 WooCommerce > 设置 > 付款 > 支付宝 中访问：

| 名称                                    | 类型     | 描述                                                                                                                                                                                                     |
| --------------------------------------- |:--------:| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 启用/禁用                          | 复选框 | 用于启用/禁用支付网关。                                                                                                                                                                     |
| 结账页面标题                     | 文本     | 在结账页面上为支付网关显示的标题。                                                                                                                                                   |
| 结账页面描述               | 文本     | 在结账页面上为支付网关显示的描述。                                                                                                                                             |
| 支付宝应用 ID                           | 文本     | 在支付宝开放平台中找到的应用 ID。                                                                                                                                                                       |
| 支付宝公钥                       | 文本区域 | 在支付宝开放平台中生成的支付宝公钥 ("支付宝公钥")。                                                                                                                                      |
| 支付宝商户应用私钥 | 文本区域 | 使用提供的支付宝工具应用程序或 `openssl` 命令行生成的私钥。<br/>此密钥是保密的，不会记录在支付宝开放平台中 - **请勿与任何人共享此值**。|
| 沙盒模式                                 | 复选框 | 如果选中，在沙盒模式下运行支付宝，使用在 [https://openhome.alipay.com/platform/appDaily.htm](https://openhome.alipay.com/platform/appDaily.htm) 中找到的设置。                                      |
| 汇率                           | 数字   | 相对于人民币的汇率（如果商店货币未设置为人民币则显示）                                                                                                                     |

## 网关配置帮助

### 配置流程概述

* 前往 [支付宝开放平台](https://openhome.alipay.com/platform/developerIndex.htm) 并登录。
* 如有必要，通过按照创建应用"创建应用"菜单下的选项创建新应用：网页&移动应用 > 支付接入。应用类型应为网页应用，网站 URL 应为 `https://[[您的网站首页地址]]`，其中 `[[您的网站首页地址]]` 是您网站的主 URL。创建应用将需要支付宝对您的网站内容进行一到两个工作日的审核。
* 应用创建后，转到应用信息页面 - 可通过 `https://openhome.alipay.com/platform/appManage.htm#/app/[[您的应用ID]]/appInfo` 直接访问（将 `[[您的应用ID]]` 替换为 Woo Alipay 将使用的应用 ID）。
* 激活支付功能并使用网关信息配置应用（参见下面的["注册功能、网站 URL 和回调"](#user-content-注册功能网站url和回调)）。
* 如有必要，生成应用公钥和私钥，在支付宝开放平台注册应用公钥，并生成支付宝公钥（参见下面的["密钥管理"](#user-content-密钥管理)）。
* 填写下面的配置字段。

### 注册功能、网站 URL 和回调

为了与支付网关通信，支付宝需要激活一些功能，了解您的网站 URL，并知道 WooCommerce 网关回调端点。

* 前往应用概览页面 - 可通过 `https://openhome.alipay.com/platform/appManage.htm#/app/[[您的应用ID]]/overview` 访问（将 `[[您的应用ID]]` 替换为 Woo Alipay 将使用的应用 ID）。
* 点击添加功能按钮"添加功能"并添加电脑网站支付和手机网站支付功能 - 这些功能需要事先激活（本指南中不描述此过程 - 参见支付产品[电脑网站支付](https://b.alipay.com/signing/productDetailV2.htm?productId=I1011000290000001000)和[手机网站支付](https://b.alipay.com/signing/productDetailV2.htm?productId=I1011000290000001001)）。
* 前往应用信息页面 - 可通过 `https://openhome.alipay.com/platform/appManage.htm#/app/[[您的应用ID]]/appInfo` 访问（将 `[[您的应用ID]]` 替换为 Woo Alipay 将使用的应用 ID）。
* 点击应用网关字段的设置链接"设置"（或修改链接"修改"）。
* 在应用网关模态框中，输入 `https://[[您的域名]]`（将 `[[您的域名]]` 替换为运行 WordPress 和 Woo Alipay 的域名或子域名），然后点击确认"确定"按钮。
* 点击授权回调地址字段的设置链接"设置"（或修改链接"修改"）。
* 在授权回调地址模态框中，在回调地址类型字段中输入 `https`，在回调地址字段中输入 `https://[[您的网站首页地址]]/wc-api/WC_Alipay/`（将 `[[您的网站首页地址]]` 替换为您网站的主 URL），取消勾选只验证域名复选框以增加安全性，然后点击确认"确定"按钮。

### 密钥管理

#### 1 - 生成密钥：

要完成支付网关配置，您需要生成应用公钥、应用私钥和支付宝公钥。

##### 使用支付宝开放平台开发助手应用程序生成应用密钥（选项 1）：

* 前往 [支付宝工具文档页面](https://docs.open.alipay.com/291/106097/) 并下载适用于您选择的操作系统（Windows 或 MacOS）的支付宝开放平台开发助手应用程序。
* 在支付宝开放平台开发助手应用程序中，在"生成密钥"部分（打开助手的默认屏幕），选择"UTF-8"和"PKCS1非Java使用"选项，然后点击"生成密钥"按钮以填充文本区域。
* 将"应用公钥"文本区域中显示的应用公钥复制到计算机上的文本文件中（下文称为 `alipay_app_public_key.txt`）。
* 将"应用私钥"文本区域中显示的应用私钥复制到计算机上的文本文件中（下文称为 `alipay_app_private_key.txt`） - **请勿与任何人共享此值**。

##### 在终端中使用 openssl 命令行生成应用密钥（选项 2）：

* 输入 `openssl` 以打开 OpenSSL 命令行工具。
* 输入 `genrsa -out alipay_app_private_key.txt 2048` 生成应用私钥文件（下文称为 `alipay_app_private_key.txt`） - **请勿与任何人共享此值**。
* 输入 `rsa -in alipay_app_private_key.txt -pubout -out alipay_app_public_key.txt` 生成应用公钥文件（下文称为 `alipay_app_public_key.txt`）。
* 输入 `exit` 退出命令行工具。
* 使用文本编辑器打开两个文件，删除所有头部、尾部、空格和回车符，使每个密钥成为单行长字符串，然后保存文件。

##### 在支付宝开放平台注册应用公钥并生成支付宝公钥（需要完成上面的选项 1 或选项 2）：

* 前往应用信息页面 - 可通过 `https://openhome.alipay.com/platform/appManage.htm#/app/[[您的应用ID]]/appInfo` 访问（将 `[[您的应用ID]]` 替换为 Woo Alipay 将使用的应用 ID）。
* 点击"接口加签方式" > "设置/查看"链接以打开配置模态框。
* 使用关联的手机号码或密码进行身份验证。
* 在签名密钥配置表单（加签管理 | 1 - 加签内容配置）中，选择公钥选项"公钥"。
* 将先前保存的文件 `alipay_app_public_key.txt` 的内容粘贴到"填写公钥字符"文本区域中。
* 点击"保存设置"按钮以注册应用公钥并生成支付宝公钥。

#### 2 - 使用应用私钥和查找支付宝公钥：

* 前往应用信息页面 - 可通过 `https://openhome.alipay.com/platform/appManage.htm#/app/[[您的应用ID]]/appInfo` 访问（将 `[[您的应用ID]]` 替换为 Woo Alipay 将使用的应用 ID）。
* 点击"接口加签方式" > "设置/查看"链接以打开配置模态框。
* 如有必要，使用关联的支付宝账户的手机号码或密码进行身份验证。
* 在签名完成配置表单（加签管理 | 2 - 加签配置完成）中，复制"支付宝公钥"下显示的支付宝公钥。
* 将支付宝公钥粘贴到下面的"支付宝公钥"字段中。
* 将先前保存的文件 `alipay_app_private_key.txt` 的内容粘贴到下面的"支付宝商户应用私钥"字段中。
* 点击"保存更改"按钮。

## 模板文件

以下模板文件使用 WordPress 提供的 `locate_template()` 函数选择，并使用 `load_template()` 函数包含。这意味着它可以在活动的 WordPress 主题中重载。开发人员可以将自定义模板文件放在主题文件夹下的以下目录中（按选择优先级顺序）：

* `plugins/woo-alipay/`
* `woo-alipay/`
* `woocommerce/woo-alipay/`
* 主题文件夹的根目录

### redirected-pay

```
redirected-pay.php
```

**描述**
用户在支付前被重定向时显示的页面模板。该模板在显示支付宝支付 UI 之前充当占位符。

**关联的样式队列键：**
`woo-alipay-main-style`

## 常见问题

### 问：此插件是否支持支付宝国际支付？
答：不支持，此插件专为支付宝中国大陆支付设计。如需国际支付，您需要使用不同的支付宝解决方案。

### 问：支持哪些 WooCommerce 版本？
答：插件支持 WooCommerce 10.2.2 及更高版本，包括与 WooCommerce 区块和高性能订单存储（HPOS）的完全兼容性。

### 问：我可以在国外使用此插件吗？
答：插件适用于拥有中国大陆支付宝商户账户的商家。根据您的支付宝账户配置，可能会适用地域限制。

### 问：如何处理支付失败或超时？
答：插件包括自动订单超时处理和 webhook 重试机制。您可以在插件的管理面板中配置超时设置。

### 问：是否支持多货币？
答：是的，插件支持多货币付款，使用可配置汇率自动转换为人民币。

### 问：可以自定义付款重定向页面吗？
答：是的，插件提供模板覆盖。您可以按照模板文件部分的说明在主题中自定义 `redirected-pay.php` 模板。

## 技术支持

- **官方网站：** [WooCN.com](https://woocn.com/)
- **插件页面：** [Wenpai.org](https://wenpai.org/plugins/woo-alipay)
- **文档：** [插件文档](https://wenpai.org/plugins/woo-alipay)
- **问题与支持：** 有关技术支持和错误报告，请通过官方网站或 GitHub 仓库联系。

## 许可证

此插件根据 GNU 通用公共许可证 v2.0 或更高版本发布。详情请参阅 [LICENSE](LICENSE) 文件。

## 贡献

欢迎贡献！请随时提交拉取请求或在 GitHub 仓库上报告问题。

---

**版本：** 3.2.0
**最后更新：** 2025年
**作者：** WooCN.com