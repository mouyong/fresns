<?php

/*
 * Fresns (https://fresns.org)
 * Copyright (C) 2021-Present Jarvis Tang
 * Released under the Apache-2.0 License.
 */

namespace App\Fresns\Panel\Http\Controllers;

use App\Helpers\AppHelper;
use App\Models\Config;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\View;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $defaultLanguage;

    protected $optionalLanguages;

    public function __construct()
    {
        View::share('langs', config('FsConfig.langs'));

        try {
            // default language
            $defaultLanguageConfig = Config::where('item_key', 'default_language')->first();

            $defaultLanguage = $defaultLanguageConfig ? $defaultLanguageConfig->item_value : config('app.locale');
            $this->defaultLanguage = $defaultLanguage;
            View::share('defaultLanguage', $defaultLanguage);

            // Available languages
            $stats = Config::where('item_key', 'language_status')->first();

            $languageConfig = Config::where('item_key', 'language_menus')->first();
            $optionalLanguages = $languageConfig ? $languageConfig->item_value : [];

            if (! $stats || ! $stats->item_value) {
                $optionalLanguages = collect($optionalLanguages)->where('langTag', $defaultLanguage)->all();
            }
            $this->optionalLanguages = $optionalLanguages;

            View::share('optionalLanguages', collect($optionalLanguages));

            $areaCodeConfig = Config::where('item_key', 'area_codes')->first();
            $areaCodes = $areaCodeConfig ? $areaCodeConfig->item_value : [];
            View::share('areaCodes', collect($areaCodes));

            // site home url
            $siteUrlConfig = Config::where('item_key', 'site_url')->first();
            $siteUrl = $siteUrlConfig->item_value ?? '/';

            View::share('siteUrl', $siteUrl);

            View::share('versionMd5', AppHelper::VERSION_MD5_16BIT);
        } catch (\Exception $e) {
        }
    }

    public function requestSuccess()
    {
        return $this->successResponse('request');
    }

    public function createSuccess()
    {
        return $this->successResponse('create');
    }

    public function updateSuccess()
    {
        return $this->successResponse('update');
    }

    public function deleteSuccess()
    {
        return $this->successResponse('delete');
    }

    public function installSuccess()
    {
        return $this->successResponse('install');
    }

    public function uninstallSuccess()
    {
        return $this->successResponse('uninstall');
    }

    public function successResponse($action)
    {
        return request()->ajax()
            ? response()->json(['message' => __('FsLang::tips.'.$action.'Success')], 200)
            : back()->with('success', __('FsLang::tips.'.$action.'Success'));
    }
}
