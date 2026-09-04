<?php
/**
 * Database Structure Sync — export, compare, and safely apply schema differences.
 * Structure only. Never deletes tables, columns, views, or data.
 */

class DatabaseStructureSync
{
    private PDO $conn;
    private string $database;
    private array $warnings = [];

    public function __construct(PDO $conn, string $database)
    {
        $this->conn = $conn;
        $this->database = $database;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public static function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /* ------------------------------------------------------------------ */
    /* Export / introspect                                                */
    /* ------------------------------------------------------------------ */

    public function exportSchema(): array
    {
        $this->warnings = [];
        $version = $this->conn->query('SELECT VERSION()')->fetchColumn();

        $schema = [
            'meta' => [
                'exported_at' => gmdate('c'),
                'database' => $this->database,
                'server_version' => (string)$version,
                'tool_version' => '1.0.0',
                'warnings' => [],
            ],
            'inventory' => [],
            'tables' => [],
            'views' => [],
            'procedures' => [],
            'functions' => [],
            'triggers' => [],
            'events' => [],
        ];

        $schema['tables'] = $this->collectTables();
        $schema['views'] = $this->collectViews();
        $schema['triggers'] = $this->collectTriggers();
        $schema['events'] = $this->collectEvents();
        $routines = $this->collectRoutines();
        $schema['procedures'] = $routines['procedures'];
        $schema['functions'] = $routines['functions'];

        $schema['inventory'] = [
            'tables' => count($schema['tables']),
            'views' => count($schema['views']),
            'triggers' => count($schema['triggers']),
            'procedures' => count($schema['procedures']),
            'functions' => count($schema['functions']),
            'events' => count($schema['events']),
            'foreign_keys' => $this->countForeignKeys(),
        ];

        $schema['meta']['warnings'] = $this->warnings;
        return $schema;
    }

    public function exportToFile(string $path): array
    {
        $schema = $this->exportSchema();
        self::ensureWritablePath($path);

        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode schema JSON.');
        }

        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException(
                'Failed to write schema snapshot (permission denied). Path: ' . $path
                . ' — ensure the directory is writable by the web server, e.g. chmod 775 storage/schema_snapshots'
            );
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to finalize schema snapshot: ' . $path);
        }
        @chmod($path, 0664);

