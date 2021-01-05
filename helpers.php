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

function objectToArray($d) {
    if (is_object($d)) {
        $d = get_object_vars($d);
    }

    if (is_array($d)) {
        return array_map(__FUNCTION__, $d);
    } else {
        return $d;
    }
}

function resources_path($file = '') {
    return __DIR__ . '/resources/' . $file;
}

function fonts_path($file = '') {
    return resources_path('fonts/' . $file);
}
