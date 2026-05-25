<?php

class ContentStore {
    private static $tableEnsured = false;

    // NOTE: schema changes to this table will NOT propagate to existing installs.
    // `CREATE TABLE IF NOT EXISTS` is a no-op once the table exists, so any column
    // added below silently fails to appear in upgraded deployments. Before changing
    // the schema, an in-plugin migration runner must be built. See README "Limitations".
    private static function createTableSql(): string {
        $prefix = TABLE_PREFIX;
        return "CREATE TABLE IF NOT EXISTS `{$prefix}ai_crawler_content` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `instance_id` INT NOT NULL,
            `url` VARCHAR(500) NOT NULL,
            `title` VARCHAR(255) DEFAULT '',
            `content` MEDIUMTEXT,
            `summary` MEDIUMTEXT DEFAULT NULL,
            `crawled_at` DATETIME NOT NULL,
            `depth` INT DEFAULT 0,
            `status` ENUM('active','error') DEFAULT 'active',
            UNIQUE KEY `url_instance` (`url`(191), `instance_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    }

    public static function ensureTable(): void {
        if (self::$tableEnsured) {
            return;
        }

        $res = db_query(self::createTableSql());
        if ($res) {
            self::$tableEnsured = true;
        }
    }

    /**
     * Store a crawled page.
     */
    public function store(
        int $instanceId,
        string $url,
        string $title,
        string $content,
        int $depth = 0,
        string $status = 'active',
        string $summary = ''
    ): bool {
        self::ensureTable();

        $prefix = TABLE_PREFIX;
        $sql = "INSERT INTO `{$prefix}ai_crawler_content`
                (`instance_id`, `url`, `title`, `content`, `summary`, `crawled_at`, `depth`, `status`)
                VALUES (%d, %s, %s, %s, %s, NOW(), %d, %s)
                ON DUPLICATE KEY UPDATE
                    `title` = VALUES(`title`),
                    `content` = VALUES(`content`),
                    `summary` = VALUES(`summary`),
                    `crawled_at` = NOW(),
                    `depth` = VALUES(`depth`),
                    `status` = VALUES(`status`)";

        $result = db_query(sprintf(
            $sql,
            $instanceId,
            db_input($url),
            db_input($title),
            db_input($content),
            db_input($summary),
            $depth,
            db_input($status)
        ), false);

        if (!$result) {
            $err = function_exists('db_error') ? db_error() : 'unknown';
            error_log("AI Response Suggester: store() failed for URL {$url} — DB error: {$err}");
        }

        return (bool) $result;
    }

    /**
     * Update only the summary for an existing page.
     */
    public function updateSummary(int $id, int $instanceId, string $summary): bool {
        $prefix = TABLE_PREFIX;
        return (bool) db_query(sprintf(
            "UPDATE `{$prefix}ai_crawler_content`
             SET `summary` = %s
             WHERE `id` = %d AND `instance_id` = %d",
            db_input($summary),
            $id,
            $instanceId
        ));
    }

    /**
     * Get concatenated content for AI context, limited by character count.
     * Prefers summary over raw content when available.
     */
    public function getContent(int $instanceId, int $charLimit = 30000): string {
        $prefix = TABLE_PREFIX;
        $sql = sprintf(
            "SELECT `title`, COALESCE(NULLIF(`summary`, ''), `content`) AS `text`
             FROM `{$prefix}ai_crawler_content`
             WHERE `instance_id` = %d AND `status` = 'active'
             ORDER BY `depth` ASC, `id` ASC",
            $instanceId
        );

        $result = db_query($sql);
        if (!$result) {
            return '';
        }

        $output = '';
        $totalLen = 0;

        while ($row = db_fetch_array($result)) {
            $chunk = '';
            if ($row['title']) {
                $chunk .= "## " . $row['title'] . "\n";
            }
            $chunk .= $row['text'] . "\n\n";

            if ($totalLen + strlen($chunk) > $charLimit) {
                $remaining = $charLimit - $totalLen;
                if ($remaining > 100) {
                    $output .= substr($chunk, 0, $remaining) . "\n... (truncated)";
                }
                break;
            }

            $output .= $chunk;
            $totalLen += strlen($chunk);
        }

        return $output;
    }

    /**
     * Get all stored pages for admin viewing.
     */
    public function getAll(int $instanceId): array {
        $prefix = TABLE_PREFIX;
        $sql = sprintf(
            "SELECT `id`, `url`, `title`,
                    LEFT(`content`, 200) AS `content_preview`,
                    LEFT(`summary`, 200) AS `summary_preview`,
                    `crawled_at`, `depth`, `status`
             FROM `{$prefix}ai_crawler_content`
             WHERE `instance_id` = %d
             ORDER BY `depth` ASC, `id` ASC",
            $instanceId
        );

        $result = db_query($sql);
        if (!$result) {
            return array();
        }

        $pages = array();
        while ($row = db_fetch_array($result)) {
            $pages[] = $row;
        }
        return $pages;
    }

    /**
     * Get statistics about crawled content.
     */
    public function getStats(int $instanceId): array {
        $prefix = TABLE_PREFIX;
        $sql = sprintf(
            "SELECT COUNT(*) as `count`, MAX(`crawled_at`) as `last_crawled`
             FROM `{$prefix}ai_crawler_content`
             WHERE `instance_id` = %d AND `status` = 'active'",
            $instanceId
        );

        $result = db_query($sql);
        if (!$result) {
            return array('count' => 0, 'last_crawled' => null);
        }

        $row = db_fetch_array($result);
        return array(
            'count' => (int) ($row['count'] ?? 0),
            'last_crawled' => $row['last_crawled'] ?? null,
        );
    }

    /**
     * Remove all crawled content for a plugin instance.
     */
    public function clear(int $instanceId): bool {
        $prefix = TABLE_PREFIX;
        db_query("DROP TABLE IF EXISTS `{$prefix}ai_crawler_content`", false);
        self::$tableEnsured = false;
        return (bool) db_query(self::createTableSql());
    }

    /**
     * Delete a single crawled page by ID.
     */
    public function delete(int $id, int $instanceId): bool {
        $prefix = TABLE_PREFIX;
        return (bool) db_query(sprintf(
            "DELETE FROM `{$prefix}ai_crawler_content` WHERE `id` = %d AND `instance_id` = %d",
            $id,
            $instanceId
        ));
    }
}
