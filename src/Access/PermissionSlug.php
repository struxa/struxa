<?php

declare(strict_types=1);

namespace App\Access;

final class PermissionSlug
{
    public const ACCESS_ADMIN = 'access_admin';
    public const MANAGE_USERS = 'manage_users';
    public const MANAGE_ROLES = 'manage_roles';
    public const MANAGE_SETTINGS = 'manage_settings';
    public const MANAGE_MENUS = 'manage_menus';
    public const MANAGE_MEDIA = 'manage_media';
    public const MANAGE_THEMES = 'manage_themes';
    public const MANAGE_PLUGINS = 'manage_plugins';
    public const MANAGE_CONTENT_TYPES = 'manage_content_types';
    public const MANAGE_TAXONOMIES = 'manage_taxonomies';
    public const MANAGE_PAGES = 'manage_pages';
    public const CREATE_CONTENT = 'create_content';
    public const EDIT_CONTENT = 'edit_content';
    public const DELETE_CONTENT = 'delete_content';
    public const PUBLISH_CONTENT = 'publish_content';
    public const REVIEW_CONTENT = 'review_content';
    public const VIEW_ACTIVITY = 'view_activity';
    public const MANAGE_PORTABILITY = 'manage_portability';
    public const MANAGE_SECURITY = 'manage_security';
    public const MANAGE_COMMENTS = 'manage_comments';
    public const MANAGE_FORMS = 'manage_forms';
    public const MANAGE_COMMERCE = 'manage_commerce';
    public const VIEW_LINK_ANALYTICS = 'view_link_analytics';
}
