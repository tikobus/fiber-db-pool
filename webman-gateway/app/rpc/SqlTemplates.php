<?php

declare(strict_types=1);

namespace app\rpc;

use RuntimeException;

/**
 * SQL 模板白名单（安全红线：前端不直接下发 SQL）。
 *
 * 前端只传 模板名 + 参数，SQL 由网关代码硬编码；
 * resolve() 只接受白名单内的模板名，模板名带任何越权 SQL 一律拒绝。
 */
final class SqlTemplates
{
    /**
     * 模板注册表：模板名 => SQL（全部预编译绑定，禁止字符串拼接）
     */
    private const TEMPLATES = [
        // 用户表
        'user.get_by_id'        => 'SELECT id, name, created_at FROM rpc_test_user WHERE id = ?',
        'user.get_by_name'      => 'SELECT id, name, created_at FROM rpc_test_user WHERE name = ? LIMIT 1',
        'user.list'             => 'SELECT id, name, created_at FROM rpc_test_user WHERE id > ? ORDER BY id LIMIT ?',
        'user.count_by_name'    => 'SELECT COUNT(*) AS cnt FROM rpc_test_user WHERE name = ?',
        'user.create'           => 'INSERT INTO rpc_test_user(name) VALUES (?)',
        'user.rename'           => 'UPDATE rpc_test_user SET name = ? WHERE id = ?',
        'user.delete'           => 'DELETE FROM rpc_test_user WHERE id = ?',

        // 压测/健康检查
        'sys.ping'              => 'SELECT 1 AS ok',
    ];

    /**
     * @param string $name 模板名
     * @return string SQL 模板
     * @throws RuntimeException 模板不存在
     */
    public static function resolve(string $name): string
    {
        // 白名单严格匹配：模板名仅允许 [a-z0-9._]，防注入
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $name)) {
            throw new RuntimeException("invalid template name: {$name}");
        }
        if (!isset(self::TEMPLATES[$name])) {
            throw new RuntimeException("template not found: {$name}");
        }
        return self::TEMPLATES[$name];
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::TEMPLATES;
    }
}