        return [
            'path' => $path,
            'bytes' => strlen($json),
            'inventory' => $schema['inventory'],
            'warnings' => $schema['meta']['warnings'],
        ];
    }

    public static function defaultSnapshotPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/storage/schema_snapshots/schema_snapshot_local.json';
    }

    public static function legacySnapshotPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/database/schema_snapshot_local.json';
    }

    /** Resolve snapshot file — prefers storage/, falls back to database/ for older exports. */
    public static function resolveSnapshotPath(string $projectRoot): string
    {
        $primary = self::defaultSnapshotPath($projectRoot);
        if (is_file($primary)) {
            return $primary;
        }
        $legacy = self::legacySnapshotPath($projectRoot);
        if (is_file($legacy)) {
            return $legacy;
        }
        return $primary;
    }

    public static function ensureWritablePath(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create directory: ' . $dir);
            }
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0775);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException(
                'Directory is not writable: ' . $dir
                . '. Run: chmod 775 ' . $dir . ' (or chmod 777 on localhost XAMPP).'
            );
        }
        if (is_file($path) && !is_writable($path)) {
            @chmod($path, 0664);
        }
        if (is_file($path) && !is_writable($path)) {
            @unlink($path);
        }
    }

    public static function loadSnapshot(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Schema snapshot not found: ' . $path);
        }
        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid schema snapshot JSON.');
        }
        return $data;
    }

    public static function loadSnapshotSummary(string $path): array
    {
        $data = self::loadSnapshot($path);
        return [
            'meta' => $data['meta'] ?? [],
            'inventory' => $data['inventory'] ?? [],
        ];
    }

    public function introspectLive(): array
    {
        return $this->introspectForCompare();
    }

    /** Lightweight live introspection for compare (skips SHOW CREATE TABLE per table). */
    public function introspectForCompare(): array
    {
        $this->warnings = [];
        $version = $this->conn->query('SELECT VERSION()')->fetchColumn();

        $schema = [
            'meta' => [
                'exported_at' => gmdate('c'),
                'database' => $this->database,
                'server_version' => (string)$version,
                'tool_version' => '1.0.0',
                'introspect_mode' => 'compare_lite',
                'warnings' => [],
            ],
            'inventory' => [],
            'tables' => $this->collectTablesLite(),
            'views' => $this->collectViewsLite(),
            'procedures' => [],
            'functions' => [],
            'triggers' => $this->collectTriggersLite(),
            'events' => $this->collectEventsLite(),
        ];

        $routines = $this->collectRoutinesLite();
        $schema['procedures'] = $routines['procedures'];
        $schema['functions'] = $routines['functions'];

        $schema['inventory'] = [
            'tables' => count($schema['tables']),
            'views' => count($schema['views']),
            'triggers' => count($schema['triggers']),
            'procedures' => count($schema['procedures']),
            'functions' => count($schema['functions']),
            'events' => count($schema['events']),
            'foreign_keys' => $this->countForeignKeys(),
        ];

        $schema['meta']['warnings'] = $this->warnings;
        return $schema;
    }

    public static function defaultPlanCachePath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/storage/schema_snapshots/sync_plan_preview.json';
    }

    public static function planCachePathForSnapshot(string $snapshotPath): string
    {
        return dirname($snapshotPath) . '/sync_plan_preview.json';
    }

    public static function savePlanCache(string $path, array $payload): void
    {
        self::ensureWritablePath($path);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode plan cache JSON.');
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write plan cache: ' . $path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to finalize plan cache: ' . $path);
        }
        @chmod($path, 0664);
    }

    public static function loadPlanCache(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    public static function loadCachedPlan(string $snapshotPath): ?array
    {
        $cachePath = self::planCachePathForSnapshot($snapshotPath);
        if (!is_file($cachePath)) {
            return null;
        }
        $cached = self::loadPlanCache($cachePath);
        if (!$cached || !is_array($cached['plan'] ?? null)) {
            return null;
        }
        $snapshotMtime = is_file($snapshotPath) ? (int)filemtime($snapshotPath) : 0;
        if ((int)($cached['snapshot_mtime'] ?? 0) !== $snapshotMtime) {
            return null;
        }
        return [
            'plan' => $cached['plan'],
            'local' => $cached['local'] ?? [],
            'live' => $cached['live'] ?? [],
            'local_meta' => $cached['local_meta'] ?? ($cached['local']['meta'] ?? []),
            'live_inventory' => $cached['live_inventory'] ?? ($cached['live']['inventory'] ?? []),
            'from_cache' => true,
            'elapsed' => (float)($cached['elapsed_seconds'] ?? 0),
            'cache_generated_at' => $cached['generated_at'] ?? null,
        ];
    }

    /** Quick live inventory counts (no per-table drill-down). */
    public function fetchLiveInventoryCounts(): array
    {
        $stmt = $this->conn->prepare("
            SELECT TABLE_TYPE, COUNT(*) AS cnt
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            GROUP BY TABLE_TYPE
        ");
        $stmt->execute([$this->database]);
        $counts = ['tables' => 0, 'views' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['TABLE_TYPE'] === 'BASE TABLE') {
                $counts['tables'] = (int)$row['cnt'];
            } elseif ($row['TABLE_TYPE'] === 'VIEW') {
                $counts['views'] = (int)$row['cnt'];
            }
        }
        $counts['foreign_keys'] = $this->countForeignKeys();
        $counts['triggers'] = 0;
        $counts['procedures'] = 0;
        $counts['functions'] = 0;
        $counts['events'] = 0;
        try {
            $t = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?");
            $t->execute([$this->database]);
            $counts['triggers'] = (int)$t->fetchColumn();
        } catch (Throwable $e) {
        }
        return $counts;
    }

    /** @return array{plan:array,local:array,live:array,local_meta:array,live_inventory:array,from_cache:bool,elapsed:float,cache_generated_at?:string} */
    public function buildComparePlan(string $snapshotPath, bool $forceRefresh = false, ?string $cachePath = null): array
    {
        $cachePath = $cachePath ?? self::planCachePathForSnapshot($snapshotPath);
        $snapshotMtime = is_file($snapshotPath) ? (int)filemtime($snapshotPath) : 0;

        if (!$forceRefresh) {
            $cached = self::loadCachedPlan($snapshotPath);
            if ($cached) {
                return $cached;
            }
        }

        $started = microtime(true);
        $local = self::loadSnapshot($snapshotPath);
        $live = $this->introspectForCompare();
        $plan = $this->compare($local, $live);
        $elapsed = round(microtime(true) - $started, 2);

        self::savePlanCache($cachePath, [
            'generated_at' => gmdate('c'),
            'snapshot_mtime' => $snapshotMtime,
            'snapshot_path' => $snapshotPath,
            'elapsed_seconds' => $elapsed,
            'local_meta' => $local['meta'] ?? [],
            'live_inventory' => $live['inventory'] ?? [],
            'local' => ['meta' => $local['meta'] ?? [], 'inventory' => $local['inventory'] ?? []],
            'live' => ['meta' => $live['meta'] ?? [], 'inventory' => $live['inventory'] ?? []],
            'plan' => $plan,
        ]);

        return [
            'plan' => $plan,
            'local' => $local,
            'live' => $live,
            'local_meta' => $local['meta'] ?? [],
            'live_inventory' => $live['inventory'] ?? [],
            'from_cache' => false,
            'elapsed' => $elapsed,
            'cache_generated_at' => gmdate('c'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Compare                                                            */
    /* ------------------------------------------------------------------ */

    public function compare(array $local, array $live): array
    {
        $plan = [
            'safe' => [],
            'manual_review' => [],
            'skipped' => [],
            'summary' => [
                'missing_tables' => 0,
                'missing_columns' => 0,
                'safe_column_changes' => 0,
                'missing_indexes' => 0,
                'missing_foreign_keys' => 0,
                'missing_views' => 0,
                'changed_views' => 0,
                'missing_triggers' => 0,
                'changed_triggers' => 0,
                'missing_procedures' => 0,
                'changed_procedures' => 0,
                'missing_functions' => 0,
                'changed_functions' => 0,
                'missing_events' => 0,
                'changed_events' => 0,
                'manual_review_count' => 0,
            ],
        ];

        $localTables = $local['tables'] ?? [];
        $liveTables = $live['tables'] ?? [];

        foreach ($localTables as $tableName => $localTable) {
            if (!isset($liveTables[$tableName])) {
                $sql = self::sanitizeCreateStatement($localTable['create_sql'] ?? '');
                if ($sql === '') {
                    $plan['manual_review'][] = $this->item('table', 'create', $tableName, '', 'Missing CREATE TABLE statement in snapshot.');
                    continue;
                }
                if (!preg_match('/IF NOT EXISTS/i', $sql)) {
                    $sql = preg_replace('/^CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $sql, 1);
                }
                $plan['safe'][] = $this->item('table', 'create', $tableName, $sql, 'Missing table on live.');
                $plan['summary']['missing_tables']++;
                continue;
            }

            $liveTable = $liveTables[$tableName];
            $this->compareTableMeta($tableName, $localTable, $liveTable, $plan);
            $this->compareColumns($tableName, $localTable['columns'] ?? [], $liveTable['columns'] ?? [], $plan);
            $this->compareIndexes($tableName, $localTable['indexes'] ?? [], $liveTable['indexes'] ?? [], $plan);
            $this->comparePrimaryKey($tableName, $localTable, $liveTable, $plan);
            $this->compareForeignKeys($tableName, $localTable['foreign_keys'] ?? [], $liveTable['foreign_keys'] ?? [], $plan);
        }

        $this->compareViews($local['views'] ?? [], $live['views'] ?? [], $plan);
        $this->compareTriggers($local['triggers'] ?? [], $live['triggers'] ?? [], $plan);
        $this->compareRoutines('procedure', $local['procedures'] ?? [], $live['procedures'] ?? [], $plan);
        $this->compareRoutines('function', $local['functions'] ?? [], $live['functions'] ?? [], $plan);
        $this->compareEvents($local['events'] ?? [], $live['events'] ?? [], $plan);

        $plan['summary']['manual_review_count'] = count($plan['manual_review']);
        return $plan;
    }

    /* ------------------------------------------------------------------ */
    /* Apply                                                              */
    /* ------------------------------------------------------------------ */

    public function applyPlan(array $plan, bool $previewOnly = true): array
    {
        $result = [
            'preview_only' => $previewOnly,
            'executed' => [],
            'failed' => [],
            'skipped' => $plan['skipped'] ?? [],
            'manual_review' => $plan['manual_review'] ?? [],
            'log_lines' => [],
        ];

        if ($previewOnly) {
            $result['log_lines'][] = 'Preview mode — no SQL executed.';
            return $result;
        }

        $order = ['table' => 1, 'column' => 2, 'index' => 3, 'foreign_key' => 4, 'view' => 5, 'trigger' => 6, 'procedure' => 7, 'function' => 8];
        $safe = $plan['safe'] ?? [];
        usort($safe, function ($a, $b) use ($order) {
            $oa = $order[$a['category'] ?? ''] ?? 99;
            $ob = $order[$b['category'] ?? ''] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            // Same table: apply columns in object order (table.col) for stable ADD COLUMN sequencing
            $aObj = $a['object'] ?? '';
            $bObj = $b['object'] ?? '';
            $aTable = str_contains($aObj, '.') ? strstr($aObj, '.', true) : $aObj;
            $bTable = str_contains($bObj, '.') ? strstr($bObj, '.', true) : $bObj;
            if ($aTable !== $bTable) {
                return strcmp($aObj, $bObj);
            }
            return strcmp($aObj, $bObj);
        });

        foreach ($safe as $entry) {
            $sql = trim($entry['sql'] ?? '');
            if ($sql === '') {
                continue;
            }
            try {
                $this->conn->exec($sql);
                $result['executed'][] = $entry;
                $result['log_lines'][] = 'OK [' . ($entry['category'] ?? '') . '] ' . ($entry['object'] ?? '') . ': ' . self::oneLine($sql);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (self::isAlreadyAppliedError($msg)) {
                    $entry['note'] = 'Already applied — treated as success.';
                    $result['executed'][] = $entry;
                    $result['log_lines'][] = 'SKIP (exists) [' . ($entry['category'] ?? '') . '] ' . ($entry['object'] ?? '') . ': ' . $msg;
                    continue;
                }
                $entry['error'] = $msg;
                $result['failed'][] = $entry;
                $result['log_lines'][] = 'FAIL [' . ($entry['category'] ?? '') . '] ' . ($entry['object'] ?? '') . ': ' . $msg;
            }
        }

        return $result;
    }

    public static function writeLogFile(string $logsDir, array $plan, array $applyResult): string
    {
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        $filename = 'database_structure_sync_' . date('Ymd_His') . '.log';
        $path = rtrim($logsDir, '/') . '/' . $filename;

        $lines = [];
        $lines[] = 'Database Structure Sync Log';
        $lines[] = 'Generated: ' . date('Y-m-d H:i:s');
        $lines[] = 'Mode: ' . (!empty($applyResult['preview_only']) ? 'PREVIEW' : 'APPLY');
        $lines[] = str_repeat('-', 72);
        $lines[] = 'Summary: ' . json_encode($plan['summary'] ?? [], JSON_UNESCAPED_UNICODE);
        $lines[] = '';

        $lines[] = '=== SAFE CHANGES (planned) ===';
        foreach ($plan['safe'] ?? [] as $e) {
            $lines[] = sprintf('[%s] %s — %s', $e['category'] ?? '', $e['object'] ?? '', $e['reason'] ?? '');
            $lines[] = $e['sql'] ?? '';
            $lines[] = '';
        }

        $lines[] = '=== EXECUTED ===';
        foreach ($applyResult['executed'] ?? [] as $e) {
            $lines[] = sprintf('OK [%s] %s', $e['category'] ?? '', $e['object'] ?? '');
        }

        $lines[] = '';
        $lines[] = '=== FAILED ===';
        foreach ($applyResult['failed'] ?? [] as $e) {
            $lines[] = sprintf('FAIL [%s] %s — %s', $e['category'] ?? '', $e['object'] ?? '', $e['error'] ?? '');
        }

        $lines[] = '';
        $lines[] = '=== MANUAL REVIEW REQUIRED ===';
        foreach ($plan['manual_review'] ?? [] as $e) {
            $lines[] = sprintf('[%s] %s — %s', $e['category'] ?? '', $e['object'] ?? '', $e['reason'] ?? '');
            if (!empty($e['sql'])) {
                $lines[] = $e['sql'];
            }
            $lines[] = '';
        }

        $lines[] = '=== SKIPPED ===';
        foreach ($plan['skipped'] ?? [] as $e) {
            $lines[] = sprintf('[%s] %s — %s', $e['category'] ?? '', $e['object'] ?? '', $e['reason'] ?? '');
        }

        if (!empty($applyResult['log_lines'])) {
            $lines[] = '';
            $lines[] = '=== RUN LOG ===';
            foreach ($applyResult['log_lines'] as $line) {
                $lines[] = $line;
            }
        }

        file_put_contents($path, implode("\n", $lines));
        return $path;
    }

    /* ------------------------------------------------------------------ */
    /* Collectors                                                         */
    /* ------------------------------------------------------------------ */

    private function collectTables(): array
    {
        $tables = [];
        $stmt = $this->conn->prepare("
            SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, TABLE_COMMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $name = $row['TABLE_NAME'];
            $create = $this->showCreate('TABLE', $name);
            $indexes = $this->collectIndexes($name);
            $tables[$name] = [
                'engine' => $row['ENGINE'],
                'collation' => $row['TABLE_COLLATION'],
                'auto_increment' => $row['AUTO_INCREMENT'],
                'comment' => $row['TABLE_COMMENT'],
                'create_sql' => $create,
                'columns' => $this->collectColumns($name),
                'indexes' => $indexes,
                'foreign_keys' => $this->collectForeignKeys($name),
                'primary_key' => $indexes['PRIMARY'] ?? null,
            ];
        }
        return $tables;
    }

    /** Compare-only table metadata (batched queries — fast on shared hosting). */
    private function collectTablesLite(): array
    {
        $tables = [];
        $stmt = $this->conn->prepare("
            SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, TABLE_COMMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $allColumns = $this->collectAllColumnsGrouped();
        $allIndexes = $this->collectAllIndexesGrouped();
        $allForeignKeys = $this->collectAllForeignKeysGrouped();

        foreach ($rows as $row) {
            $name = $row['TABLE_NAME'];
            $indexes = $allIndexes[$name] ?? [];
            $tables[$name] = [
                'engine' => $row['ENGINE'],
                'collation' => $row['TABLE_COLLATION'],
                'auto_increment' => $row['AUTO_INCREMENT'],
                'comment' => $row['TABLE_COMMENT'],
                'columns' => $allColumns[$name] ?? [],
                'indexes' => $indexes,
                'foreign_keys' => $allForeignKeys[$name] ?? [],
                'primary_key' => $indexes['PRIMARY'] ?? null,
            ];
        }
        return $tables;
    }

    private function collectAllColumnsGrouped(): array
    {
        $stmt = $this->conn->prepare("
            SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, DATA_TYPE,
                   CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                   IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME,
                   COLUMN_COMMENT, GENERATION_EXPRESSION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ");
        $stmt->execute([$this->database]);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = $row['TABLE_NAME'];
            $grouped[$table][$row['COLUMN_NAME']] = [
                'name' => $row['COLUMN_NAME'],
                'ordinal' => (int)$row['ORDINAL_POSITION'],
                'column_type' => $row['COLUMN_TYPE'],
                'data_type' => strtolower($row['DATA_TYPE']),
                'char_max_length' => $row['CHARACTER_MAXIMUM_LENGTH'],
                'numeric_precision' => $row['NUMERIC_PRECISION'],
                'numeric_scale' => $row['NUMERIC_SCALE'],
                'is_nullable' => $row['IS_NULLABLE'],
                'column_default' => $row['COLUMN_DEFAULT'],
                'extra' => $row['EXTRA'],
                'column_key' => $row['COLUMN_KEY'],
                'collation_name' => $row['COLLATION_NAME'],
                'comment' => $row['COLUMN_COMMENT'],
                'generation_expression' => $row['GENERATION_EXPRESSION'],
            ];
        }
        return $grouped;
    }

    private function collectAllIndexesGrouped(): array
    {
        $stmt = $this->conn->prepare("
            SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        ");
        $stmt->execute([$this->database]);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = $row['TABLE_NAME'];
            $idx = $row['INDEX_NAME'];
            if (!isset($grouped[$table][$idx])) {
                $grouped[$table][$idx] = [
                    'name' => $idx,
                    'unique' => ($row['NON_UNIQUE'] == '0'),
                    'type' => $row['INDEX_TYPE'],
                    'columns' => [],
                ];
            }
            $grouped[$table][$idx]['columns'][] = [
                'name' => $row['COLUMN_NAME'],
                'subpart' => $row['SUB_PART'],
            ];
        }
        return $grouped;
    }

    private function collectAllForeignKeysGrouped(): array
    {
        $stmt = $this->conn->prepare("
            SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                   rc.UPDATE_RULE, rc.DELETE_RULE, k.ORDINAL_POSITION
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             AND rc.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION
        ");
        $stmt->execute([$this->database]);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = $row['TABLE_NAME'];
            $name = $row['CONSTRAINT_NAME'];
            if (!isset($grouped[$table][$name])) {
                $grouped[$table][$name] = [
                    'name' => $name,
                    'columns' => [],
                    'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                    'referenced_columns' => [],
                    'on_update' => $row['UPDATE_RULE'],
                    'on_delete' => $row['DELETE_RULE'],
                ];
            }
            $grouped[$table][$name]['columns'][] = $row['COLUMN_NAME'];
            $grouped[$table][$name]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        return $grouped;
    }

    private function collectColumns(string $table): array
    {
        $stmt = $this->conn->prepare("
            SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, DATA_TYPE,
                   CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                   IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME,
                   COLUMN_COMMENT, GENERATION_EXPRESSION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([$this->database, $table]);
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[$row['COLUMN_NAME']] = [
                'name' => $row['COLUMN_NAME'],
                'ordinal' => (int)$row['ORDINAL_POSITION'],
                'column_type' => $row['COLUMN_TYPE'],
                'data_type' => strtolower($row['DATA_TYPE']),
                'char_max_length' => $row['CHARACTER_MAXIMUM_LENGTH'],
                'numeric_precision' => $row['NUMERIC_PRECISION'],
                'numeric_scale' => $row['NUMERIC_SCALE'],
                'is_nullable' => $row['IS_NULLABLE'],
                'column_default' => $row['COLUMN_DEFAULT'],
                'extra' => $row['EXTRA'],
                'column_key' => $row['COLUMN_KEY'],
                'collation_name' => $row['COLLATION_NAME'],
                'comment' => $row['COLUMN_COMMENT'],
                'generation_expression' => $row['GENERATION_EXPRESSION'],
            ];
        }
        return $cols;
    }

    private function collectIndexes(string $table): array
    {
        $stmt = $this->conn->prepare("
            SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $stmt->execute([$this->database, $table]);
        $indexes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $idx = $row['INDEX_NAME'];
            if (!isset($indexes[$idx])) {
                $indexes[$idx] = [
                    'name' => $idx,
                    'unique' => ($row['NON_UNIQUE'] == '0'),
                    'type' => $row['INDEX_TYPE'],
                    'columns' => [],
                ];
            }
            $indexes[$idx]['columns'][] = [
                'name' => $row['COLUMN_NAME'],
                'subpart' => $row['SUB_PART'],
            ];
        }
        return $indexes;
    }

    private function collectForeignKeys(string $table): array
    {
        $stmt = $this->conn->prepare("
            SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                   rc.UPDATE_RULE, rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
              ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             AND rc.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION
        ");
        $stmt->execute([$this->database, $table]);
        $fks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $row['CONSTRAINT_NAME'];
            if (!isset($fks[$name])) {
                $fks[$name] = [
                    'name' => $name,
                    'columns' => [],
                    'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                    'referenced_columns' => [],
                    'on_update' => $row['UPDATE_RULE'],
                    'on_delete' => $row['DELETE_RULE'],
                ];
            }
            $fks[$name]['columns'][] = $row['COLUMN_NAME'];
            $fks[$name]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
        }
        return $fks;
    }

    private function extractPrimaryKey(string $table): ?array
    {
        $indexes = $this->collectIndexes($table);
        if (!isset($indexes['PRIMARY'])) {
            return null;
        }
        return $indexes['PRIMARY'];
    }

    private function collectViews(): array
    {
        $views = [];
        $stmt = $this->conn->prepare("
            SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $create = $this->showCreate('VIEW', $name);
            $views[$name] = [
                'name' => $name,
                'create_sql' => $create,
                'definition_normalized' => self::normalizeRoutineSql($create),
            ];
        }
        return $views;
    }

    /** Compare-only views (information_schema — no SHOW CREATE VIEW per view). */
    private function collectViewsLite(): array
    {
        $views = [];
        $stmt = $this->conn->prepare("
            SELECT TABLE_NAME, VIEW_DEFINITION
            FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $row['TABLE_NAME'];
            $def = (string)($row['VIEW_DEFINITION'] ?? '');
            $views[$name] = [
                'name' => $name,
                'create_sql' => '',
                'definition_normalized' => self::normalizeRoutineSql($def),
            ];
        }
        return $views;
    }

    private function collectTriggersLite(): array
    {
        try {
            $c = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?");
            $c->execute([$this->database]);
            if ((int)$c->fetchColumn() === 0) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $this->collectTriggers();
    }

    private function collectEventsLite(): array
    {
        try {
            $c = $this->conn->prepare("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?");
            $c->execute([$this->database]);
            if ((int)$c->fetchColumn() === 0) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $this->collectEvents();
    }

    private function collectRoutinesLite(): array
    {
        $out = ['procedures' => [], 'functions' => []];
        try {
            $stmt = $this->conn->prepare("
                SELECT ROUTINE_NAME, ROUTINE_TYPE
                FROM information_schema.ROUTINES
                WHERE ROUTINE_SCHEMA = ?
                ORDER BY ROUTINE_NAME
            ");
            $stmt->execute([$this->database]);
            $names = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->warnings[] = 'Routines skipped on live compare: ' . $e->getMessage();
            return $out;
        }
        if (!$names) {
            return $out;
        }
        foreach ($names as $row) {
            $name = $row['ROUTINE_NAME'];
            $type = strtoupper($row['ROUTINE_TYPE']);
            $kind = $type === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $create = $this->showCreate($kind, $name);
            if ($create === '') {
                continue;
            }
            $entry = [
                'name' => $name,
                'create_sql' => $create,
                'definition_normalized' => self::normalizeRoutineSql($create),
            ];
            if ($type === 'FUNCTION') {
                $out['functions'][$name] = $entry;
            } else {
                $out['procedures'][$name] = $entry;
            }
        }
        return $out;
    }

    private function collectTriggers(): array
    {
        $triggers = [];
        try {
            $stmt = $this->conn->prepare("
                SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING
                FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = ?
                ORDER BY TRIGGER_NAME
            ");
            $stmt->execute([$this->database]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = $row['TRIGGER_NAME'];
                $create = $this->showCreate('TRIGGER', $name);
                $triggers[$name] = array_merge($row, [
                    'create_sql' => $create,
                    'definition_normalized' => self::normalizeRoutineSql($create),
                ]);
            }
        } catch (Throwable $e) {
            $this->warnings[] = 'Triggers introspection failed: ' . $e->getMessage();
        }
        return $triggers;
    }

    private function collectEvents(): array
    {
        $events = [];
        try {
            $stmt = $this->conn->prepare("
                SELECT EVENT_NAME, STATUS, EVENT_TYPE, EXECUTE_AT, INTERVAL_VALUE, INTERVAL_FIELD
                FROM information_schema.EVENTS
                WHERE EVENT_SCHEMA = ?
                ORDER BY EVENT_NAME
            ");
            $stmt->execute([$this->database]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = $row['EVENT_NAME'];
                $create = $this->showCreate('EVENT', $name);
                $events[$name] = array_merge($row, [
                    'create_sql' => $create,
                    'definition_normalized' => self::normalizeRoutineSql($create),
                ]);
            }
        } catch (Throwable $e) {
            $this->warnings[] = 'Events introspection skipped (scheduler may be disabled): ' . $e->getMessage();
        }
        return $events;
    }

    private function collectRoutines(): array
    {
        $out = ['procedures' => [], 'functions' => []];
        $names = [];

        try {
            $stmt = $this->conn->prepare("
                SELECT ROUTINE_NAME, ROUTINE_TYPE
                FROM information_schema.ROUTINES
                WHERE ROUTINE_SCHEMA = ?
                ORDER BY ROUTINE_NAME
            ");
            $stmt->execute([$this->database]);
            $names = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->warnings[] = 'Routine list via information_schema failed: ' . $e->getMessage() . ' — try mysql_upgrade on the server.';
            try {
                foreach ($this->conn->query("SHOW PROCEDURE STATUS WHERE Db = " . $this->conn->quote($this->database))->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $names[] = ['ROUTINE_NAME' => $row['Name'], 'ROUTINE_TYPE' => 'PROCEDURE'];
                }
                foreach ($this->conn->query("SHOW FUNCTION STATUS WHERE Db = " . $this->conn->quote($this->database))->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $names[] = ['ROUTINE_NAME' => $row['Name'], 'ROUTINE_TYPE' => 'FUNCTION'];
                }
            } catch (Throwable $e2) {
                $this->warnings[] = 'Routine list via SHOW STATUS failed: ' . $e2->getMessage();
            }
        }

        foreach ($names as $row) {
            $name = $row['ROUTINE_NAME'];
            $type = strtoupper($row['ROUTINE_TYPE']);
            $kind = $type === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $create = $this->showCreate($kind, $name);
            if ($create === '') {
                continue;
            }
            $entry = [
                'name' => $name,
                'create_sql' => $create,
                'definition_normalized' => self::normalizeRoutineSql($create),
            ];
            if ($type === 'FUNCTION') {
                $out['functions'][$name] = $entry;
            } else {
                $out['procedures'][$name] = $entry;
            }
        }
        return $out;
    }

    private function countForeignKeys(): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");
        $stmt->execute([$this->database]);
        return (int)$stmt->fetchColumn();
    }

    private function showCreate(string $type, string $name): string
    {
        $map = [
            'TABLE' => 'SHOW CREATE TABLE',
            'VIEW' => 'SHOW CREATE VIEW',
            'TRIGGER' => 'SHOW CREATE TRIGGER',
            'EVENT' => 'SHOW CREATE EVENT',
            'PROCEDURE' => 'SHOW CREATE PROCEDURE',
            'FUNCTION' => 'SHOW CREATE FUNCTION',
        ];
        if (!isset($map[$type])) {
            return '';
        }
        $qTable = self::quoteIdent($name);
        $sql = $map[$type] . ' ' . $qTable;
        try {
            $row = $this->conn->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return '';
            }
            foreach ($row as $key => $val) {
                if (stripos($key, 'create') !== false) {
                    return (string)$val;
                }
            }
        } catch (Throwable $e) {
            $this->warnings[] = "$type $name: " . $e->getMessage();
        }
        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Comparators                                                        */
    /* ------------------------------------------------------------------ */

    private function compareTableMeta(string $table, array $local, array $live, array &$plan): void
    {
        if (($local['engine'] ?? '') !== ($live['engine'] ?? '')) {
            $plan['manual_review'][] = $this->item(
                'table_meta',
                'engine',
                $table,
                '',
                'Engine differs (local: ' . ($local['engine'] ?? '?') . ', live: ' . ($live['engine'] ?? '?') . ').'
            );
        }
        if (($local['collation'] ?? '') !== ($live['collation'] ?? '')) {
            $plan['manual_review'][] = $this->item(
                'table_meta',
                'collation',
                $table,
                '',
                'Table collation differs (local: ' . ($local['collation'] ?? '?') . ', live: ' . ($live['collation'] ?? '?') . ').'
            );
        }
    }

    private function compareColumns(string $table, array $localCols, array $liveCols, array &$plan): void
    {
        $qt = self::quoteIdent($table);

        foreach ($localCols as $colName => $localCol) {
            if (!isset($liveCols[$colName])) {
                $def = self::buildColumnDefinition($localCol);
                $after = $this->afterColumnClause($localCols, $colName, $liveCols);
                $sql = 'ALTER TABLE ' . $qt . ' ADD COLUMN ' . $def . $after;
                $plan['safe'][] = $this->item('column', 'add', $table . '.' . $colName, $sql, 'Missing column on live.');
                $plan['summary']['missing_columns']++;
                continue;
            }

            $liveCol = $liveCols[$colName];
            $localType = strtolower($localCol['column_type'] ?? '');
            $liveType = strtolower($liveCol['column_type'] ?? '');

            if ($localType === $liveType
                && ($localCol['is_nullable'] ?? '') === ($liveCol['is_nullable'] ?? '')
                && self::defaultsEqual($localCol, $liveCol)
                && ($localCol['extra'] ?? '') === ($liveCol['extra'] ?? '')
            ) {
                continue;
            }

            if ($localType !== $liveType) {
                if (self::isVarcharExpansion($liveCol, $localCol)) {
                    $sql = 'ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . self::buildColumnDefinition($localCol);
                    $plan['safe'][] = $this->item('column', 'expand_varchar', $table . '.' . $colName, $sql, 'Expand VARCHAR length safely.');
                    $plan['summary']['safe_column_changes']++;
                } else {
                    $plan['manual_review'][] = $this->item(
                        'column',
                        'type_change',
                        $table . '.' . $colName,
                        '-- Manual: ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . self::buildColumnDefinition($localCol),
                        'Column type differs (live: ' . $liveType . ', local: ' . $localType . '). May cause data loss.'
                    );
                }
                continue;
            }

            if (($localCol['is_nullable'] ?? '') === 'YES' && ($liveCol['is_nullable'] ?? '') === 'NO') {
                $sql = 'ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . self::buildColumnDefinition($localCol);
                $plan['safe'][] = $this->item('column', 'allow_null', $table . '.' . $colName, $sql, 'Allow NULL (less restrictive).');
                $plan['summary']['safe_column_changes']++;
                continue;
            }

            if (($localCol['is_nullable'] ?? '') === 'NO' && ($liveCol['is_nullable'] ?? '') === 'YES') {
                if ($this->columnHasNulls($table, $colName)) {
                    $plan['manual_review'][] = $this->item(
                        'column',
                        'not_null',
                        $table . '.' . $colName,
                        '-- Manual: fix NULL rows first, then: ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . self::buildColumnDefinition($localCol),
                        'Local requires NOT NULL but live column contains NULL values.'
                    );
                } else {
                    $plan['manual_review'][] = $this->item(
                        'column',
                        'not_null',
                        $table . '.' . $colName,
                        'ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . self::buildColumnDefinition($localCol),
                        'NOT NULL change — review before applying on live.'
                    );
                }
                continue;
            }

            if (!self::defaultsEqual($localCol, $liveCol)) {
                $plan['manual_review'][] = $this->item(
                    'column',
                    'default',
                    $table . '.' . $colName,
                    '-- Manual: ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . self::buildColumnDefinition($localCol),
                    'Default value differs.'
                );
            }

            if (($localCol['extra'] ?? '') !== ($liveCol['extra'] ?? '')) {
                $plan['manual_review'][] = $this->item(
                    'column',
                    'extra',
                    $table . '.' . $colName,
                    '-- Manual: ALTER TABLE ' . $qt . ' MODIFY COLUMN ' . self::buildColumnDefinition($localCol),
                    'Column extra attributes differ (auto_increment / on update).'
                );
            }
        }

        foreach ($liveCols as $colName => $_liveCol) {
            if (!isset($localCols[$colName])) {
                $plan['skipped'][] = $this->item(
                    'column',
                    'extra_on_live',
                    $table . '.' . $colName,
                    '',
                    'Column exists on live but not in local snapshot — left unchanged (no drops).'
                );
            }
        }
    }

    private function compareIndexes(string $table, array $localIdx, array $liveIdx, array &$plan): void
    {
        $qt = self::quoteIdent($table);
        foreach ($localIdx as $idxName => $localIndex) {
            if ($idxName === 'PRIMARY') {
                continue;
            }
            if (!isset($liveIdx[$idxName])) {
                $sql = self::buildAddIndexSql($table, $localIndex);
                $plan['safe'][] = $this->item('index', 'add', $table . '.' . $idxName, $sql, 'Missing index on live.');
                $plan['summary']['missing_indexes']++;
                continue;
            }
            if (self::indexSignature($localIndex) !== self::indexSignature($liveIdx[$idxName])) {
                $plan['manual_review'][] = $this->item(
                    'index',
                    'diff',
                    $table . '.' . $idxName,
                    '-- Manual: review index definition',
                    'Index exists but definition differs.'
                );
            }
        }
    }

    private function comparePrimaryKey(string $table, array $localTable, array $liveTable, array &$plan): void
    {
        $localPk = $localTable['primary_key'] ?? null;
        $livePk = $liveTable['primary_key'] ?? null;
        if ($localPk && !$livePk) {
            $plan['manual_review'][] = $this->item(
                'primary_key',
                'add',
                $table,
                '-- Manual: add PRIMARY KEY after verifying no duplicates',
                'Local has PRIMARY KEY; live table has none.'
            );
        } elseif ($localPk && $livePk && self::indexSignature($localPk) !== self::indexSignature($livePk)) {
            $plan['manual_review'][] = $this->item(
                'primary_key',
                'diff',
                $table,
                '',
                'PRIMARY KEY definition differs.'
            );
        }
    }

    private function compareForeignKeys(string $table, array $localFks, array $liveFks, array &$plan): void
    {
        foreach ($localFks as $fkName => $localFk) {
            if (isset($liveFks[$fkName])) {
                if (self::fkSignature($localFk) !== self::fkSignature($liveFks[$fkName])) {
                    $plan['manual_review'][] = $this->item(
                        'foreign_key',
                        'diff',
                        $table . '.' . $fkName,
                        '',
                        'Foreign key definition differs.'
                    );
                }
                continue;
            }

            $matchName = null;
            foreach ($liveFks as $liveName => $liveFk) {
                if (self::fkSignature($localFk) === self::fkSignature($liveFk)) {
                    $matchName = $liveName;
                    break;
                }
            }
            if ($matchName !== null) {
                continue;
            }

            $violations = $this->countFkViolations($table, $localFk);
            $sql = self::buildAddForeignKeySql($table, $localFk);
            if ($violations > 0) {
                $plan['manual_review'][] = $this->item(
                    'foreign_key',
                    'add',
                    $table . '.' . $fkName,
                    $sql,
                    "Missing FK on live but $violations orphan row(s) would violate it."
                );
            } else {
                $plan['safe'][] = $this->item('foreign_key', 'add', $table . '.' . $fkName, $sql, 'Missing foreign key on live.');
                $plan['summary']['missing_foreign_keys']++;
            }
        }
    }

    private function compareViews(array $local, array $live, array &$plan): void
    {
        foreach ($local as $name => $localView) {
            if (!isset($live[$name])) {
                $sql = self::sanitizeCreateStatement($localView['create_sql'] ?? '');
                $plan['safe'][] = $this->item('view', 'create', $name, $sql, 'Missing view on live.');
                $plan['summary']['missing_views']++;
                continue;
            }
            $localNorm = $localView['definition_normalized'] ?? self::normalizeRoutineSql($localView['create_sql'] ?? '');
            $liveNorm = $live[$name]['definition_normalized'] ?? self::normalizeRoutineSql($live[$name]['create_sql'] ?? '');
            if ($localNorm !== $liveNorm) {
                $replace = self::toCreateOrReplaceView($localView['create_sql'] ?? '');
                $plan['manual_review'][] = $this->item(
                    'view',
                    'replace',
                    $name,
                    $replace,
                    'View definition differs — review dependencies before replacing.'
                );
                $plan['summary']['changed_views']++;
            }
        }
        foreach ($live as $name => $_) {
            if (!isset($local[$name])) {
                $plan['skipped'][] = $this->item('view', 'extra_on_live', $name, '', 'View on live not in local snapshot — not dropped.');
            }
        }
    }

    private function compareTriggers(array $local, array $live, array &$plan): void
    {
        foreach ($local as $name => $localTrg) {
            if (!isset($live[$name])) {
                $sql = self::sanitizeCreateStatement($localTrg['create_sql'] ?? '');
                $plan['safe'][] = $this->item('trigger', 'create', $name, $sql, 'Missing trigger on live.');
                $plan['summary']['missing_triggers']++;
                continue;
            }
            $localNorm = $localTrg['definition_normalized'] ?? '';
            $liveNorm = $live[$name]['definition_normalized'] ?? '';
            if ($localNorm !== $liveNorm) {
                $plan['manual_review'][] = $this->item(
                    'trigger',
                    'replace',
                    $name,
                    '-- Manual: DROP TRIGGER + CREATE from snapshot',
                    'Trigger definition differs.'
                );
                $plan['summary']['changed_triggers']++;
            }
        }
    }

    private function compareRoutines(string $kind, array $local, array $live, array &$plan): void
    {
        $summaryMissing = $kind === 'procedure' ? 'missing_procedures' : 'missing_functions';
        $summaryChanged = $kind === 'procedure' ? 'changed_procedures' : 'changed_functions';

        foreach ($local as $name => $localRoutine) {
            if (!isset($live[$name])) {
                $sql = self::sanitizeCreateStatement($localRoutine['create_sql'] ?? '');
                $plan['safe'][] = $this->item($kind, 'create', $name, $sql, 'Missing ' . $kind . ' on live.');
                $plan['summary'][$summaryMissing]++;
                continue;
            }
            $localNorm = $localRoutine['definition_normalized'] ?? '';
            $liveNorm = $live[$name]['definition_normalized'] ?? '';
            if ($localNorm !== $liveNorm) {
                $plan['manual_review'][] = $this->item(
                    $kind,
                    'replace',
                    $name,
                    '-- Manual: DROP ' . strtoupper($kind) . ' + CREATE from snapshot',
                    ucfirst($kind) . ' definition differs.'
                );
                $plan['summary'][$summaryChanged]++;
            }
        }
    }

    private function compareEvents(array $local, array $live, array &$plan): void
    {
        foreach ($local as $name => $localEvent) {
            if (!isset($live[$name])) {
                $sql = self::sanitizeCreateStatement($localEvent['create_sql'] ?? '');
                $plan['manual_review'][] = $this->item(
                    'event',
                    'create',
                    $name,
                    $sql,
                    'Missing event — requires EVENT privilege and scheduler enabled.'
                );
                $plan['summary']['missing_events']++;
                continue;
            }
            $localNorm = $localEvent['definition_normalized'] ?? '';
            $liveNorm = $live[$name]['definition_normalized'] ?? '';
            if ($localNorm !== $liveNorm) {
                $plan['manual_review'][] = $this->item(
                    'event',
                    'replace',
                    $name,
                    '-- Manual: review event definition',
                    'Event definition differs.'
                );
                $plan['summary']['changed_events']++;
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function item(string $category, string $action, string $object, string $sql, string $reason): array
    {
        return [
            'category' => $category,
            'action' => $action,
            'object' => $object,
            'sql' => $sql,
            'reason' => $reason,
        ];
    }

    private function afterColumnClause(array $localCols, string $colName, array $liveCols): string
    {
        $target = (int)($localCols[$colName]['ordinal'] ?? 0);
        $prev = null;
        $prevOrdinal = -1;
        foreach ($localCols as $name => $col) {
            $ordinal = (int)($col['ordinal'] ?? 0);
            if ($ordinal < $target && isset($liveCols[$name]) && $ordinal > $prevOrdinal) {
                $prev = $name;
                $prevOrdinal = $ordinal;
            }
        }
        if ($prev && isset($liveCols[$prev])) {
            return ' AFTER ' . self::quoteIdent($prev);
        }
        return '';
    }

    private function columnHasNulls(string $table, string $column): bool
    {
        $qt = self::quoteIdent($table);
        $qc = self::quoteIdent($column);
        try {
            $sql = "SELECT 1 FROM $qt WHERE $qc IS NULL LIMIT 1";
            return (bool)$this->conn->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return true;
        }
    }

    private function countFkViolations(string $table, array $fk): int
    {
        $qt = self::quoteIdent($table);
        $rt = self::quoteIdent($fk['referenced_table']);
        $join = [];
        $nullCheck = [];
        foreach ($fk['columns'] as $i => $col) {
            $rc = $fk['referenced_columns'][$i] ?? $col;
            $join[] = 'l.' . self::quoteIdent($col) . ' = r.' . self::quoteIdent($rc);
            $nullCheck[] = 'l.' . self::quoteIdent($col) . ' IS NOT NULL';
        }
        $sql = 'SELECT COUNT(*) FROM ' . $qt . ' l LEFT JOIN ' . $rt . ' r ON ' . implode(' AND ', $join)
            . ' WHERE (' . implode(' OR ', $nullCheck) . ') AND r.' . self::quoteIdent($fk['referenced_columns'][0]) . ' IS NULL';
        try {
            return (int)$this->conn->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 1;
        }
    }

    public static function isAlreadyAppliedError(string $message): bool
    {
        $msg = strtolower($message);
        $needles = [
            'duplicate column',
            'duplicate key name',
            'duplicate foreign key',
            'already exists',
            'multiple primary key',
            'check that column/key exists',
        ];
        foreach ($needles as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function buildColumnDefinition(array $col): string
    {
        $dataType = strtolower($col['data_type'] ?? '');
        $sql = self::quoteIdent($col['name']) . ' ' . $col['column_type'];
        if (!empty($col['collation_name']) && self::typeUsesCollation($dataType) && !in_array($dataType, ['enum', 'set'], true)) {
            $sql .= ' COLLATE ' . $col['collation_name'];
        }
        if (!empty($col['generation_expression'])) {
            $sql .= ' GENERATED ALWAYS AS (' . $col['generation_expression'] . ')';
            if (stripos($col['extra'] ?? '', 'stored') !== false) {
                $sql .= ' STORED';
            } else {
                $sql .= ' VIRTUAL';
            }
        } else {
            $sql .= ($col['is_nullable'] ?? '') === 'YES' ? ' NULL' : ' NOT NULL';
            if (array_key_exists('column_default', $col) && $col['column_default'] !== null) {
                $formatted = self::formatDefault($col['column_default'], $dataType);
                $isNullable = ($col['is_nullable'] ?? '') === 'YES';
                if ($formatted !== 'NULL' || !$isNullable) {
                    $sql .= ' DEFAULT ' . $formatted;
                }
            }
            if (!empty($col['extra'])) {
                $extra = $col['extra'];
                if (stripos($extra, 'DEFAULT_GENERATED') === false) {
                    $sql .= ' ' . $extra;
                }
            }
        }
        if (!empty($col['comment'])) {
            $sql .= ' COMMENT ' . self::quoteString($col['comment']);
        }
        return $sql;
    }

    public static function buildAddIndexSql(string $table, array $index): string
    {
        $cols = [];
        foreach ($index['columns'] as $c) {
            $part = self::quoteIdent($c['name']);
            if (!empty($c['subpart'])) {
                $part .= '(' . (int)$c['subpart'] . ')';
            }
            $cols[] = $part;
        }
        $qt = self::quoteIdent($table);
        $idx = self::quoteIdent($index['name']);
        if (!empty($index['unique'])) {
            return 'ALTER TABLE ' . $qt . ' ADD UNIQUE INDEX ' . $idx . ' (' . implode(', ', $cols) . ')';
        }
        return 'ALTER TABLE ' . $qt . ' ADD INDEX ' . $idx . ' (' . implode(', ', $cols) . ')';
    }

    public static function buildAddForeignKeySql(string $table, array $fk): string
    {
        $qt = self::quoteIdent($table);
        $rt = self::quoteIdent($fk['referenced_table']);
        $cols = array_map([self::class, 'quoteIdent'], $fk['columns']);
        $refCols = array_map([self::class, 'quoteIdent'], $fk['referenced_columns']);
        $name = self::quoteIdent($fk['name']);
        return 'ALTER TABLE ' . $qt . ' ADD CONSTRAINT ' . $name
            . ' FOREIGN KEY (' . implode(', ', $cols) . ') REFERENCES ' . $rt
            . ' (' . implode(', ', $refCols) . ')'
            . ' ON UPDATE ' . ($fk['on_update'] ?? 'RESTRICT')
            . ' ON DELETE ' . ($fk['on_delete'] ?? 'RESTRICT');
    }

    public static function sanitizeCreateStatement(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return '';
        }
        $sql = preg_replace('/DEFINER\s*=\s*`[^`]+`@`[^`]+`/i', 'DEFINER=CURRENT_USER', $sql);
        $sql = preg_replace('/SQL\s+SECURITY\s+DEFINER/i', 'SQL SECURITY INVOKER', $sql);
        return $sql;
    }

    public static function toCreateOrReplaceView(string $sql): string
    {
        $sql = self::sanitizeCreateStatement($sql);
        if (preg_match('/^CREATE\s+(OR\s+REPLACE\s+)?(ALGORITHM=\w+\s+)?(DEFINER=\S+\s+)?(SQL\s+SECURITY\s+\w+\s+)?VIEW/i', $sql)) {
            return preg_replace('/^CREATE\s+(OR\s+REPLACE\s+)?/i', 'CREATE OR REPLACE ', $sql, 1);
        }
        return $sql;
    }

    public static function normalizeRoutineSql(string $sql): string
    {
        $sql = self::sanitizeCreateStatement($sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        return strtolower(trim($sql));
    }

    public static function isVarcharExpansion(array $liveCol, array $localCol): bool
    {
        $liveDt = $liveCol['data_type'] ?? '';
        $localDt = $localCol['data_type'] ?? '';
        if (!in_array($liveDt, ['varchar', 'char'], true) || $liveDt !== $localDt) {
            return false;
        }
        $liveLen = (int)($liveCol['char_max_length'] ?? 0);
        $localLen = (int)($localCol['char_max_length'] ?? 0);
        return $localLen > $liveLen;
    }

    public static function defaultsEqual(array $a, array $b): bool
    {
        return self::normalizeDefault($a['column_default'] ?? null, $a['data_type'] ?? '')
            === self::normalizeDefault($b['column_default'] ?? null, $b['data_type'] ?? '');
    }

    public static function normalizeDefault($default, string $dataType): string
    {
        if ($default === null) {
            return '';
        }
        $d = (string)$default;
        if ($d === 'NULL') {
            return '';
        }
        if (in_array(strtolower($dataType), ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true)) {
            return is_numeric($d) ? (string)(0 + $d) : trim($d, "'\"");
        }
        return trim($d, "'\"");
    }

    public static function formatDefault($default, string $dataType): string
    {
        if ($default === null) {
            return 'NULL';
        }
        $dRaw = (string)$default;
        if (stripos($dRaw, 'current_timestamp') !== false) {
            return strtoupper($dRaw);
        }
        $d = trim($dRaw, "'\"");
        if ($d === '' || strcasecmp($d, 'NULL') === 0) {
            return 'NULL';
        }
        if (in_array(strtolower($dataType), ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'float', 'double'], true)) {
            if ($d === '' || !is_numeric($d)) {
                return self::quoteString($d);
            }
            return (string)(0 + $d);
        }
        return self::quoteString($d);
    }

    public static function quoteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public static function typeUsesCollation(string $dataType): bool
    {
        return in_array(strtolower($dataType), ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set'], true);
    }

    public static function indexSignature(array $index): string
    {
        $cols = array_map(fn($c) => ($c['name'] ?? '') . ':' . ($c['subpart'] ?? ''), $index['columns'] ?? []);
        return ($index['unique'] ?? false ? 'U' : 'N') . '|' . implode(',', $cols);
    }

    public static function fkSignature(array $fk): string
    {
        return implode(',', $fk['columns'] ?? []) . '->'
            . ($fk['referenced_table'] ?? '') . '(' . implode(',', $fk['referenced_columns'] ?? []) . ')'
            . '|' . ($fk['on_update'] ?? '') . '|' . ($fk['on_delete'] ?? '');
    }

    public static function oneLine(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    public static function checkAccess(?array $roles = null, ?string $syncKey = null): bool
    {
        $roles = $roles ?? [];
        if (array_intersect($roles, ['Owner', 'Admin'])) {
            return true;
        }

        $allowedIps = defined('DB_STRUCTURE_SYNC_ALLOWED_IPS') ? (array)DB_STRUCTURE_SYNC_ALLOWED_IPS : [];
        if ($allowedIps) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (in_array($ip, $allowedIps, true)) {
                return true;
            }
        }

        $requiredKey = defined('DB_STRUCTURE_SYNC_KEY') ? (string)DB_STRUCTURE_SYNC_KEY : '';
        if ($requiredKey !== '') {
            $provided = $syncKey ?? ($_GET['sync_key'] ?? $_POST['sync_key'] ?? '');
            if (hash_equals($requiredKey, (string)$provided)) {
                return true;
            }
        }

        return false;
    }
}
