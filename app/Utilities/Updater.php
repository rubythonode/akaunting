<?php

namespace App\Utilities;

use App\Models\Module\Module as Model;
use App\Models\Module\ModuleHistory as ModelHistory;
use App\Traits\SiteApi;
use Artisan;
use Cache;
use Date;
use File;
use Module;
use ZipArchive;

class Updater
{
    use SiteApi;

    public static function clear()
    {
        Cache::forget('modules');
        Cache::forget('updates');
        Cache::forget('versions');

        return true;
    }

    // Update
    public static function update($alias, $version)
    {
        if (!$data = static::download($alias, $version)) {
            return false;
        }

        $path = 'temp-' . md5(mt_rand());
        $temp_path = storage_path('app/temp') . '/' . $path;

        $file = $temp_path . '/upload.zip';

        // Create tmp directory
        if (!File::isDirectory($temp_path)) {
            File::makeDirectory($temp_path);
        }

        // Add content to the Zip file
        $uploaded = is_int(file_put_contents($file, $data)) ? true : false;

        if (!$uploaded) {
            return false;
        }

        // Unzip the file
        $zip = new ZipArchive();

        if (!$zip->open($file) || !$zip->extractTo($temp_path)) {
            return false;
        }

        $zip->close();

        // Remove Zip
        File::delete($file);

        if ($alias == 'core') {
            // Move all files/folders from temp path then delete it
            File::copyDirectory($temp_path, base_path());
            File::deleteDirectory($temp_path);

            // Update database
            Artisan::call('migrate', ['--force' => true]);

            // Check if the file mirror was successful
            if (version('short') != $version) {
                return false;
            }
        } else {
            $module = Module::get($alias);
            $model = Model::where('alias', $alias)->first();

            // Move all files/folders from temp path then delete it
            File::copyDirectory($temp_path, module_path($module->get('name')));
            File::deleteDirectory($temp_path);

            // Update database
            Artisan::call('migrate', ['--force' => true]);

            // Add history
            ModelHistory::create([
                'company_id' => session('company_id'),
                'module_id' => $model->id,
                'category' => $module->get('category'),
                'version' => $version,
                'description' => trans('modules.history.updated', ['module' => $module->get('name')]),
            ]);
        }

        return true;
    }

    public static function download($alias, $version)
    {
        $file = null;

        // Check core first
        $info = Info::all();

        if ($alias == 'core') {
            $url = 'core/download/' . $version . '/' . $info['php'] . '/' . $info['mysql'];
        } else {
            $url = 'modules/items/' . $alias . '/download/' . $version . '/' . $info['akaunting'] . '/' . $info['token'];
        }

        $response = static::getRemote($url, ['timeout' => 30, 'referer' => true, 'track_redirects' => true]);

        if ($response->getStatusCode() == 200) {
            $file = $response->getBody()->getContents();
        }

        return $file;
    }

    public static function all()
    {
        // Get data from cache
        $data = Cache::get('updates');

        if (!empty($data) || !setting('general.api_token')) {
            return $data;
        }

        // No data in cache, grab them from remote
        $data = array();

        $modules = Module::all();

        $versions = Versions::latest($modules);

        foreach ($versions as $alias => $version) {
            // Modules come as array
            if ($alias == 'core') {
                if (version_compare(version('short'), $version) != 0) {
                    $data['core'] = $version;
                }
            } else {
                $module = Module::get($alias);

                // Up-to-date
                if (version_compare($module->get('version'), $version) == 0) {
                    continue;
                }

                $data[$alias] = $version;
            }
        }

        Cache::put('updates', $data, Date::now()->addHour(6));

        return $data;
    }
}