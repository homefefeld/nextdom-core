<?php
/*
* This file is part of the NextDom software (https://github.com/NextDom or http://nextdom.github.io).
* Copyright (c) 2018 NextDom.
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, version 2.
*
* This program is distributed in the hope that it will be useful, but
* WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
* General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

namespace NextDom\Managers;

use NextDom\Exceptions\CoreException;
use NextDom\Helpers\FileSystemHelper;
use NextDom\Helpers\LogHelper;
use NextDom\Helpers\SystemHelper;
use splitbrain\PHPArchive\Tar;

class BackupManager
{
    /**
     * Runs the backup procedure
     *
     * Last output should not be removed since it act as a marker in ajax calls
     *
     * @param bool $interactive if true, messages are wrote on stdout
     * @return bool true if no error
     */
    public static function createBackup($interactive = false)
    {
        $printer    = self::getPrinter($interactive);
        $backupDir  = self::getBackupDirectory();
        $backupName = self::getBackupFilename();
        $backupPath = sprintf("%s/%s", $backupDir, $backupName);
        $sqlPath    = sprintf("%s/DB_backup.sql", $backupDir);
        $cachePath  = CacheManager::getCachePath();
        $startTime  = strtotime('now');
        $status     = "success";

        try {
            $printer("*********** starting backup procedure at %s ***********\n", date('Y-m-d H:i:s'));
            NextDomHelper::event('begin_backup', true);

            $printer("starting plugin backup...");
            self::backupPlugins();
            $printer("oK\n");
            $printer("checking database integrity...");
            self::repairDB();
            $printer("oK\n");
            $printer("starting database backup...");
            self::createDBBackup($sqlPath);
            $printer("oK\n");
            $printer("starting cache backup...");
            CacheManager::persist();
            $printer("oK\n");
            $printer("creating backup archive...");
            self::createBackupArchive($backupPath, $sqlPath, $cachePath);
            $printer("oK\n");
            $printer("rotating backup archives...");
            self::rotateBackups($backupDir);
            $printer("oK\n");
            $printer("uploading backup to remote clouds...");
            self::sendRemoteBackup($backupPath);
            $printer("oK\n");

            NextDomHelper::event('end_backup');
            $printer(" -> STATUS: success\n");
            $printer(" -> ELAPSED TIME: %s sec(s)\n", (strtotime('now') - $startTime));
            $printer("*********** end of backup procedure at %s ***********\n", date('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            $status = "error";
            $printer("Ko\n");
            $printer("> ERROR: %s\n" . Utils::br2nl($e->getMessage()));
            $printer("> DETAILS\n");
            $printer("%s\n", print_r($e->getTrace(), true));
            LogHelper::add('backup', 'error', $e->getMessage());
        }

        // the following line acts as marker used in ajax telling that the procedure is finished
        // it should be me removed
        $printer("Closing with %s\n\n", $status);
        return ($status == "success");
    }

    /**
     * Runs the restore procedure
     *
     * Last output should not be removed since it act as a marker in ajax calls
     *
     * @param bool $interactive if true, messages are wrote on stdout
     * @param bool $file path to backup archive, when empty, use last available backup
     * @return bool false when error occurs
     */
    public static function restoreBackup($interactive = false, $file = '') {
        $printer    = BackupManager::getPrinter($interactive);
        $backupDir  = self::getBackupDirectory();
        $startTime  = strtotime('now');
        $status     = "success";

        try {
            $printer("*********** starting restore procedure at %s ***********\n", date('Y-m-d H:i:s'));
            NextDomHelper::event('begin_restore', true);

            if ($file == null) {
                $file = self::getLastBackupFilePath($backupDir, "newest");
            }
            $printer("file used for restoration: %s", $file);

            $printer("stopping nextdom system...");
            NextDomHelper::stopSystem();
            $printer("oK\n");
            $printer("extracting backup archive...");
            $tmpDir = self::extractArchive($file);
            $printer("oK\n");
            $printer("resotring mysql database...");
            self::restoreDatabase($tmpDir);
            $printer("oK\n");
            $printer("importing jeedom configuration...");
            self::restoreJeedomConfig();
            $printer("oK\n");
            $printer("restoring cache...");
            self::restoreCache();
            $printer("oK\n");
            $printer("restoring plugins...");
            self::restorePlugins();
            $printer("oK\n");
            $printer("migrate database (post plugins)...");
            self::loadSQLMigrateScript();
            $printer("oK\n");
            $printer("starting nextdom system...");
            NextDomHelper::startSystem();
            $printer("oK\n");
            $printer("updating system configuration...");
            self::updateConfig();
            $printer("oK\n");
            $printer("chechking system consistency...");
            ConsistencyManager::checkConsistency();
            $printer("oK\n");

            SystemHelper::rrmdir($tmpDir);
            NextDomHelper::event("end_restore");
            $printer(" -> STATUS: success\n");
            $printer(" -> ELAPSED TIME: %s sec(s)\n", (strtotime('now') - $startTime));
            $printer("*********** end of restore procedure at %s ***********\n", date('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            $status = "error";
            $printer("Ko\n");
            $printer("> ERROR: %s\n", Utils::br2nl($e->getMessage()));
            $printer("> DETAILS\n");
            $printer("%s\n", print_r($e->getTrace(), true));
            LogHelper::add('restore', 'error', $e->getMessage());
        }
        // the following line acts as marker used in ajax telling that the procedure is finished
        // it should be me removed
        $printer("Closing with %s\n\n", $status);
        return ($status == "success");
    }

    /**
     * Creates a backup archive
     *
     * @param bool $background Lancer la sauvegarde en tâche de fond.
     * @throws \Exception
     */
    public static function backup(bool $background = false)
    {
        if ($background) {
            LogHelper::clear('backup');
            $script = sprintf("%s/install/backup.php", NEXTDOM_ROOT);
            SystemHelper::php($script);
        } else {
            self::createBackup(true);
        }
    }

    /**
     * Restore a backup from file
     *
     * @param string $file Backup file path
     * @param bool $background Start backup task in background
     */
    public static function restore(string $file = '', bool $background = false)
    {
        if (true == $background) {
            LogHelper::clear("restore");
            $script = sprintf("%s/install/restore.php", NEXTDOM_ROOT);
            SystemHelper::php($script);
        } else {
            self::restoreBackup(true, $file);
        }
    }

    /**
     * Creates callable va_arg printer according to $interfactive parameter
     *
     * @param bool $interfactive when true, printer write on stdout, write to backup log otherwise
     * @return function
     */
    private static function getPrinter($interfactive = false)
    {
        $logFile = null;
        if (false == $interactive) {
            $logPath = LogHelper::getPathToLog('backup');
            $logFile = fopen($logPath, "a");
        }
        return function() use ($logFile) {
            $args   = func_get_args();
            $format = $args[0];
            $msg    = vsprintf($format, array_shift($args));
            if ($logFile) {
                fprintf($logFile, $msg);
            } else {
                echo $msg;
            }
        };
    }

    /**
     * Creates an archive with all files that should be included in backup
     *
     * We proceed in 4 steps:
     * 1. creates a tar archive with plugins
     * 2. append mysql backup to tar archive
     * 3. append cache to  to tar archive
     * 4. compress tar archive
     *
     * This allows to untie path dependencies between source files while keeping
     * the same directory structure in the tar archive (in order to keep backward
     * compatibility with jeedom archives).
     *
     * @param string $outputPath path of generated backup archive
     * @retrun bool true archive generated successfully
     * @throws \Exception when error occured while writing the tar archive
     */
    public static function createBackupArchive($outputPath, $sqlPath, $cachePath)
    {
        $tar  = new Tar();
        $tar->setCompression();
        $tar->create($filename);
        $tar->addFile($cachePath, "var/cache.tar.gz");
        $tar->addFile($sqlPath,   "DB_backup.sql");
        $dirs = array("plugins", "public/img/plan");
        foreach ($dirs as $c_dir) {
            $entries = scandir(NEXTDOM_ROOT . '/' . $c_dir);
            $entries = array_filter($entries, is_file);
            array_map(array($tar, 'addFile'), $entries);
        }
        $tar->close();
    }

    /**
     * Creates an array with information about existing backup files
     *
     * Available fields:
     * - file: path to backup archive
     * - mtime: current file modification timestamp
     * - size: file size in bytes
     *
     * 1. get file list from globing
     * 2. generate array of object from file list
     *
     * @param string $backupDir backup root directory
     * @throws CoreException if cannot stat one of the backup files
     * @retrun array of file object
     */
    public static function getBackupFileInfo($backupDir) {
        $pattern = sprintf("%s/backup-*.tar.gz", $backupDir);
        // 1.
        if (false == ($entries = glob($pattern))) {
            throw CoreException("error in globbing pattern " . $pattern);
        }
        // 2.
        return array_map(function($c_file) {
            if (false == ($stat = stat($c_file))) {
                throw CoreException("unable to stat file " . $c_file);
            }
            return array("file" => $c_file, "mtime" => $stat[9], "size" => $stat[7])
        }, $entries);
    }

    /**
     * Returns path to last available backup archive
     *
     * @throws CoreException when no archive is found
     * @return string archive file path
     */
    private static function getLastBackupFilePath($backupDir, $order = "newest") {
        $files = self::getBackupFileInfo($backupDir);

        if ($order == "newest") {
            usort($files, function($x, $y) {
                return ($x["mtime"] - $y["mtime"]);
            });
        } else {
            usort($files, function($x, $y) {
                return -1 * ($x["mtime"] - $y["mtime"]);
            });
        }

        if (true == empty($files)) {
            throw \CoreException("unable to find any backup file");
        }
        return $file[0]["path"];
    }

    /**
     * Removes backup archives according to backup::keepDays and backup::maxSize
     *
     * 1. sort files by mtime using standard integer-cmp tick
     * 2. since files are sorted, we can sum-up bytes until limit is reached
     *
     * @param string $backupDir backup root directory
     * @throws \Exception
     */
    public static function rotateBackups($backupDir) {
        $maxDays         = ConfigManager::byKey('backup::keepDays');
        $maxSizeInBytes  = ConfigManager::byKey('backup::maxSize') * 1024 * 1024;
        $maxMtime        = time() - ($maxDays * 60 * 60 * 24);
        $totalBytes      = 0;
        $files           = self::getBackupFileInfo($backupDir);

        // 1.
        usort($files, function($x, $y) {
            return -1 * ($x["mtime"] - $y["mtime"]);
        });

        // 2.
        foreach ($files as $c_entry) {
            if (($totalBytes > $maxSizeInBytes) ||
                ($c_entry["mtime"] > $maxMtime)) {
                @unlink($c_entry);
                continue;
            }
            $totalBytes += $c_entry["size"];
        }
    }

    /**
     * Trigger remote upload for all available repos
     *
     * @param string $path path to backup archive
     * @retrun bool true is everything went fine
     */
    public static function sendRemoteBackup($path) {
        $repos = UpdateManager::listRepo();
        foreach ($repos as $c_key => $c_val) {
            if (($c_val['scope']['backup'] === false)            ||
                (ConfigManager::byKey($c_key . '::enable') == 0) ||
                (ConfigManager::byKey($c_key . '::cloudUpload') == 0)) {
                continue;
            }
            try {
                $class = sprintf("repo_%s", $c_key);
                $class::backup_send($path);
            } catch (Exception $e) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns backup directory according to backup::path
     *
     * When backup::path is relative, constructs directory from NEXTDOM_RUN root
     *
     * @retrun string backup root directory
     */
    public static function getBackupDirectory() {
        $dir = ConfigManager::byKey('backup::path');
        if ("/" != substr($dir, 0, 1)) {
            $dir = sprintf("%s/%s", NEXTDOM_RUN, $dir);
        }
        return $dir;
    }

    /**
     * Creates a backup of database to given output file path
     *
     * @throws \Exception true when mysqldump failed
     */
    public static function createDBBackup($outputFile)
    {
        global $CONFIG;

        $status  = 0;
        $format  = "mysqldump --host='%s' --port='%s' --user='%s' --password='%s' %s  > %s";
        $command = sprintf($format,
                           $CONFIG['db']['host'],
                           $CONFIG['db']['port'],
                           $CONFIG['db']['username'],
                           $CONFIG['db']['password'],
                           $CONFIG['db']['dbname'],
                           $outputFile);

        system($command, $status);
        if ($status != 0) {
            throw \Exception("error while dumping database, exited with status " . $status);
        }
    }

    /**
     * Checks and repair database
     *
     * @throws \Exception when mysqlcheck exited with error status
     */
    public static function repairDB()
    {
        global $CONFIG;

        $status  = 0;
        $format  = "mysqlcheck --host='%s' --port='%d' --user='%s' --password='%s' %s --auto-repair --silent";
        $command = sprintf($format,
                           $CONFIG['db']['host'],
                           $CONFIG['db']['port'],
                           $CONFIG['db']['username'],
                           $CONFIG['db']['password'],
                           $CONFIG['db']['dbname']);
        system($command, $status);
        if ($status != 0) {
            throw \Exception("error while checking database, exited with status " . $status);
        }
    }

    /**
     * Runs backup method for each active plugins if available
     *
     * @throws \Exception
     */
    public static function backupPlugins()
    {
        $plugins = PluginManager::listPlugin(true);
        foreach ($plugins as $c_plugin) {
            $pid = $c_plugin->getId();
            if (true == method_exists($pid, 'backup')) {
                $pid::backup();
            }
        }
    }

    /**
     * Computes backup filename from nextdom's name and current datetime
     *
     * @returns string backup filename
     */
    private static function getBackupFilename()
    {
        $date      = date("Y-m-d-H\hi");
        $version   = NextDomHelper::getJeedomVersion();
        $name      = ConfigManager::byKey('name', 'core', 'NextDom');
        $cleanName = str_replace(array('&','#', "'", '"', '+', "-"), "", $name);
        $cleanName = str_replace(" ", "_", $cleanName);
        $format    = "backup-%s-%s-%s.tar.gz";

        return sprintf($format, $cleanName, $version, $date);
    }


    /**
     * Obtenir la liste des sauvegardes
     *
     * @return array Liste des sauvegardes
     * @throws \Exception
     */
    public static function listBackup(): array
    {
        if (substr(ConfigManager::byKey('backup::path'), 0, 1) != '/') {
            $backup_dir = NEXTDOM_RUN . '/' . ConfigManager::byKey('backup::path');
        } else {
            $backup_dir = ConfigManager::byKey('backup::path');
        }
        $backups = FileSystemHelper::ls($backup_dir, '*.tar.gz', false, array('files', 'quiet', 'datetime_asc'));
        $result = array();
        foreach ($backups as $backup) {
            $result[$backup_dir . '/' . $backup] = $backup;
        }
        return $result;
    }

    /**
     * Remove a backup file
     *
     * @param string $backupFilePath Backup file path
     *
     * @throws CoreException
     */
    public static function removeBackup(string $backupFilePath)
    {
        if (file_exists($backupFilePath)) {
            unlink($backupFilePath);
        } else {
            throw new CoreException(__('Impossible de trouver le fichier : ') . $backupFilePath);
        }
    }

    /**
     * Load given file in mysql database
     *
     * @param string $file path to file to load
     * @throw CoreException when a mysql error occurs
     */
    private static function loadSQLFromFile($file)
    {
        if (false === ($content = file_get_contents($file))) {
            throw CoreException("unable to find sql file " . $file);
        }
        try {
            \DB::getConnection()->exec($content);
        } catch (\Exception $e) {
            throw CoreException("error loading sql file " . $file . " : " . $e->getMessage());
        }
    }

    /**
     * Extracts backup archive to a temporary folder
     *
     * @param string $file path to backup archive
     * @throw CoreException when error on reading archive or creating temporary dir
     * @return string path to generated temporary directory
     */
    private static function extractArchive($file) {
        $excludeDirs = array("AlternativeMarketForJeedom", "musicast");
        $exclude = sprintf("^(%s)$", join("|", $excludeDirs));
        $tmpDir  = sprintf("%s-%s", TMP_DIRECTORY, date('Y-m-d H:i:s'));
        if (false == mkdir($tmpDir, $mode = 0770, true)) {
            throw \CoreException("unable to create tmp directory " . $tmpDir);
        }
        $tar = new Tar();
        $tar->open($file);
        $entries = $tar->contents();
        $tar->extract($tmpDir, "", $exclude);
        return $tmpDir;
    }

    /**
     * Loads migrate script into mysql database
     *
     * @throws CoreException from RestoreManager::loadSQLFromFile
     */
    private static function loadSQLMigrateScript() {
        $migrateFile = sprintf("%s/migrate/migrate.sql", NEXTDOM_ROOT);

        self::loadSQLFromFile($migrateFile);
    }

    /**
     * Loads mysql dump from backup archive into database
     *
     * @param string $tmpDir extracted backup root directory
     * @throws CoreException when error occurs
     */
    private static function restoreDatabase($tmpDir) {
        $backupFile  = sprintf("%s/DB_backup.sql", $tmpDir);

        if (0 != SystemHelper::vsystem("sed -i -e s/jeedom/nextdom/g %s", $filepath)) {
            throw CoreException("unable to modify content of backup file " . $file);
        }
        \DB::Prepare("SET foreign_key_checks = 0", array(), \DB::FETCH_TYPE_ROW);
        $tables = \DB::Prepare("SHOW TABLES", array(), \DB::FETCH_TYPE_ALL);
        foreach ($tables as $table) {
            $table = array_values($table);
            $table = $table[0];
            $statement = sprintf("DROP TABLE IF EXISTS `%s`", $table[0]);
            \DB::Prepare($statement, array(), \DB::FETCH_TYPE_ROW);
        }
        self::loadSQLFromFile($backupFile);
        self::loadSQLMigrateScript();
        \DB::Prepare("SET foreign_key_checks = 1", array(), \DB::FETCH_TYPE_ROW);
    }

    /**
     * Import common config if not already exists
     *
     * @param string $tmpDir extracted backup root directory
     */
    private static function restoreJeedomConfig($tmpDir) {
        $commonBackup  = sprintf("%s/common.config.php",              $tmpDir);
        $commonConfig  = sprintf("%s/core/config/common.config.php",  NEXTDOM_ROOT);
        $jeedomConfig  = sprintf("%s/core/config/jeedom.config.php",  NEXTDOM_ROOT);

        if (true == file_exists($jeedomConfig)) {
            @unlink($jeedomConfig);
        }
        if ((false == file_exists($commonConfig)) &&
            (true  == file_exists($commonBackup))) {
            if (false == rename($commonBackup, $commonConfig)) {
                // should at least warn, silent fail kept from install/restore.php refactoring
            }
        }
    }

    /**
     * Restore cache from backup archive
     *
     * @param string $tmpDir extracted backup root directory
     */
    private static function restoreCache($tmpDir) {
        $cachePath1 = sprintf("%s/cache.tar.gz",     $tmpDir);
        $cachePath2 = sprintf("%s/var/cache.tar.gz", $tmpDir);
        $cacheDest  = sprintf("%s/cache.tar.gz",     NEXTDOM_RUN);

        if (true == file_exists($cachePath1)) {
            rename($cachePath1, $cacheDest);
        } elseif (true == file_exists($cachePath2)) {
            rename($cachePath2, $cacheDest);
        }

        try {
            CacheManager::restore();
        } catch (\Exception $e) {
            // not a big deal if cache cannot be restored
        }
    }

    /**
     * Restore plugins from backup archive
     *
     * @param string $tmpDir extracted backup root directory
     */
    private static function restorePlugins($tmpDir) {
        $plugingDirs = glob(sprintf("%s/plugings/*", $tmpDir), GLOB_ONLYDIR);
        $pluginRoot  = sprintf("%s/plugings", NEXTDOM_ROOT);

        foreach ($plugingDirs as $c_dir) {
            $name = basedir($c_dir);
            if (false == rename($c_dir, sprintf("%s/%s", $pluginRoot, $name))) {
                // should probably fail, keeping behavior prior to install/restore.php refactoring
            }
        }

        $plugins = PluginManager::listPlugin(true);
        foreach ($plugins as $c_plugin) {
            // call plugin restore hook, if any
            $pluginID = $c_plugin->getId();
            $dependencyInfo = $c_plugin->getDependencyInfo(true);
            if (method_exists($pluginID, 'restore')) {
                $pluginID::restore();
            }
            // reset plugin dependencies
            $cache = CacheManager::byKey('dependancy' . $plugin->getId());
            $cache->remove();
            CacheManager::set('dependancy' . $plugin->getId(), "nok");
        }
    }

    private static function updateConfig() {
        ConfigManager::save('hardware_name', '');
        $cache = CacheManager::byKey('nextdom::isCapable::sudo');
        $cache->remove();
    }
}
