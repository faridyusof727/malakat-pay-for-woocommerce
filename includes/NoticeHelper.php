<?php

class NoticeHelper
{
    public static function humanify(array $errors)
    {
        $formatted = '';

        foreach($errors as $key => $error) {
            $formatted .= self::formatErrorKey($key) . ': ' . implode('<br/>', $error) . '<br/>';
        }

        return $formatted;
    }

    private function formatErrorKey($key)
    {
        $keyArr = explode('.', $key);
        return ucwords(implode(' ', $keyArr));
    }
}