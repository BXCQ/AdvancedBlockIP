<?php

/**
 * 高级IP访问控制 (Advanced IP Blocker)
 * 
 * 一款功能强大的Typecho插件，提供基于IP的黑名单、白名单和智能威胁检测功能，保护您的网站免受恶意访问和攻击。
 *
 * @package    AdvancedBlockIP
 * @author     璇
 * @version    2.3.2
 * @link       https://github.com/BXCQ/AdvancedBlockIP
 * @update     2025.07.09
 *
 * 历史版本
 * Version 1.0.0 (2014-10-14)
 * Version 1.0.1 (2014-10-15)
 * Version 2.0.0 (2025-04-05) - 璇
 * Version 2.1.0 (2025-05-13) - 璇
 * Version 2.2.0 (2025-06-06) - 璇 
 * Version 2.3.0 (2025-06-23) - 璇   
 */

namespace TypechoPlugin\AdvancedBlockIP;

// 导入Typecho核心类
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Db;
use Typecho\Widget\Exception;
use Typecho\Request;
use Typecho\Widget;
use Typecho\Common;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once 'adapter.php';

/**
 * 高级IP访问控制插件类
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        try {
            // 绑定到页面渲染前的钩子
            TypechoPlugin::factory('Widget_Archive')->beforeRender = array(__CLASS__, 'checkIPAccess');
            // 添加更多钩子以确保拦截功能在所有页面生效
            TypechoPlugin::factory('Widget_Archive')->header = array(__CLASS__, 'checkIPAccess');
            TypechoPlugin::factory('Widget_Archive')->footer = array(__CLASS__, 'checkIPAccess');
            TypechoPlugin::factory('Widget_Archive')->handle = array(__CLASS__, 'checkIPAccess');
            TypechoPlugin::factory('index.php')->begin = array(__CLASS__, 'checkIPAccess');
            TypechoPlugin::factory('admin/common.php')->begin = array(__CLASS__, 'checkIPAccess');

            // 使用全局命名空间的Helper类
            \Helper::addPanel(1, 'AdvancedBlockIP/console.php', 'IP防护控制台', 'IP防护控制台', 'administrator');

            // 兼容导航菜单钩子
            TypechoPlugin::factory('admin/menu.php')->navBar = array(__CLASS__, 'navBar');

            // 创建插件数据表（包括表结构更新）
            self::createTables();

            return "高级IP访问控制插件启用成功！";
        } catch (\Exception $e) {
            return "插件激活失败: " . $e->getMessage() . " (文件: " . $e->getFile() . ", 行: " . $e->getLine() . ")";
        }
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        // 使用全局命名空间的Helper类
        \Helper::removePanel(1, 'AdvancedBlockIP/console.php');

        return "高级IP访问控制插件已禁用。";
    }

    /**
     * 添加导航条
     * 
     * @param array $items 导航项目
     * @return array $items 返回修改后的导航项目
     */
    public static function navBar($items)
    {
        // 最简单的方式添加菜单项
        $items['IP防护控制台'] = 'extending.php?panel=AdvancedBlockIP/console.php';
        return $items;
    }

    /**
     * 插件配置面板
     */
    public static function config(Form $form)
    {
        try {
            // 获取已保存的插件配置
            $options = Widget::widget('Widget_Options');
            $config = $options->plugin('AdvancedBlockIP');
        } catch (\Exception $e) {
            // 插件首次激活或无配置时，使用空对象
            $config = new \stdClass();
        }

        // 功能模式选择
        $mode = new Select(
            'mode',
            array(
                'blacklist' => '黑名单模式（拦截指定IP）',
                'whitelist' => '白名单模式（仅允许指定IP）',
                'smart' => '智能模式（自动识别威胁）'
            ),
            isset($config->mode) ? $config->mode : 'smart',
            '工作模式',
            '选择插件的工作模式，推荐使用智能模式（黑白名单同时生效）'
        );
        $form->addInput($mode);

        // 黑名单处理模式
        $blacklistMode = new Select(
            'blacklistMode',
            array(
                'block' => '完全禁止访问',
                'limit' => '限制访问频率'
            ),
            isset($config->blacklistMode) ? $config->blacklistMode : 'block',
            '黑名单处理模式',
            '选择对黑名单IP的处理方式'
        );
        $form->addInput($blacklistMode);

        // IP黑名单配置
        $blacklistValue = isset($config->blacklist) ? $config->blacklist : '';
        $blacklist = new Textarea(
            'blacklist',
            null,
            $blacklistValue,
            'IP黑名单',
            '每行一个IP地址或IP段，支持以下格式：<br/>
            • 单个IP：192.168.1.100<br/>
            • IP范围：192.168.1.1-50<br/>
            • 单个通配符：192.168.1.*<br/>
            • 多个通配符：192.168.*.*、113.215.*.*<br/>
            • 三个通配符：10.*.*.*、172.*.*.*<br/>
            • CIDR：192.168.1.0/24<br/>'
        );
        $form->addInput($blacklist);

        // IP白名单配置
        $whitelistValue = isset($config->whitelist) ? $config->whitelist : '';
        $whitelist = new Textarea(
            'whitelist',
            null,
            $whitelistValue,
            'IP白名单',
            '管理员和可信任的IP地址列表，格式同黑名单，支持通配符如192.168.*.*'
        );
        $form->addInput($whitelist);

        // 访问间隔限制
        $accessIntervalValue = isset($config->accessInterval) ? $config->accessInterval : '10';
        $accessInterval = new Text(
            'accessInterval',
            null,
            $accessIntervalValue,
            '访问间隔限制（秒）',
            '单个IP两次访问之间的最小间隔时间，0为不限制'
        );
        $form->addInput($accessInterval);

        // 自定义拦截页面
        $customMessageValue = isset($config->customMessage) ? $config->customMessage : '抱歉，您的访问被系统安全策略拦截。如需帮助，请联系网站管理员。';
        $customMessage = new Textarea(
            'customMessage',
            null,
            $customMessageValue,
            '自定义拦截提示',
            '自定义显示给被拦截用户的信息，支持HTML'
        );
        $form->addInput($customMessage);

        // 调试模式
        $debugMode = new Select(
            'debugMode',
            array(
                '0' => '关闭',
                '1' => '开启'
            ),
            isset($config->debugMode) ? $config->debugMode : '0',
            '调试模式',
            '开启后会记录详细的运行日志到服务器error_log，仅在排查问题时开启'
        );
        $form->addInput($debugMode);
    }

    /**
     * 个人配置（留空）
     */
    public static function personalConfig(Form $form)
    {
        // 个人配置暂不需要
    }

    /**
     * 主要的IP检查函数
     */
    public static function checkIPAccess()
    {
        try {
            // 使用静态变量防止重复执行
            static $checked = false;
            if ($checked) {
                return;
            }
            $checked = true;

            $request = new Request();
            $clientIP = self::getRealClientIP($request);
            
            try {
                $options = Widget::widget('Widget_Options');
                $config = $options->plugin('AdvancedBlockIP');
            } catch (\Exception $e) {
                // 当插件配置不存在时，提供一个空的默认配置
                $config = new \stdClass();
            }

            // 获取工作模式，默认为智能模式
            $mode = isset($config->mode) ? $config->mode : 'smart';

            // 判断是否开启调试模式
            $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;

            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 检查IP访问 - IP: {$clientIP}, 模式: {$mode}");
            }

            // 白名单检查始终执行（最高优先级）
            if (self::isWhitelisted($clientIP, $config)) {
                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: 白名单匹配成功，放行IP: {$clientIP}");
                }
                self::recordLastAccess($clientIP, $request->getRequestUrl());
                return;
            }

            // 黑名单检查始终执行（第二优先级）
            if (self::isBlacklisted($clientIP, $config)) {
                $blacklistMode = isset($config->blacklistMode) ? $config->blacklistMode : 'block';

                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: 黑名单匹配成功，IP: {$clientIP}, 处理模式: {$blacklistMode}");
                }

                if ($blacklistMode === 'block') {
                    // 完全禁止访问
                    self::blockAccess($clientIP, 'blacklisted', $config);
                    return;
                } else {
                    // 限制访问频率模式
                    if (self::isAccessTooFrequent($clientIP, $config, true)) {
                        if ($debugMode) {
                            error_log("AdvancedBlockIP Debug: 黑名单IP访问过于频繁，拦截IP: {$clientIP}");
                        }
                        self::blockAccess($clientIP, 'blacklist_rate_limit', $config);
                        return;
                    }
                    self::recordLastAccess($clientIP, $request->getRequestUrl());
                    return;
                }
            }

            // 智能模式处理
            if ($mode === 'smart') {
                // 智能检测（包含频率异常检测，会自动拉黑）
                $smartBlockReasonsArray = self::isSmartBlocked($clientIP, $config);
                if (!empty($smartBlockReasonsArray)) {
                    $combinedSmartReasonComment = implode(', ', $smartBlockReasonsArray);
                    $reasonForDisplayAndLog = '智能检测：' . $combinedSmartReasonComment;

                    if ($debugMode) {
                        error_log("AdvancedBlockIP Debug: 智能拦截触发，IP: {$clientIP}, 原因: {$combinedSmartReasonComment}");
                    }

                    // 智能检测到异常，自动加入黑名单并拦截
                    self::addToBlacklist($clientIP, $config, $combinedSmartReasonComment);
                    self::blockAccess($clientIP, $reasonForDisplayAndLog, $config);
                    return;
                }

                // 智能模式下，非黑名单IP通过智能检测后直接放行
                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: 智能检测通过，放行IP: {$clientIP}");
                }
                self::recordLastAccess($clientIP, $request->getRequestUrl());
                return;
            }

            // 其他模式处理
            switch ($mode) {
                case 'blacklist':
                    if ($debugMode) {
                        error_log("AdvancedBlockIP Debug: 黑名单模式，非黑名单IP放行: {$clientIP}");
                    }
                    self::recordLastAccess($clientIP, $request->getRequestUrl());
                    return;

                case 'whitelist':
                    if ($debugMode) {
                        error_log("AdvancedBlockIP Debug: 白名单模式，非白名单IP拦截: {$clientIP}");
                    }
                    self::blockAccess($clientIP, 'not_whitelisted', $config);
                    return;
            }

            // 默认记录访问并放行
            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 默认放行IP: {$clientIP}");
            }
            self::recordLastAccess($clientIP, $request->getRequestUrl());
        } catch (\Exception $e) {
            error_log("AdvancedBlockIP Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        }
    }

    /**
     * 获取真实客户端IP
     */
    private static function getRealClientIP($request)
    {
        $ipHeaders = array(
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $request->getIp();
    }

    /**
     * 检查IP是否在黑名单中
     */
    private static function isBlacklisted($ip, $config)
    {
        $blacklistConfig = '';
        if (isset($config->blacklist) && !empty($config->blacklist)) {
            $blacklistConfig = $config->blacklist;
        } elseif (isset($config->ips) && !empty($config->ips)) {
            $blacklistConfig = $config->ips;
        }

        if (empty($blacklistConfig)) {
            return false;
        }

        return self::matchIPRules($ip, $blacklistConfig, $config);
    }

    /**
     * 检查IP是否在白名单中
     */
    private static function isWhitelisted($ip, $config)
    {
        if (!isset($config->whitelist) || empty($config->whitelist)) {
            return false;
        }

        return self::matchIPRules($ip, $config->whitelist, $config);
    }

    /**
     * 智能检测威胁IP
     */
    private static function isSmartBlocked($ip, $config)
    {
        $reasons = [];

        // 判断是否开启调试模式
        $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;

        if ($debugMode) {
            error_log("AdvancedBlockIP Debug: 开始智能检测 IP: {$ip}");
        }

        // 频率异常检测
        $frequencyAnomaly = self::checkFrequencyAnomaly($ip, $config);
        if ($frequencyAnomaly) {
            $reasons[] = '频率异常';
            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 检测到频率异常 IP: {$ip}");
            }
        }

        // UA异常检测
        $uaAnomaly = self::checkUserAgentAnomaly();
        if ($uaAnomaly) {
            $reasons[] = 'UA异常';
            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 检测到UA异常 IP: {$ip}, UA: " . $_SERVER['HTTP_USER_AGENT']);
            }
        }

        // 来源异常检测
        $refererAnomaly = self::checkRefererAnomaly();
        if ($refererAnomaly) {
            $reasons[] = '来源异常';
            if ($debugMode) {
                $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '无';
                error_log("AdvancedBlockIP Debug: 检测到来源异常 IP: {$ip}, Referer: {$referer}");
            }
        }

        return $reasons;
    }

    /**
     * 检测访问频率异常
     */
    private static function checkFrequencyAnomaly($ip, $config)
    {
        try {
            // 判断是否开启调试模式
            $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;

            $db = Db::get();
            $prefix = $db->getPrefix();
            $currentTime = time();

            // 检查1秒内访问超过2个不同URL的情况
            $recentAccessCountDifferentURLs = $db->fetchObject(
                $db->select('COUNT(DISTINCT url) as count')
                    ->from($prefix . 'blockip_access_log')
                    ->where('ip = ? AND timestamp > ?', $ip, $currentTime - 1)
            );

            if ($recentAccessCountDifferentURLs && $recentAccessCountDifferentURLs->count >= 2) {
                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: 频率异常 - 1秒内访问{$recentAccessCountDifferentURLs->count}个不同URL, IP: {$ip}");
                }
                return true;
            }

            // 检查5秒内访问超过3次的情况
            $recentAccessCount5s = $db->fetchObject(
                $db->select('COUNT(*) as count')
                    ->from($prefix . 'blockip_access_log')
                    ->where('ip = ? AND timestamp > ?', $ip, $currentTime - 5)
            );

            if ($recentAccessCount5s && $recentAccessCount5s->count >= 3) {
                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: 频率异常 - 5秒内访问{$recentAccessCount5s->count}次, IP: {$ip}");
                }
                return true;
            }

            // 检查10秒内访问超过6次的情况
            $recentAccessCount10s = $db->fetchObject(
                $db->select('COUNT(*) as count')
                    ->from($prefix . 'blockip_access_log')
                    ->where('ip = ? AND timestamp > ?', $ip, $currentTime - 10)
            );

            if ($recentAccessCount10s && $recentAccessCount10s->count >= 6) {
                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: 频率异常 - 10秒内访问{$recentAccessCount10s->count}次, IP: {$ip}");
                }
                return true;
            }

            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 频率检测正常, IP: {$ip}");
            }
            return false;
        } catch (\Exception $e) {
            error_log("AdvancedBlockIP Error in checkFrequencyAnomaly: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查User-Agent是否异常
     */
    private static function checkUserAgentAnomaly()
    {
        $request = new Request();
        $userAgent = $request->getAgent();

        if (empty($userAgent)) {
            return 'UA为空';
        }

        // 常见的恶意或扫描工具的User-Agent片段
        $maliciousUAs = [
            'sqlmap',
            'nmap',
            'nikto',
            'wpscan',
            'acunetix',
            'netsparker',
            'havij',
            'python-requests',
            'go-http-client',
            'curl/',
            'wget/',
            'libwww-perl',
            'apachebench',
            'httrack',
            'indy library'
        ];

        // 排除已知的正常搜索引擎爬虫
        $knownGoodBots = [
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou web spider',
            'applebot'
        ];

        foreach ($knownGoodBots as $goodBot) {
            if (stripos($userAgent, $goodBot) !== false) {
                return false;
            }
        }

        foreach ($maliciousUAs as $uaPattern) {
            if (stripos($userAgent, $uaPattern) !== false) {
                return 'UA异常';
            }
        }

        return false;
    }

    /**
     * 检查Referer是否异常
     */
    private static function checkRefererAnomaly()
    {
        $request = new Request();
        $referer = $request->getReferer();

        if (empty($referer)) {
            return false;
        }

        $suspiciousReferers = [
            'casino',
            'poker',
            'gambling',
            'adult',
            'sex',
            'seo_service',
            'backlink_tool',
            '.xyz/'
        ];

        foreach ($suspiciousReferers as $refPattern) {
            if (stripos($referer, $refPattern) !== false) {
                return '来源异常';
            }
        }

        return false;
    }

    /**
     * 检查IP规则匹配
     */
    private static function matchIPRules($ip, $rules, $config)
    {
        // 判断是否开启调试模式
        $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;

        if (empty($rules)) {
            return false;
        }

        $lines = preg_split('/[\r\n]+/', $rules, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || substr($line, 0, 1) === '#') {
                continue; // 跳过空行和完整注释行
            }

            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 尝试匹配规则 IP: {$ip}, 规则: {$line}");
            }

            if (self::matchSingleIPRule($ip, $line, $config)) {
                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: 规则匹配成功 IP: {$ip}, 规则: {$line}");
                }
                return true;
            }
        }

        return false;
    }

    /**
     * 匹配单条IP规则
     */
    private static function matchSingleIPRule($ip, $rule, $config)
    {
        // 判断是否开启调试模式
        $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;

        // 去除规则中的行内注释
        if (strpos($rule, '#') !== false) {
            $rule = trim(substr($rule, 0, strpos($rule, '#')));
        }

        if (empty($rule)) {
            return false;
        }

        if ($debugMode) {
            error_log("AdvancedBlockIP Debug: 处理规则 IP: {$ip}, 清理后规则: {$rule}");
        }

        // 检查各种规则格式
        if ($rule === $ip) {
            return true; // 完全匹配
        } elseif (strpos($rule, '*') !== false) {
            return self::matchWildcard($ip, $rule); // 通配符匹配
        } elseif (strpos($rule, '/') !== false) {
            return self::matchCIDR($ip, $rule); // CIDR匹配
        } elseif (strpos($rule, '-') !== false) {
            return self::matchIPRange($ip, $rule); // IP范围匹配
        } elseif (preg_match('/^[A-Z]{2}$/', $rule)) {
            // 国家代码匹配（需要GeoIP支持，暂未实现）
            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 国家代码匹配暂不支持 IP: {$ip}, 国家代码: {$rule}");
            }
            return false;
        }

        return false;
    }

    /**
     * 匹配通配符 - 确保正确实现
     */
    private static function matchWildcard($ip, $pattern)
    {
        $ipParts = explode('.', $ip);
        $patternParts = explode('.', $pattern);

        if (count($ipParts) != 4 || count($patternParts) != 4) {
            return false;
        }

        for ($i = 0; $i < 4; $i++) {
            if ($patternParts[$i] === '*') {
                continue;
            }

            if ($ipParts[$i] !== $patternParts[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * 匹配CIDR格式
     */
    private static function matchCIDR($ip, $cidr)
    {
        list($network, $mask) = explode('/', $cidr);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($network);
        }

        return false;
    }

    /**
     * 匹配IP范围
     */
    private static function matchIPRange($ip, $range)
    {
        $ipParts = explode('.', $ip);
        $rangeParts = explode('.', $range);

        for ($i = 0; $i < 4; $i++) {
            if (strpos($rangeParts[$i], '-') !== false) {
                list($start, $end) = explode('-', $rangeParts[$i]);
                if ($ipParts[$i] < $start || $ipParts[$i] > $end) {
                    return false;
                }
            } else {
                if ($ipParts[$i] != $rangeParts[$i]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 记录最后访问时间
     */
    private static function recordLastAccess($ip, $url = '')
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $currentTime = time();
            $request = new Request();

            $db->query($db->insert($prefix . 'blockip_access_log')->rows(array(
                'ip' => $ip,
                'url' => $url,
                'user_agent' => substr((string)$request->getAgent(), 0, 255),
                'last_access' => $currentTime,
                'timestamp' => $currentTime
            )));

            // 清理30天前的旧记录
            $db->query($db->delete($prefix . 'blockip_access_log')
                ->where('last_access < ?', $currentTime - 2592000));
        } catch (\Exception $e) {
            error_log("AdvancedBlockIP recordLastAccess Error: " . $e->getMessage());
        }
    }

    /**
     * 检查访问是否过于频繁
     */
    private static function isAccessTooFrequent($ip, $config, $isBlacklistCheck = false)
    {
        $accessInterval = isset($config->accessInterval) ? (int)$config->accessInterval : 10;
        if ($accessInterval <= 0) {
            return false;
        }

        if ($isBlacklistCheck) {
            $accessInterval = max(1, $accessInterval * 0.1);
        }

        $lastAccess = self::getLastAccessTime($ip);
        if ($lastAccess && (time() - $lastAccess) < $accessInterval) {
            return true;
        }

        return false;
    }

    /**
     * 获取上次访问时间
     */
    private static function getLastAccessTime($ip)
    {
        static $accessCache = array();
        if (isset($accessCache[$ip])) {
            return $accessCache[$ip];
        }

        try {
            $db = Db::get();
            $prefix = $db->getPrefix();

            $result = $db->fetchObject($db->select('last_access')
                ->from($prefix . 'blockip_access_log')
                ->where('ip = ?', $ip)
                ->order('last_access', Db::SORT_DESC)
                ->limit(1));

            $lastAccess = $result ? (int)$result->last_access : 0;
            $accessCache[$ip] = $lastAccess;
            return $lastAccess;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 拦截访问
     */
    private static function blockAccess($ip, $reason, $config)
    {
        // 判断是否开启调试模式
        $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;

        $reasonMap = array(
            'blacklisted' => '黑名单拦截',
            'not_whitelisted' => '非白名单访问',
            'access_too_frequent' => '访问过于频繁',
            'blacklist_rate_limit' => '黑名单频率限制'
        );

        $finalReason = isset($reasonMap[$reason]) ? $reasonMap[$reason] : $reason;

        if ($debugMode) {
            error_log("AdvancedBlockIP Debug: 拦截访问 - IP: {$ip}, 原因: {$finalReason}");
        }

        self::logBlockedAccess($ip, $finalReason);

        $customMessage = isset($config->customMessage) && !empty($config->customMessage) ?
            $config->customMessage :
            '抱歉，您的访问被系统安全策略拦截。如需帮助，请联系网站管理员。';

        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>访问被拦截</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .icon { font-size: 64px; color: #e74c3c; margin-bottom: 20px; }
        h1 { color: #2c3e50; margin-bottom: 20px; }
        p { color: #7f8c8d; line-height: 1.6; }
        .reason { background: #ecf0f1; padding: 10px; border-radius: 5px; margin: 20px 0; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🛡️</div>
        <h1>访问被拦截</h1>
        <p>' . $customMessage . '</p>
        <div class="reason">拦截原因: ' . htmlspecialchars($finalReason) . '</div>
        <p><small>IP: ' . htmlspecialchars($ip) . ' | 时间: ' . date('Y-m-d H:i:s') . '</small></p>
    </div>
</body>
</html>';

        exit;
    }

    /**
     * 记录拦截日志
     */
    private static function logBlockedAccess($ip, $reason = '')
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $request = new Request();

            $db->query($db->insert($prefix . 'blockip_logs')->rows([
                'ip' => $ip,
                'created' => time(),
                'user_agent' => substr((string)$request->getAgent(), 0, 255),
                'referer' => substr((string)$request->getReferer(), 0, 255),
                'url' => substr($request->getRequestUrl(), 0, 255),
                'reason' => $reason ?: 'unknown',
                'action' => 'blocked'
            ]));
        } catch (Exception $e) {
            error_log("AdvancedBlockIP Log Error: " . $e->getMessage());
        }
    }

    /**
     * 自动将IP加入黑名单
     */
    private static function addToBlacklist($ip, $config, $detectionType = '未知类型')
    {
        // 判断是否开启调试模式
        $debugMode = isset($config->debugMode) ? (bool)$config->debugMode : false;

        if (self::isWhitelisted($ip, $config)) {
            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 拒绝加入黑名单 - IP在白名单中: {$ip}");
            }
            return false;
        }

        $blacklistConfigKey = isset($config->blacklist) ? 'blacklist' : (isset($config->ips) ? 'ips' : null);
        if (!$blacklistConfigKey) {
            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 加入黑名单失败 - 无法确定黑名单配置键: {$ip}");
            }
            return false;
        }

        $currentBlacklist = isset($config->{$blacklistConfigKey}) ? $config->{$blacklistConfigKey} : '';
        $blacklistLines = preg_split('/[\r\n]+/', $currentBlacklist, -1, PREG_SPLIT_NO_EMPTY);

        // 检查IP是否已在黑名单中
        foreach ($blacklistLines as $line) {
            if (self::matchSingleIPRule($ip, trim($line), $config)) {
                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: IP已在黑名单中: {$ip}");
                }
                self::logAutoBlacklist($ip, '智能检测：' . $detectionType);
                return false;
            }
        }

        $newRule = $ip . ' # 智能检测：' . $detectionType . ' @ ' . date('Y-m-d H:i:s');
        $updatedBlacklist = $currentBlacklist . (empty($currentBlacklist) ? '' : "\n") . $newRule;

        if ($debugMode) {
            error_log("AdvancedBlockIP Debug: 尝试将IP添加到黑名单: {$ip}, 检测类型: {$detectionType}");
        }

        try {
            $db = Db::get();
            $currentConfigResult = $db->fetchObject($db->select('value')
                ->from('table.options')
                ->where('name = ? AND user = 0', 'plugin:AdvancedBlockIP'));

            if ($currentConfigResult && $currentConfigResult->value) {
                $currentConfigArray = unserialize($currentConfigResult->value);
                $currentConfigArray[$blacklistConfigKey] = $updatedBlacklist;

                $db->query(
                    $db->update('table.options')
                        ->rows(['value' => serialize($currentConfigArray)])
                        ->where('name = ? AND user = 0', 'plugin:AdvancedBlockIP')
                );

                if ($debugMode) {
                    error_log("AdvancedBlockIP Debug: IP成功添加到黑名单: {$ip}");
                }
            }
        } catch (\Exception $e) {
            error_log("AdvancedBlockIP addToBlacklist Error: " . $e->getMessage());
            if ($debugMode) {
                error_log("AdvancedBlockIP Debug: 加入黑名单失败 - 数据库错误: {$ip}");
            }
            return false;
        }

        self::logAutoBlacklist($ip, '智能检测：' . $detectionType);
        return true;
    }

    /**
     * 记录自动加入黑名单的日志
     */
    private static function logAutoBlacklist($ip, $detectionType)
    {
        try {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $request = new Request();

            $db->query($db->insert($prefix . 'blockip_logs')->rows([
                'ip' => $ip,
                'created' => time(),
                'user_agent' => substr((string)$request->getAgent(), 0, 255),
                'referer' => substr((string)$request->getReferer(), 0, 255),
                'url' => substr($request->getRequestUrl(), 0, 255),
                'reason' => $detectionType,
                'action' => 'auto_blacklisted'
            ]));
        } catch (Exception $e) {
            error_log("AdvancedBlockIP AutoBlacklist Log Error: " . $e->getMessage());
        }
    }

    /**
     * 创建数据表
     */
    private static function createTables()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $sql1 = "CREATE TABLE IF NOT EXISTS `{$prefix}blockip_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `action` varchar(20) NOT NULL,
            `reason` varchar(100) DEFAULT '',
            `url` varchar(500) DEFAULT '',
            `user_agent` text,
            `referer` varchar(255) DEFAULT '',
            `created` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ip_created` (`ip`, `created`),
            KEY `action_created` (`action`, `created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $sql2 = "CREATE TABLE IF NOT EXISTS `{$prefix}blockip_access_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `url` varchar(500) DEFAULT '',
            `user_agent` text,
            `last_access` int(11) NOT NULL,
            `timestamp` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ip_timestamp` (`ip`, `timestamp`),
            KEY `timestamp` (`timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $db->query($sql1);
            $db->query($sql2);
        } catch (\Exception $e) {
            error_log("AdvancedBlockIP createTables Error: " . $e->getMessage());
        }
    }
}
