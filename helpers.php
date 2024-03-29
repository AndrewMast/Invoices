<?php

function array_merge_recursive_distinct(array $array1, array $array2) {
    $merged = $array1;

    foreach ($array2 as $key => $value) {
        if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
            $merged[$key] = array_merge_recursive_distinct($merged[$key], $value);
        } else {
            $merged[$key] = $value;
        }
    }

    return $merged;
}

function object_to_array($d) {
    if (is_object($d)) {
        $d = get_object_vars($d);
    }

    if (is_array($d)) {
        return array_map(__FUNCTION__, $d);
    } else {
        return $d;
    }
}

function base_path($file = '') {
    return __DIR__ . DIRECTORY_SEPARATOR . $file;
}

function output_path($file = '') {
    return base_path('output' . DIRECTORY_SEPARATOR . $file);
}

function settings_path($file = '') {
    return base_path('settings' . DIRECTORY_SEPARATOR . $file);
}

function config_path($file = '') {
    return base_path('config' . DIRECTORY_SEPARATOR . $file);
}

function resource_path($file = '') {
    return base_path('resources' . DIRECTORY_SEPARATOR . $file);
}

function font_path($file = '') {
    return resource_path('fonts' . DIRECTORY_SEPARATOR . $file);
}
