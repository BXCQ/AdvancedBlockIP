<?php

/**
 * 高级IP访问控制插件 - 控制台页面
 * 
 * 显示详细的日志记录和统计信息
 */

if (!defined('__TYPECHO_ADMIN__') && !defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// 引入适配器确保类别名正确加载
require_once __DIR__ . '/adapter.php';

// 获取必要的对象
$request = Typecho_Request::getInstance();
$action = $request->get('action', '');
$page = max(1, (int)$request->get('page', 1));
$pageSize = 15;

// 获取数据库连接
$db = Typecho_Db::get();
$prefix = $db->getPrefix();
$optionsWidget = Widget_Options::alloc(); // Use a different name to avoid conflict if $options is used later for plugin options specifically
$pluginOptions = $optionsWidget->plugin('AdvancedBlockIP');

// 处理清空日志请求
if ($action === 'clear_logs' && $request->isPost()) {
    try {
        $db->query($db->delete($prefix . 'blockip_logs'));
        $success_message = "日志已清空";
    } catch (Exception $e) {
        $error_message = "清空失败: " . $e->getMessage();
    }
}

// 处理从黑名单删除IP的请求
if ($action === 'delete_from_blacklist' && $request->isPost()) {
    $ipToDelete = $request->get('ip_to_delete', '');
    if ($ipToDelete) {
        try {
            // $pluginOptions is already loaded
            if ($pluginOptions) {
                $blacklistConfigKey = isset($pluginOptions->blacklist) ? 'blacklist' : (isset($pluginOptions->ips) ? 'ips' : null);
                $currentBlacklist = $blacklistConfigKey ? $pluginOptions->{$blacklistConfigKey} : '';

                $blacklistLines = preg_split('/[\\r\\n]+/', $currentBlacklist, -1, PREG_SPLIT_NO_EMPTY);
                $newBlacklistLines = [];
                $found = false;
                foreach ($blacklistLines as $line) {
                    // Check if the line starts with the IP to delete, optionally followed by a space and #
                    if (preg_match('/^' . preg_quote($ipToDelete, '/') . '(\\s+#.*)?$/', trim($line))) {
                        $found = true;
                        continue; // Skip this line
                    }
                    $newBlacklistLines[] = $line;
                }

                if ($found) {
                    $updatedBlacklist = implode("\\n", $newBlacklistLines);

                    // 重新获取当前完整的插件配置
                    $currentConfigResult = $db->fetchObject($db->select('value')
                        ->from('table.options')
                        ->where('name = ? AND user = 0', 'plugin:AdvancedBlockIP'));

                    if ($currentConfigResult && $currentConfigResult->value) {
                        $currentPluginConfigArray = unserialize($currentConfigResult->value);
                        $currentPluginConfigArray[$blacklistConfigKey] = $updatedBlacklist;

                        $db->query(
                            $db->update('table.options')
                                ->rows(['value' => serialize($currentPluginConfigArray)])
                                ->where('name = ? AND user = 0', 'plugin:AdvancedBlockIP')
                        );

                        // 清除Typecho的配置缓存
                        try {
                            $reflectionClass = new \ReflectionClass('Widget_Options');
                            $configsProperty = $reflectionClass->getProperty('_configs');
                            $configsProperty->setAccessible(true);
                            $configsProperty->setValue(null, null);

                            $pluginsProperty = $reflectionClass->getProperty('_plugins');
                            $pluginsProperty->setAccessible(true);
                            $pluginsProperty->setValue(null, null);
                        } catch (\ReflectionException $e) {
                            error_log("AdvancedBlockIP Console Cache Clear Error: " . $e->getMessage());
                        }

                        $success_message = "IP '" . htmlspecialchars($ipToDelete) . "' 已从黑名单中移除。";

                        // 重定向到当前页面以刷新数据显示
                        header('Location: ' . $_SERVER['REQUEST_URI']);
                        exit;
                    } else {
                        $error_message = "无法获取插件配置以删除IP。";
                    }
                } else {
                    $error_message = "未在黑名单中找到IP '" . htmlspecialchars($ipToDelete) . "'。";
                }
            } else {
                $error_message = "无法加载插件配置以删除IP。";
            }
        } catch (Exception $e) {
            $error_message = "从黑名单移除IP失败: " . $e->getMessage();
        }
    } else {
        $error_message = "未指定要从黑名单移除的IP。";
    }
}

// 处理删除本页日志请求
if ($action === 'delete_page_logs' && $request->isPost()) {
    try {
        // 获取当前页的日志ID
        $offset = ($page - 1) * $pageSize;
        $currentPageLogs = $db->fetchAll($db->select('id')
            ->from($prefix . 'blockip_logs')
            ->order('created', Typecho_Db::SORT_DESC)
            ->limit($pageSize)
            ->offset($offset));
        
        if (!empty($currentPageLogs)) {
            $deletedCount = 0;
            foreach ($currentPageLogs as $log) {
                $db->query($db->delete($prefix . 'blockip_logs')->where('id = ?', $log['id']));
                $deletedCount++;
            }
            $success_message = "本页 " . $deletedCount . " 条日志已删除";
        } else {
            $error_message = "本页没有可删除的日志";
        }
    } catch (Exception $e) {
        $error_message = "删除本页日志失败: " . $e->getMessage();
    }
}

// 获取统计数据
function getDetailedStats($db, $prefix)
{
    $stats = array();

    try {
        // 基础统计
        $totalResult = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs'));
        $stats['total'] = $totalResult ? (int)$totalResult->count : 0;

        // 今日统计
        $today = strtotime('today');
        $todayResult = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs')
            ->where('created >= ?', $today));
        $stats['today'] = $todayResult ? (int)$todayResult->count : 0;

        // 自动加入黑名单统计
        $autoBlacklistResult = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs')
            ->where('action = ?', 'auto_blacklisted'));
        $stats['auto_blacklisted'] = $autoBlacklistResult ? (int)$autoBlacklistResult->count : 0;

        // 按原因分组统计
        $reasonStats = $db->fetchAll($db->select('reason, COUNT(*) as count')
            ->from($prefix . 'blockip_logs')
            ->group('reason')
            ->order('count', Typecho_Db::SORT_DESC));
        $stats['by_reason'] = $reasonStats ?: array();

        // 最活跃IP统计
        $topIPs = $db->fetchAll($db->select('ip, COUNT(*) as count')
            ->from($prefix . 'blockip_logs')
            ->group('ip')
            ->order('count', Typecho_Db::SORT_DESC)
            ->limit(10));
        $stats['top_ips'] = $topIPs ?: array();

        // 24小时内按小时统计
        $hourlyStats = array();
        for ($i = 23; $i >= 0; $i--) {
            $hourStart = strtotime("-{$i} hours", strtotime(date('Y-m-d H:00:00')));
            $hourEnd = $hourStart + 3600;

            $hourResult = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'blockip_logs')
                ->where('created >= ? AND created < ?', $hourStart, $hourEnd));

            $hourlyStats[] = array(
                'hour' => date('H:i', $hourStart),
                'count' => $hourResult ? (int)$hourResult->count : 0
            );
        }
        $stats['hourly'] = $hourlyStats;
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }

    return $stats;
}

