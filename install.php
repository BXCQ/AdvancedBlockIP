<?php

/**
 * 高级IP访问控制插件 - 安装检查脚本
 * 
 * 用于检查服务器环境和配置是否满足插件运行要求
 * 
 * @package AdvancedBlockIP
 * @author 璇
 * @version 2.3.3
 * @update: 2025.07.22
 */

// 开始输出缓冲
ob_start();

// 仅在命令行或管理员权限下运行
if (php_sapi_name() !== 'cli' && !isset($_GET['admin_check'])) {
    die('此脚本仅供管理员使用');
}

echo "高级IP访问控制插件 - 环境检查\n";
echo "=====================================\n\n";

// PHP版本检查
echo "1. PHP版本检查:\n";
$phpVersion = PHP_VERSION;
$minPhpVersion = '5.6.0';
if (version_compare($phpVersion, $minPhpVersion, '>=')) {
    echo "   ✓ PHP版本: {$phpVersion} (满足要求 >= {$minPhpVersion})\n";
} else {
    echo "   ✗ PHP版本: {$phpVersion} (需要 >= {$minPhpVersion})\n";
}

// 必需扩展检查
echo "\n2. PHP扩展检查:\n";
$requiredExtensions = ['pdo', 'json', 'curl', 'filter'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "   ✓ {$ext} 扩展已加载\n";
    } else {
        echo "   ✗ {$ext} 扩展未加载\n";
    }
}

// 数据库连接检查（如果可以连接的话）
echo "\n3. 数据库连接检查:\n";
try {
    // 尝试包含Typecho配置
    $configPaths = [
        dirname(__FILE__) . '/../../config.inc.php',
        dirname(__FILE__) . '/../../../config.inc.php',
        dirname(__FILE__) . '/../../../../config.inc.php'
    ];

    $configFound = false;
    foreach ($configPaths as $configPath) {
        if (file_exists($configPath)) {
            include_once $configPath;
            $configFound = true;
            break;
        }
    }

    if ($configFound) {
        echo "   ✓ Typecho配置文件找到\n";
        // 检查是否定义了数据库常量
        if (defined('__TYPECHO_DB_HOST__')) {
            $dbHost = constant('__TYPECHO_DB_HOST__');
            if (!empty($dbHost)) {
                echo "   ✓ 数据库配置: " . $dbHost . "\n";
            } else {
                echo "   ⚠ 数据库配置常量为空\n";
            }
        } else {
            echo "   ⚠ 数据库配置常量未定义\n";
        }
    } else {
        echo "   ⚠ 无法找到Typecho配置文件\n";
    }
} catch (Exception $e) {
    echo "   ⚠ 数据库连接检查失败: " . $e->getMessage() . "\n";
}

// 文件权限检查
echo "\n4. 文件权限检查:\n";
$pluginDir = dirname(__FILE__);
if (is_readable($pluginDir)) {
    echo "   ✓ 插件目录可读\n";
} else {
    echo "   ✗ 插件目录不可读\n";
}

if (is_writable($pluginDir)) {
    echo "   ✓ 插件目录可写（用于日志）\n";
} else {
    echo "   ⚠ 插件目录不可写（无法记录日志）\n";
}

// 内存和执行时间检查
echo "\n5. 系统资源检查:\n";
$memoryLimit = ini_get('memory_limit');
echo "   内存限制: {$memoryLimit}\n";

$maxExecutionTime = ini_get('max_execution_time');
echo "   最大执行时间: {$maxExecutionTime}秒\n";

// 网络功能检查
echo "\n6. 网络功能检查:\n";
if (function_exists('curl_init')) {
    echo "   ✓ cURL支持（地理位置检测需要）\n";
} else {
    echo "   ✗ cURL不支持（无法使用地理位置检测）\n";
}

if (function_exists('file_get_contents')) {
    echo "   ✓ file_get_contents支持\n";
} else {
    echo "   ✗ file_get_contents不支持\n";
}

// 安全功能检查
echo "\n7. 安全功能检查:\n";
if (function_exists('filter_var')) {
    echo "   ✓ IP地址验证功能可用\n";
} else {
    echo "   ✗ IP地址验证功能不可用\n";
}

if (function_exists('ip2long')) {
    echo "   ✓ IP地址转换功能可用\n";
} else {
    echo "   ✗ IP地址转换功能不可用\n";
}

// 给出建议
echo "\n=====================================\n";
echo "安装建议:\n";
echo "1. 确保所有必需的PHP扩展都已安装\n";
echo "2. 建议PHP内存限制至少128MB\n";
echo "3. 插件目录需要适当的读写权限\n";
echo "4. 启用插件前请备份网站和数据库\n";
echo "5. 推荐使用智能模式（黑白名单同时生效）\n";
echo "6. 将自己的IP添加到白名单中以防意外锁定\n";

// 快速配置示例
echo "\n快速配置示例:\n";
echo "1. 启用插件后，默认已选择'智能模式'\n";
$clientIP = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '未知');
echo "2. 在白名单中添加您的IP: " . $clientIP . "\n";
echo "3. 黑名单支持多种格式:\n";
echo "   - 单个IP: 192.168.1.100\n";
echo "   - 单个通配符: 192.168.1.*\n";
echo "   - 多个通配符: 113.215.*.* 或 192.168.*.*\n";
echo "   - 三个通配符: 10.*.*.* 或 172.*.*.*\n";
echo "   - IP范围: 192.168.1.1-50\n";
echo "   - CIDR: 192.168.1.0/24\n";
echo "4. 设置访问间隔限制: 10秒\n";
echo "5. 智能检测会自动将威胁IP加入黑名单\n";

echo "\n检查完成！\n";

// 如果是Web访问，输出HTML格式
if (php_sapi_name() !== 'cli') {
    $output = ob_get_clean();
    echo '<html><head><meta charset="UTF-8"><title>插件安装检查</title></head><body>';
    echo '<pre>' . htmlspecialchars($output) . '</pre>';
    echo '</body></html>';
}
