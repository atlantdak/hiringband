<?php

declare(strict_types=1);

namespace App;

final class Config
{
    public const USER_AGENT = 'wordpress-draft-creator/1.0';
    public const CONNECT_TIMEOUT = 3.0;
    public const REQUEST_TIMEOUT = 10.0;
    public const POSTS_ROUTE = '/wp/v2/posts';
    public const DRAFT_TITLE = 'API test draft';
    public const DRAFT_CONTENT = 'This draft was created through the WordPress REST API.';
    public const DRAFT_STATUS = 'draft';
    /** @var list<int> Stable libcurl error numbers; some ext-curl builds omit legacy aliases. */
    public const TLS_CURL_ERRNOS = [35, 51, 58, 59, 60, 77, 83];

    private function __construct()
    {
    }
}