// 获取日志列表
function getLogsList($db, $prefix, $page, $pageSize)
{
    $offset = ($page - 1) * $pageSize;

    try {
        // 获取总数
        $totalResult = $db->fetchObject($db->select('COUNT(*) as count')
            ->from($prefix . 'blockip_logs'));
        $total = $totalResult ? (int)$totalResult->count : 0;

        // 获取日志列表
        $logs = $db->fetchAll($db->select()
            ->from($prefix . 'blockip_logs')
            ->order('created', Typecho_Db::SORT_DESC)
            ->limit($pageSize)
            ->offset($offset));

        return array(
            'logs' => $logs ?: array(),
            'total' => $total,
            'total_pages' => ceil($total / $pageSize)
        );
    } catch (Exception $e) {
        return array(
            'logs' => array(),
            'total' => 0,
            'total_pages' => 0,
            'error' => $e->getMessage()
        );
    }
}

$stats = getDetailedStats($db, $prefix);
$logData = getLogsList($db, $prefix, $page, $pageSize);

// 重新加载插件选项以确保获取最新数据（特别是在删除操作后）
$optionsWidget = Widget_Options::alloc();
$pluginOptions = $optionsWidget->plugin('AdvancedBlockIP');

// 获取黑名单条目
function getBlacklistEntries($pluginOptions)
{
    $entries = [];
    if (!$pluginOptions) {
        return $entries;
    }

    $blacklistConfigKey = isset($pluginOptions->blacklist) ? 'blacklist' : (isset($pluginOptions->ips) ? 'ips' : null);
    $blacklistString = $blacklistConfigKey ? $pluginOptions->{$blacklistConfigKey} : '';

    if (empty($blacklistString)) {
        return $entries;
    }

    $lines = preg_split('/[\\r\\n]+/', $blacklistString, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $ip = $line;
        $reason = '手动添加'; // Default reason
        $date_added = '';

        if (strpos($line, '#') !== false) {
            list($ip, $comment) = explode('#', $line, 2);
            $ip = trim($ip);
            $comment = trim($comment);

            // Try to parse reason and date from comment
            // Example comment: 智能检测：频率异常, UA异常 @ 2024-07-30 10:00:00
            if (preg_match('/^(.*?) @ (\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2})$/', $comment, $matches)) {
                $reason = trim($matches[1]);
                $date_added = trim($matches[2]);
            } elseif (!empty($comment)) {
                // If comment doesn't match the full pattern but is not empty, use it as reason
                $reason = $comment;
            }
        }

        // Ensure IP is valid before adding (basic check)
        if (
            filter_var($ip, FILTER_VALIDATE_IP) ||
            strpos($ip, '*') !== false ||
            strpos($ip, '/') !== false ||
            (strlen($ip) == 2 && ctype_alpha($ip)) || // Country code
            (strpos($ip, '-') !== false && substr_count($ip, '.') >= 1) // Range
        ) {
            $entries[] = [
                'ip' => $ip,
                'reason' => $reason,
                'date_added' => $date_added
            ];
        }
    }
    return $entries;
}

