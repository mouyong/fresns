<?php

/*
 * Fresns (https://fresns.org)
 * Copyright (C) 2021-Present Jarvis Tang
 * Released under the Apache-2.0 License.
 */

namespace App\Helpers;

use App\Models\Config;
use App\Models\SessionKey;
use App\Utilities\CommandUtility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AppHelper
{
    const VERSION = '2.0.0-beta.1';
    const VERSION_INT = 1;
    const VERSION_MD5 = 'ad08a1e2f72f73db450864a89d753a67';
    const VERSION_MD5_16BIT = 'f72f73db450864a8';

    // fresns test helper
    public static function fresnsTestHelper()
    {
        $fresnsTest = time();

        return $fresnsTest;
    }

    // app version
    public static function getAppVersion()
    {
        $item['version'] = self::VERSION;
        $item['versionInt'] = self::VERSION_INT;
        $appVersion = $item;

        return $appVersion;
    }

    // get system info
    public static function getSystemInfo()
    {
        $systemInfo['server'] = php_uname('s').' '.php_uname('r');
        $systemInfo['web'] = $_SERVER['SERVER_SOFTWARE'];
        // $systemInfo['composer'] = array_merge(self::getComposerVersionInfo(), self::getComposerConfigInfo());
        $systemInfo['composer'] = self::getComposerVersionInfo();

        $phpInfo['version'] = PHP_VERSION;
        $phpInfo['cliInfo'] = CommandUtility::getPhpProcess(['-v'])->run()->getOutput();
        $phpInfo['uploadMaxFileSize'] = ini_get('upload_max_filesize');
        $systemInfo['php'] = $phpInfo;

        return $systemInfo;
    }

    // get mysql database info
    public static function getMySqlInfo()
    {
        $mySqlVersion = 'version()';
        $dbInfo['version'] = DB::select('select version()')[0]->$mySqlVersion;

        $dbInfo['timezone'] = 'UTC'.DateHelper::fresnsDatabaseTimezone();
        $dbInfo['envTimezone'] = config('app.timezone');
        $dbInfo['envTimezoneToUtc'] = 'UTC'.DateHelper::fresnsDatabaseTimezoneByName(config('app.timezone'));

        $mySqlCollation = 'Value';
        $dbInfo['collation'] = DB::select('show variables like "collation%"')[1]->$mySqlCollation;

        $mySqlSize = 'Size';
        $dbInfo['sizeMb'] = round(DB::select('SELECT table_schema AS "Database", SUM(data_length + index_length) / 1024 / 1024 AS "Size" FROM information_schema.TABLES GROUP BY table_schema')[1]->$mySqlSize, 2);
        $dbInfo['sizeGb'] = round(DB::select('SELECT table_schema AS "Database", SUM(data_length + index_length) / 1024 / 1024 / 1024 AS "Size" FROM information_schema.TABLES GROUP BY table_schema')[1]->$mySqlSize, 2);

        return $dbInfo;
    }

    // get composer version info
    public static function getComposerVersionInfo()
    {
        $composerInfo = CommandUtility::getComposerProcess(['-V'])->run()->getOutput();
        $toArray = explode(' ', $composerInfo);

        $version = null;
        foreach ($toArray as $item) {
            if (substr_count($item, '.') == 2) {
                $version = $item;
                break;
            }
        }

        $versionInfo['version'] = $version ?? 0;
        $versionInfo['versionInfo'] = $composerInfo;

        return $versionInfo;
    }

    // get composer version info
    public static function getComposerConfigInfo()
    {
        $configInfoDiagnose = CommandUtility::getComposerProcess(['diagnose'])->run()->getOutput();
        $configInfoRepositories = json_decode(CommandUtility::getComposerProcess(['config', '-g', 'repositories-packagist'])->run()->getOutput(), true);
        $configInfoAll = CommandUtility::getComposerProcess(['config', '-g', '--list'])->run()->getOutput();

        $configInfo['diagnose'] = $configInfoDiagnose ?? null;
        $configInfo['repositories'] = $configInfoRepositories ?? null;
        $configInfo['configList'] = $configInfoAll ?? null;

        return $configInfo;
    }

    // get themes
    public static function getThemes()
    {
        $themeFiles = glob(config('themes.paths.themes').'/*/theme.json');

        $themes = [];
        foreach ($themeFiles as $file) {
            $themeJson = json_decode(@file_get_contents($file), true);

            if (! $themeJson) {
                continue;
            }

            $themes[] = $themeJson;
        }

        return $themes;
    }

    // get plugin config
    public static function getPluginConfig(string $plugin)
    {
        $pluginJsonFile = config('plugins.paths.plugins').'/'.$plugin.'/plugin.json';
        if (! $pluginJsonFile) {
            return null;
        }

        $themeConfig = json_decode(File::get($pluginJsonFile), true);

        return $themeConfig;
    }

    // get theme config
    public static function getThemeConfig(string $theme)
    {
        $themeJsonFile = config('themes.paths.themes').'/'.$theme.'/theme.json';
        if (! $themeJsonFile) {
            return null;
        }

        $themeConfig = json_decode(File::get($themeJsonFile), true);

        return $themeConfig;
    }

    // set initial configuration
    public static function setInitialConfiguration()
    {
        $engine = AppHelper::getPluginConfig('FresnsEngine');
        $theme = AppHelper::getThemeConfig('ThemeFrame');

        // check web engine and theme
        if (empty($engine) && empty($theme)) {
            return;
        }

        // create key
        $appKey = new SessionKey;
        $appKey->platform_id = 4;
        $appKey->name = 'Fresns Official Engine';
        $appKey->app_id = Str::random(8);
        $appKey->app_secret = Str::random(32);
        $appKey->save();

        // config web engine and theme
        $configKeys = [
            'engine_service',
            'engine_key_id',
            'FresnsEngine_Desktop',
            'FresnsEngine_Mobile',
        ];

        $configValues = [
            'engine_service' => 'FresnsEngine',
            'engine_key_id' => $appKey->id,
            'FresnsEngine_Desktop' => 'ThemeFrame',
            'FresnsEngine_Mobile' => 'ThemeFrame',
        ];

        $configs = Config::whereIn('item_key', $configKeys)->get();

        foreach ($configKeys as $configKey) {
            $config = $configs->where('item_key', $configKey)->first();

            $config->item_value = $configValues[$configKey];
            $config->save();
        }

        // activate web engine
        \Artisan::call('market:activate', ['unikey' => 'FresnsEngine']);
    }
}
