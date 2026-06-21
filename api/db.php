<?php
/**
 * 数据库连接类
 */
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            json_response(500, ['error' => '数据库连接失败: ' . $e->getMessage()]);
            exit;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    // 快捷方法
    public static function query($sql, $params = []) {
        $pdo = self::getInstance()->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne($sql, $params = []) {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    public static function insert($table, $data) {
        $pdo = self::getInstance()->getPdo();
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            TABLE_PREFIX . $table,
            implode(', ', $fields),
            implode(', ', $placeholders)
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return $pdo->lastInsertId();
    }

    public static function update($table, $data, $where, $whereParams = []) {
        $pdo = self::getInstance()->getPdo();
        $setParts = [];
        $setValues = [];
        foreach ($data as $field => $value) {
            $setParts[] = $field . ' = ?';
            $setValues[] = $value;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            TABLE_PREFIX . $table,
            implode(', ', $setParts),
            $where
        );
        $params = array_merge($setValues, $whereParams);
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public static function delete($table, $where, $params = []) {
        $sql = sprintf('DELETE FROM %s WHERE %s', TABLE_PREFIX . $table, $where);
        return self::query($sql, $params)->rowCount();
    }

    public static function count($table, $where = '1=1', $params = []) {
        $sql = sprintf('SELECT COUNT(*) as cnt FROM %s WHERE %s', TABLE_PREFIX . $table, $where);
        $result = self::fetchOne($sql, $params);
        return (int)($result['cnt'] ?? 0);
    }
}