$blacklistEntries = getBlacklistEntries($pluginOptions);

// 获取原因的中文描述
function getReasonDescription($reason)
{
    $descriptions = array(
        'blacklisted' => '黑名单拦截',
        'not_whitelisted' => '非白名单',
        'smart_detection' => '智能检测',
        'access_too_frequent' => '访问过频',
        'blacklist_rate_limit' => '黑名单频率限制',
        'frequency_anomaly' => '频率异常',
        'user_agent_anomaly' => 'UA异常',
        'referer_anomaly' => '来源异常',
        'behavior_pattern' => '行为异常',
        'smart_detection_auto_add' => '智能检测自动加入黑名单',
        '频率异常' => '频率异常',
        'UA异常' => 'UA异常',
        'UA为空' => 'UA为空',
        '来源异常' => '来源异常',
        '行为模式异常' => '行为异常'
    );

    // 如果原因以特定前缀开头，直接返回完整描述
    if (strpos($reason, '智能检测：') === 0 || 
        strpos($reason, '黑名单：') === 0) {
        return $reason;
    }

    return isset($descriptions[$reason]) ? $descriptions[$reason] : $reason;
}

// 构建当前页面URL（用于分页）
function getCurrentPageUrl($page = null)
{
    $baseUrl = $_SERVER['REQUEST_URI'];
    $baseUrl = preg_replace('/[?&]page=\d+/', '', $baseUrl);
    $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

    if ($page !== null) {
        return $baseUrl . $separator . 'page=' . $page;
    }

    return $baseUrl;
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>IP防护控制台 - <?php echo $optionsWidget->title; ?></title>
    <link rel="stylesheet" type="text/css" href="<?php echo $optionsWidget->adminStaticUrl('css'); ?>/normalize.css">
    <link rel="stylesheet" type="text/css" href="<?php echo $optionsWidget->adminStaticUrl('css'); ?>/grid.css">
    <link rel="stylesheet" type="text/css" href="<?php echo $optionsWidget->adminStaticUrl('css'); ?>/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary:rgb(18, 123, 203);
            /* 更柔和的蓝色 */
            --color-primary-light: #e8f4fd;
            --color-primary-dark: #4a8bc2;
            --color-success: #28a745;
            /* 绿色成功 */
            --color-success-light: #eaf6ec;
            --color-danger:rgb(245, 68, 86);
            /* 红色危险 */
            --color-danger-light: #fdecea;
            --color-warning: #ffc107;
            /* 黄色警告 */
            --color-text-primary: #212529;
            /* 主要文本 */
            --color-text-secondary: #6c757d;
            /* 次要文本 */
            --color-text-muted: #adb5bd;
            /* 静默文本 */
            --color-background: #f8f9fa;
            /* 主背景 */
            --color-card-background: #ffffff;
            /* 卡片背景 */
            --color-border: #dee2e6;
            /* 边框颜色 */
            --font-family-sans-serif: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            --border-radius: 0.375rem;
            /* 6px - Slightly smaller radius for a tighter feel */
            --box-shadow: 0 0.2rem 0.6rem rgba(0, 0, 0, 0.04);
            /* Subtler shadow */
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family-sans-serif);
            background-color: var(--color-background);
            color: var(--color-text-primary);
            line-height: 1.55;
            /* Slightly tighter line height */
            margin: 0;
            padding: 0;
            font-size: 15px;
            /* Base font size slightly reduced */
        }

        .container {
            max-width: 1500px;
            /* Slightly reduced max-width */
            margin: 0 auto;
            padding: 1.5rem;
            /* Reduced container padding */
        }

        /* 标签页导航样式 */
        .tab-navigation {
            display: flex;
            background: var(--color-card-background);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            box-shadow: var(--box-shadow);
            margin-bottom: 0;
            overflow: hidden;
        }

        .tab-button {
            flex: 1;
            padding: 1rem 1.5rem;
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 0.9375rem;
            font-weight: 500;
            color: var(--color-text-secondary);
            transition: all 0.2s ease;
            border-bottom: 3px solid transparent;
            position: relative;
        }

        .tab-button:hover {
            background-color: #f8f9fa;
            color: var(--color-text-primary);
        }

        .tab-button.active {
            color: var(--color-primary);
            border-bottom-color: var(--color-primary);
            background-color: var(--color-card-background);
        }

        .tab-button .tab-icon {
            margin-right: 0.5rem;
            font-size: 1.1rem;
        }

        /* 标签页内容样式 */
        .tab-content {
            display: none;
            background: var(--color-card-background);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .tab-content.active {
            display: block;
        }

        /* 页面头部 */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            /* Reduced margin */
            padding-bottom: 1rem;
            /* Reduced padding */
            border-bottom: 1px solid var(--color-border);
        }

        .page-title-group {
            flex-grow: 1;
        }

        .page-title {
            font-size: 1.75rem;
            /* Reduced from 1.875rem */
            font-weight: 600;
            margin: 0;
            color: var(--color-text-primary);
        }

        .page-subtitle {
            font-size: 0.875rem;
            /* Reduced from 0.9375rem */
            font-weight: 400;
            color: var(--color-text-secondary);
            margin-top: 0.2rem;
        }

        .action-buttons-header {
            display: flex;
            gap: 0.625rem;
            /* Reduced gap */
        }

        /* 消息提示 */
        .message {
            padding: 0.875rem 1rem;
            /* Reduced padding */
            margin: 1.25rem 0;
            /* Reduced margin */
            border-radius: var(--border-radius);
            font-weight: 500;
            font-size: 0.875rem;
            /* Reduced font size */
            border: 1px solid transparent;
        }

        .success {
            background-color: var(--color-success-light);
            color: var(--color-success);
            border-color: var(--color-success);
        }

        .error {
            background-color: var(--color-danger-light);
            color: var(--color-danger);
            border-color: var(--color-danger);
        }

        /* 卡片基础样式 */
        .card {
            background-color: var(--color-card-background);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.25rem;
            /* Reduced margin */
            padding: 1.25rem;
            /* Reduced padding */
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }

        .card:hover {
            transform: translateY(-2px);
            /* Reduced hover effect */
            box-shadow: 0 0.4rem 1.2rem rgba(0, 0, 0, 0.06);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            /* Reduced margin */
            padding-bottom: 0.625rem;
            /* Reduced padding */
            border-bottom: 1px solid var(--color-border);
        }

        .card-icon {
            font-size: 1.125rem;
            /* Reduced icon size */
            margin-right: 0.625rem;
            color: var(--color-primary);
        }

        .card-title {
            font-size: 1.0625rem;
            /* Approx 17px, reduced */
            font-weight: 600;
            margin: 0;
            color: var(--color-text-primary);
        }

        /* 统计卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            /* Reduced minmax */
            gap: 1rem;
            /* Reduced gap */
            margin-bottom: 1.5rem;
            /* Reduced margin */
        }

        .stat-card {
            padding: 1rem;
            /* Reduced padding */
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            /* Reduced from 2.25rem */
            font-weight: 700;
            margin-bottom: 0.2rem;
            color: var(--color-primary);
            line-height: 1.1;
        }

        .stat-label {
            font-size: 0.8125rem;
            /* Reduced from 0.875rem */
            font-weight: 500;
            color: var(--color-text-secondary);
            margin: 0;
        }

        /* 趋势图表 */
        .hourly-chart-container .card-header {
            margin-bottom: 0.625rem;
            /* Reduced margin */
        }

        .hourly-chart {
            display: flex;
            align-items: flex-end;
            height: 170px;
            /* Reduced from 200px */
            gap: 0.2rem;
            /* Reduced gap */
            padding: 0.75rem 0 0 0;
            /* Reduced padding */
        }

        .hour-bar {
            flex: 1;
            background-color: var(--color-primary-light);
            border-top: 2px solid var(--color-primary);
            position: relative;
            cursor: pointer;
            border-radius: 0.1875rem 0.1875rem 0 0;
            /* 3px */
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .hour-bar:hover {
            background-color: var(--color-primary);
            border-color: var(--color-primary-dark);
        }

        .hour-bar:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 105%;
            left: 50%;
            transform: translateX(-50%);
            background-color: var(--color-text-primary);
            color: white;
            padding: 0.4rem 0.6rem;
            /* Reduced padding */
            border-radius: 0.25rem;
            /* 4px */
            font-size: 0.75rem;
            /* Reduced from 0.8125rem */
            font-weight: 500;
            white-space: nowrap;
            z-index: 10;
            box-shadow: 0 0.1rem 0.4rem rgba(0, 0, 0, 0.1);
        }

        .chart-time-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            /* Reduced from 0.8125rem */
            color: var(--color-text-muted);
            margin-top: 0.4rem;
            padding: 0 0.2rem;
        }

        /* 原因和IP统计 */
        .detailed-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            /* Reduced minmax */
            gap: 1rem;
            /* Reduced gap */
        }

        .list-stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.625rem 0;
            /* Reduced padding */
            border-bottom: 1px solid var(--color-border);
            font-size: 0.875rem;
            /* Reduced from 0.9375rem */
        }

        .list-stat-item:last-child {
            border-bottom: none;
        }

        .list-stat-item span:first-child {
            font-weight: 500;
            color: var(--color-text-secondary);
        }

        .list-stat-item-value {
            font-weight: 600;
            color: var(--color-text-primary);
            background-color: #e9ecef;
            padding: 0.2rem 0.5rem;
            /* Reduced padding */
            border-radius: 0.1875rem;
            /* 3px */
            font-size: 0.8125rem;
            /* Reduced from 0.875rem */
        }

        .monospace-font {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
        }

        /* 黑名单表格专用样式 */
        .blacklist-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.75rem;
            font-size: 0.8125rem;
        }

        .blacklist-table th,
        .blacklist-table td {
            padding: 0.625rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--color-border);
        }

        .blacklist-table th {
            background-color: #f1f3f5;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .blacklist-table tr:hover td {
            background-color: #f8f9fa;
        }

        .blacklist-table .btn-delete-ip {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        /* 日志表格 */
        .log-table-container .card-header {
            justify-content: space-between;
        }

        .log-summary {
            font-size: 0.875rem;
            /* Reduced from 0.9375rem */
            color: var(--color-text-secondary);
        }

        .log-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.75rem;
            /* Reduced margin */
            font-size: 0.8125rem;
            /* Reduced from 0.875rem */
        }

        .log-table th,
        .log-table td {
            padding: 0.625rem 0.75rem;
            /* Reduced padding */
            text-align: left;
            border-bottom: 1px solid var(--color-border);
        }

        .log-table th {
            background-color: #f1f3f5;
            font-weight: 600;
            font-size: 0.75rem;
            /* Reduced from 0.8125rem */
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }

        .log-table tr:hover td {
            background-color: #f8f9fa;
        }

        .log-table td .highlight {
            background-color: var(--color-danger-light);
            color: var(--color-danger);
            padding: 0.2rem 0.4rem;
            /* Reduced padding */
            border-radius: 0.1875rem;
            /* 3px */
            font-weight: 600;
            font-size: 0.6875rem;
            /* 11px, reduced */
            display: inline-block;
        }

        .log-table .text-truncate {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            vertical-align: middle;
        }

        /* 分页 */
        .pagination {
            text-align: center;
            margin: 1.5rem 0 0.75rem 0;
            /* Reduced margin */
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            /* Reduced size */
            height: 32px;
            /* Reduced size */
            padding: 0 0.625rem;
            /* Reduced padding */
            margin: 0 0.1875rem;
            /* Reduced margin */
            border: 1px solid var(--color-border);
            text-decoration: none;
            border-radius: 0.25rem;
            /* 4px */
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.8125rem;
            /* Reduced font size */
            background-color: var(--color-card-background);
            color: var(--color-primary);
        }

        .pagination a:hover {
            background-color: var(--color-primary-light);
            border-color: var(--color-primary);
            color: var(--color-primary-dark);
        }

        .pagination .current {
            background-color: var(--color-primary);
            color: white;
            border-color: var(--color-primary);
            font-weight: 600;
        }

        /* 按钮样式 */
        .btn {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            /* 减小间距 */
            padding: 0.4rem 0.8rem !important;
            /* 减小内边距 */
            border: 1px solid transparent;
            border-radius: 0.25rem;
            /* 4px */
            text-decoration: none;
            font-weight: 500;
            /* Standardized weight */
            font-size: 0.8125rem;
            /* 减小字体 */
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            line-height: 1.4;
            /* 减小行高 */
            box-sizing: border-box;
            height: auto !important;
            vertical-align: middle;
        }

        .btn-primary {
            background-color: var(--color-primary) !important;
            color: white !important;
            border-color: var(--color-primary) !important;
        }

        .btn-primary:hover {
            background-color: var(--color-primary-dark) !important;
            border-color: var(--color-primary-dark) !important;
            color: white !important;
            text-decoration: none !important;
            transform: translateY(-1px);
        }

        .btn-danger {
            background-color: var(--color-danger) !important;
            color: white !important;
            border-color: var(--color-danger) !important;
        }

        .btn-danger:hover {
            background-color: #c82333 !important;
            border-color: #c82333 !important;
            color: white !important;
            text-decoration: none !important;
            transform: translateY(-1px);
        }

        .btn-outline-secondary {
            background-color: transparent !important;
            color: var(--color-text-secondary) !important;
            border-color: var(--color-border) !important;
        }

        .btn-outline-secondary:hover {
            background-color: var(--color-text-secondary) !important;
            color: white !important;
            border-color: var(--color-text-secondary) !important;
            text-decoration: none !important;
        }

        /* 确保按钮图标和文字对齐 */
        .btn-icon {
            display: inline-flex;
            align-items: center;
            font-size: 0.875rem;
            /* 减小图标尺寸 */
        }

        /* 功能说明区域 */
        .info-section .card-title {
            font-size: 1.0625rem;
            /* Consistent with other card titles */
        }

        .info-section ul {
            margin: 0;
            padding-left: 0;
            list-style: none;
        }

        .info-section li {
            margin-bottom: 0.625rem;
            /* Reduced margin */
            color: var(--color-text-secondary);
            font-size: 0.875rem;
            /* Reduced font size */
            padding-left: 1.25rem;
            /* Reduced padding */
            position: relative;
        }

        .info-section li::before {
            content: '💡';
            position: absolute;
            left: 0;
            color: var(--color-warning);
            font-size: 0.9rem;
            /* Reduced icon size */
        }

        .info-section .info-highlight {
            color: var(--color-primary);
            font-weight: 600;
        }

        /* 空状态 */
        .empty-state {
            text-align: center;
            padding: 1.5rem;
            /* Reduced padding */
            color: var(--color-text-muted);
        }

        .empty-state-icon {
            font-size: 2.5rem;
            /* Reduced icon size */
            margin-bottom: 0.75rem;
            opacity: 0.7;
        }

        .empty-state-title {
            font-size: 1.125rem;
            /* Reduced font size */
            font-weight: 500;
            color: var(--color-text-secondary);
            margin-bottom: 0.4rem;
        }

        .empty-state-text {
            font-size: 0.875rem;
            /* Reduced font size */
        }

        /* 响应式 */
        @media (max-width: 768px) {
            .container {
                padding: 0.75rem;
                /* Further reduced for mobile */
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }

            .page-title {
                font-size: 1.375rem;
                /* Further reduced for mobile */
            }

            .stats-grid,
            .detailed-stats-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .action-buttons-header {
                width: 100%;
                flex-direction: column;
                gap: 0.5rem;
                /* Reduced gap for mobile buttons */
            }

            .action-buttons-header .btn {
                width: 100%;
                justify-content: center;
                padding: 0.6rem 0.5rem;
                /* Adjust button padding for mobile */
                font-size: 0.8125rem;
                /* Adjust button font for mobile */
            }

            .log-table {
                font-size: 0.75rem;
                /* Further reduced for mobile */
            }

            .log-table th,
            .log-table td {
                padding: 0.5rem 0.375rem;
                /* Further reduced padding for mobile */
            }

            .log-table .text-truncate {
                max-width: 80px;
                /* Adjust truncate for mobile */
            }

            .card {
                padding: 1rem;
                /* Adjust card padding for mobile */
            }

            .stat-card {
                padding: 0.75rem;
                /* Adjust stat card padding for mobile */
            }

            .stat-number {
                font-size: 1.75rem;
                /* Adjust stat number for mobile */
            }

            .hourly-chart {
                height: 140px;
                /* Adjust chart height for mobile */
            }

            .tab-navigation {
                flex-direction: column;
            }

            .tab-button {
                border-bottom: none;
                border-right: 3px solid transparent;
            }

            .tab-button.active {
                border-bottom: none;
                border-right-color: var(--color-primary);
            }
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>
    <?php include 'menu.php'; ?>

    <div class="main">
        <div class="body container">
            <!-- 页面头部 -->
            <div class="page-header">
                <div class="page-title-group">
                    <h1 class="page-title">🛡️ IP防护控制台</h1>
                    <p class="page-subtitle">实时监控网站访问安全，智能拦截恶意IP和可疑行为。</p>
                </div>
                <div class="action-buttons-header">
                    <a href="<?php echo $optionsWidget->adminUrl; ?>options-plugin.php?config=AdvancedBlockIP" class="btn btn-primary">
                        <span class="btn-icon">⚙️</span>
                        <span>插件设置</span>
                    </a>
                </div>
            </div>

            <!-- 消息提示 -->
            <?php if (isset($success_message)): ?>
                <div class="message success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="message error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- 标签页导航 -->
            <div class="tab-navigation">
                <button class="tab-button active" onclick="switchTab('overview')">
                    <span class="tab-icon">📊</span>
                    <span>安全概览</span>
                </button>
                <button class="tab-button" onclick="switchTab('logs')">
                    <span class="tab-icon">📋</span>
                    <span>详细日志</span>
                </button>
            </div>

            <!-- 标签页内容 -->
            <!-- 安全概览页面 -->
            <div id="overview-tab" class="tab-content active">
                <!-- 统计概览 -->
                <div class="stats-grid">
                    <div class="card stat-card">
                        <div class="stat-number"><?php echo $stats['today']; ?></div>
                        <div class="stat-label">今日拦截</div>
                    </div>
                    <div class="card stat-card">
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">累计拦截</div>
                    </div>
                    <div class="card stat-card">
                        <div class="stat-number"><?php echo $stats['auto_blacklisted']; ?></div>
                        <div class="stat-label">自动拉黑IP数</div>
                    </div>
                    <div class="card stat-card">
                        <div class="stat-number"><?php echo count($stats['top_ips']); ?></div>
                        <div class="stat-label">当前活跃威胁IP</div>
                    </div>
                </div>

                <!-- 24小时统计图表 -->
                <div class="card hourly-chart-container">
                    <div class="card-header">
                        <span class="card-icon">📈</span>
                        <h3 class="card-title">24小时拦截趋势</h3>
                    </div>
                    <div class="hourly-chart">
                        <?php
                        $maxCount = max(array_column($stats['hourly'], 'count'));
                        if ($maxCount == 0) $maxCount = 1; // 避免除以零
                        foreach ($stats['hourly'] as $hour):
                            $heightPercentage = ($hour['count'] / $maxCount) * 100;
                        ?>
                            <div class="hour-bar"
                                style="height: <?php echo max(2, $heightPercentage); // 最小高度2px 
                                                ?>%"
                                data-tooltip="<?php echo $hour['hour'] . ': ' . $hour['count'] . '次拦截'; ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-time-labels">
                        <span><?php echo $stats['hourly'][0]['hour']; ?> (24小时前)</span>
                        <span>现在</span>
                    </div>
                </div>

                <!-- 详细统计 -->
                <div class="detailed-stats-grid">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-icon">🚫</span>
                            <h3 class="card-title">拦截原因分布</h3>
                        </div>
                        <?php if (!empty($stats['by_reason'])): ?>
                            <?php foreach ($stats['by_reason'] as $reason): ?>
                                <div class="list-stat-item">
                                    <span><?php echo getReasonDescription($reason['reason']); ?></span>
                                    <span class="list-stat-item-value"><?php echo $reason['count']; ?> 次</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">🤷</div>
                                <p class="empty-state-text">暂无拦截原因数据</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <span class="card-icon">🎯</span>
                            <h3 class="card-title">最活跃威胁IP Top 10</h3>
                        </div>
                        <?php if (!empty($stats['top_ips'])): ?>
                            <?php foreach ($stats['top_ips'] as $ip): ?>
                                <div class="list-stat-item">
                                    <span class="monospace-font"><?php echo htmlspecialchars($ip['ip']); ?></span>
                                    <span class="list-stat-item-value"><?php echo $ip['count']; ?> 次</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">🛡️</div>
                                <p class="empty-state-text">暂无活跃威胁IP记录</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 当前黑名单IP列表 -->
                <div class="card blacklist-table-container">
                    <div class="card-header">
                        <span class="card-icon">🔒</span>
                        <h3 class="card-title">管理黑名单IP</h3>
                    </div>
                    <?php if (!empty($blacklistEntries)): ?>
                        <table class="blacklist-table">
                            <thead>
                                <tr>
                                    <th style="width: 25%;">IP地址 / 规则</th>
                                    <th style="width: 40%;">添加原因</th>
                                    <th style="width: 20%;">添加时间</th>
                                    <th style="width: 15%; text-align: center;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blacklistEntries as $entry): ?>
                                    <tr>
                                        <td class="monospace-font"><?php echo htmlspecialchars($entry['ip']); ?></td>
                                        <td><?php echo htmlspecialchars(getReasonDescription($entry['reason'])); ?></td>
                                        <td class="monospace-font"><?php echo htmlspecialchars($entry['date_added'] ?: '-'); ?></td>
                                        <td style="text-align: center;">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('确定要从黑名单中移除此IP/规则吗？');">
                                                <input type="hidden" name="action" value="delete_from_blacklist">
                                                <input type="hidden" name="ip_to_delete" value="<?php echo htmlspecialchars($entry['ip']); ?>">
                                                <button type="submit" class="btn btn-danger btn-delete-ip">删除</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">✅</div>
                            <h4 class="empty-state-title">黑名单为空</h4>
                            <p class="empty-state-text">当前没有IP在黑名单中。</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 功能说明 -->
                <div class="card info-section">
                    <div class="card-header">
                        <h4 class="card-title" style="border-bottom:0; margin-bottom:0;">功能说明与使用指南</h4>
                    </div>
                    <ul>
                        <li>此控制台显示所有被拦截的访问记录，每页显示 <span class="info-highlight"><?php echo $pageSize; ?></span> 条记录。</li>
                        <li>访问间隔限制默认为 <span class="info-highlight">10秒</span>，可在 <a href="<?php echo $optionsWidget->adminUrl; ?>options-plugin.php?config=AdvancedBlockIP">插件设置</a> 中调整。</li>
                        <li>黑名单IP可选择 <span class="info-highlight">完全禁止访问</span> 或 <span class="info-highlight">仅限制频率</span>。</li>
                        <li><span class="info-highlight">智能模式</span>：默认启用，黑白名单同时生效，自动识别威胁。</li>
                        <li><span class="info-highlight">访问频率控制</span>：【访问过频】仅限制黑名单IP的访问频率，不会自动拉黑。</li>
                        <li><span class="info-highlight">频率异常检测</span>：【频率异常】严格的威胁识别，会自动拉黑IP。</li>
                        <li><span class="info-highlight">频率异常规则</span>：1秒内访问2个及以上不同URL 或 5秒内访问3次及以上 或 10秒内访问6次及以上。</li>
                        <li><span class="info-highlight">分类标记</span>：【访问过频】【频率异常】【UA异常】【来源异常】【行为异常】【多重异常】。</li>
                        <li>智能检测包括：频率异常、UA异常、来源异常和行为模式检测。</li>
                        <li><span class="info-highlight">日志管理</span>：支持清空所有日志或删除当前页日志，便于维护。</li>
                        <li>建议定期查看拦截日志，了解网站安全状况，必要时调整防护策略。</li>
                    </ul>
                </div>
            </div>

            <!-- 详细日志页面 -->
            <div id="logs-tab" class="tab-content">
                <div class="card log-table-container">
                    <div class="card-header">
                        <div>
                            <span class="card-icon">📋</span>
                            <h3 class="card-title" style="display:inline-block; margin-bottom:0;">详细拦截日志</h3>
                        </div>
                        <div style="display: flex; gap: 1rem; align-items: center;">
                            <div class="log-summary">
                                共 <?php echo $logData['total']; ?> 条记录，每页 <?php echo $pageSize; ?> 条
                            </div>
                            <button onclick="if(confirm('确定要删除本页的所有日志吗？\n\n此操作将永久删除本页显示的 <?php echo count($logData['logs']); ?> 条记录，无法恢复！\n请确认是否继续？')) { 
                                var form = document.createElement('form');
                                form.method = 'POST';
                                form.innerHTML = '<input type=&quot;hidden&quot; name=&quot;action&quot; value=&quot;delete_page_logs&quot;>';
                                document.body.appendChild(form);
                                form.submit();
                            }" class="btn btn-danger" style="font-size: 0.8125rem; padding: 0.4rem 0.8rem;">
                                <span class="btn-icon">🗑️</span>
                                <span>删除本页</span>
                            </button>
                            <button onclick="if(confirm('确定要清空所有日志吗？\n\n此操作将永久删除所有拦截记录，无法恢复！\n请确认是否继续？')) { 
                                var form = document.createElement('form');
                                form.method = 'POST';
                                form.innerHTML = '<input type=&quot;hidden&quot; name=&quot;action&quot; value=&quot;clear_logs&quot;>';
                                document.body.appendChild(form);
                                form.submit();
                            }" class="btn btn-danger">
                                <span class="btn-icon">🗑️</span>
                                <span>清空日志</span>
                            </button>
                        </div>
                    </div>

                    <?php if (!empty($logData['logs'])): ?>
                        <table class="log-table">
                            <thead>
                                <tr>
                                    <th style="width: 15%;">拦截时间</th>
                                    <th style="width: 15%;">IP地址</th>
                                    <th style="width: 20%;">拦截原因</th>
                                    <th style="width: 30%;">用户代理 (User-Agent)</th>
                                    <th style="width: 20%;">来源页面 (Referer)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logData['logs'] as $log): ?>
                                    <tr>
                                        <td class="monospace-font"><?php echo date('m-d H:i:s', $log['created']); ?></td>
                                        <td class="monospace-font"><?php echo htmlspecialchars($log['ip']); ?></td>
                                        <td>
                                            <span class="highlight"><?php echo getReasonDescription($log['reason']); ?></span>
                                        </td>
                                        <td style="max-width: 280px; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                            <?php echo htmlspecialchars(substr($log['user_agent'], 0, 100)) . (strlen($log['user_agent']) > 100 ? '...' : ''); ?>
                                        </td>
                                        <td>
                                            <span class="text-truncate" title="<?php echo htmlspecialchars($log['referer']); ?>">
                                                <?php echo htmlspecialchars($log['referer']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- 分页 -->
                        <?php if ($logData['total_pages'] > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="<?php echo getCurrentPageUrl($page - 1); ?>">‹ 上一页</a>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($logData['total_pages'], $page + 2);

                                if ($start > 1) {
                                    echo '<a href="' . getCurrentPageUrl(1) . '">1</a>';
                                    if ($start > 2) echo '<span>...</span>';
                                }

                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <?php if ($i == $page): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo getCurrentPageUrl($i); ?>"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php
                                if ($end < $logData['total_pages']) {
                                    if ($end < $logData['total_pages'] - 1) echo '<span>...</span>';
                                    echo '<a href="' . getCurrentPageUrl($logData['total_pages']) . '">' . $logData['total_pages'] . '</a>';
                                }
                                ?>

                                <?php if ($page < $logData['total_pages']): ?>
                                    <a href="<?php echo getCurrentPageUrl($page + 1); ?>">下一页 ›</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📜</div>
                            <h4 class="empty-state-title">暂无拦截日志</h4>
                            <p class="empty-state-text">系统正在持续保护您的网站，目前还没有拦截记录。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // 隐藏所有标签页内容
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });

            // 移除所有标签按钮的激活状态
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });

            // 显示选中的标签页内容
            document.getElementById(tabName + '-tab').classList.add('active');

            // 激活选中的标签按钮
            event.target.closest('.tab-button').classList.add('active');

            // 保存当前标签页状态到localStorage
            localStorage.setItem('activeTab', tabName);
        }

        // 页面加载时恢复上次选择的标签页
        document.addEventListener('DOMContentLoaded', function() {
            const savedTab = localStorage.getItem('activeTab');
            if (savedTab && document.getElementById(savedTab + '-tab')) {
                // 先移除所有激活状态
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.querySelectorAll('.tab-button').forEach(button => {
                    button.classList.remove('active');
                });

                // 激活保存的标签页
                document.getElementById(savedTab + '-tab').classList.add('active');
                document.querySelector(`[onclick="switchTab('${savedTab}')"]`).classList.add('active');
            }
        });
    </script>

    <?php include 'footer.php'; ?>
</body>

</html>