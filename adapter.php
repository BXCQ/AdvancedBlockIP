<?php
/**
 * 适配器文件 - 用于处理Typecho新旧版本的类名兼容性
 */

// 如果未定义Typecho根目录，则退出
if (!defined('__TYPECHO_ROOT_DIR__') && !defined('__TYPECHO_ADMIN__')) {
    exit;
}

// 只创建控制台页面需要的类别名，避免重复声明
if (!class_exists('Typecho_Db')) {
    class_alias('Typecho\\Db', 'Typecho_Db');
}

if (!class_exists('Typecho_Request')) {
    class_alias('Typecho\\Request', 'Typecho_Request');
}

if (!class_exists('Widget_Options')) {
    class_alias('Typecho\\Widget\\Options', 'Widget_Options');
}

if (!class_exists('Plugin')) {
    class_alias('Typecho\\Plugin', 'Plugin');
}

// 直接实现Helper类，而不是创建别名
if (!class_exists('Helper')) {
    class Helper
    {
        public static function options()
        {
            if (class_exists('\\Typecho\\Helper')) {
                $class = '\\Typecho\\Helper';
                return $class::options();
            } else if (class_exists('\\Typecho\\Common')) {
                $class = '\\Typecho\\Common';
                if (method_exists($class, 'options')) {
                    return $class::options();
                }
            }
            return null;
        }

        public static function addAction($action, $className)
        {
            if (class_exists('\\Typecho\\Plugin\\Helper')) {
                $class = '\\Typecho\\Plugin\\Helper';
                if (method_exists($class, 'addAction')) {
                    return $class::addAction($action, $className);
                }
            }
            return false;
        }

        public static function removeAction($action)
        {
            if (class_exists('\\Typecho\\Plugin\\Helper')) {
                $class = '\\Typecho\\Plugin\\Helper';
                if (method_exists($class, 'removeAction')) {
                    return $class::removeAction($action);
                }
            }
            return false;
        }

        public static function addPanel($group, $fileName, $title, $description, $permission = null)
        {
            if (class_exists('\\Typecho\\Plugin\\Helper')) {
                $class = '\\Typecho\\Plugin\\Helper';
                if (method_exists($class, 'addPanel')) {
                    return $class::addPanel($group, $fileName, $title, $description, $permission);
                }
            }
            return false;
        }

        public static function removePanel($group, $fileName)
        {
            if (class_exists('\\Typecho\\Plugin\\Helper')) {
                $class = '\\Typecho\\Plugin\\Helper';
                if (method_exists($class, 'removePanel')) {
                    return $class::removePanel($group, $fileName);
                }
            }
            return false;
        }
    }
}

// 定义常量，确保兼容性
if (!defined('Typecho_Db::SORT_ASC')) {
    define('Typecho_Db::SORT_ASC', 'ASC');
}

if (!defined('Typecho_Db::SORT_DESC')) {
    define('Typecho_Db::SORT_DESC', 'DESC');
} 