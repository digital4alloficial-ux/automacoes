<?php
declare(strict_types=1);
namespace Digital4All;

final class LocalAuth
{
    public function __construct(private \PDO $db, private string $key) {}
    private function query(string $sql, array $args = []): \PDOStatement
    {
        $s = $this->db->prepare($sql); $s->execute($args); return $s;
    }
    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) && !preg_match('/[^\x20-\x7e]/', $email) ? $email : '';
    }
    /** Persistent throttling across sessions/workers. No raw email/IP in buckets. */
    public function login(string $email, string $password, string $ip): ?array
    {
        $email = self::email($email);
        $buckets = [hash_hmac('sha256', 'email:' . $email, $this->key) => 8,
                    hash_hmac('sha256', 'ip:' . $ip, $this->key) => 40];
        ksort($buckets); // Stable order avoids deadlocks.
        $this->db->beginTransaction();
        try {
            foreach ($buckets as $bucket => $limit) {
                $this->query('INSERT IGNORE INTO login_limits (bucket,window_start) VALUES (?,?)', [$bucket,time()]);
                $row = $this->query('SELECT * FROM login_limits WHERE bucket = ? FOR UPDATE', [$bucket])->fetch(\PDO::FETCH_ASSOC);
                $count = (int)$row['attempts'];
                if ((int)$row['window_start'] <= time()-900) { $count = 0; }
                if ($count >= $limit) { $this->db->commit(); return null; }
                $this->query('UPDATE login_limits SET window_start = ?, attempts = ? WHERE bucket = ?', [$count === 0 ? time() : $row['window_start'], $count+1,$bucket]);
            }
            $user = $this->query('SELECT id,password_hash,active,session_version FROM app_users WHERE email = ?', [$email])->fetch(\PDO::FETCH_ASSOC);
            // Valid bcrypt dummy avoids an obvious unknown-account timing shortcut.
            $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valid = strlen($password) <= 72 && password_verify($password,$hash);
            $this->db->commit();
            if (!$user || !$valid || (int)$user['active'] !== 1) { return null; }
            return ['id'=>(int)$user['id'],'version'=>(int)$user['session_version']];
        } catch (\Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }
    public function userId(array $session): ?int
    {
        if (!is_int($session['user_id'] ?? null) || !is_int($session['auth_started'] ?? null) || !is_int($session['auth_seen'] ?? null) ||
            $session['auth_started'] < time()-28800 || $session['auth_seen'] < time()-1800) { return null; }
        $u=$this->query('SELECT active,session_version FROM app_users WHERE id = ?',[$session['user_id']])->fetch(\PDO::FETCH_ASSOC);
        return $u && (int)$u['active'] === 1 && (int)$u['session_version'] === ($session['auth_version'] ?? null) ? $session['user_id'] : null;
    }
    public function companies(int $user): array
    {
        return $this->query('SELECT c.id,c.name FROM companies c JOIN company_users m ON m.company_id=c.id WHERE m.user_id=? AND m.can_manage_bling=1 ORDER BY c.name',[$user])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function identity(array $session): ?array
    {
        $user=$this->userId($session); $company=$session['company_id'] ?? null;
        if ($user === null || !is_int($company)) { return null; }
        $ok=$this->query('SELECT 1 FROM company_users WHERE user_id=? AND company_id=? AND can_manage_bling=1',[$user,$company])->fetchColumn();
        return $ok ? ['principal_id'=>(string)$user,'company_id'=>$company] : null;
    }
}
