<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Access\WorkflowService;
use App\Ai\AiBlogChatService;
use App\Ai\AiBlogChatSession;
use App\Ai\AiContentDraftService;
use App\Ai\AiDraftPublishedPagesContext;
use App\Ai\AiRateLimitExceededException;
use App\Ai\AiRateLimiter;
use App\Ai\ChatSseResponseStream;
use App\Ai\OpenAiApiKeyResolver;
use App\Ai\OpenAiChatStreamProcessor;
use App\Ai\OpenAiException;
use App\Content\ContentEntryFormValidator;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryRevisionRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentSlugger;
use App\Content\ContentTypeRepository;
use App\Event\ContentEntrySavedEvent;
use App\Event\Events;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaRepository;
use App\Seo\SeoFormParser;
use App\Settings\SettingsRepository;
use App\Settings;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\EntryTaxonomySync;
use App\Taxonomy\EntryTaxonomyValidator;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Psr7\Response as SlimResponse;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $permView = new RequirePermission($pdo, [
        PermissionSlug::CREATE_CONTENT,
        PermissionSlug::MANAGE_SETTINGS,
    ]);
    $permCreate = new RequirePermission($pdo, [PermissionSlug::CREATE_CONTENT]);
    $permSettings = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };
    $cmsUserId = static function (Request $request): ?int {
        /** @var array<string, mixed> $u */
        $u = $request->getAttribute('cms_user') ?? [];
        $id = isset($u['id']) ? (int) $u['id'] : 0;

        return $id > 0 ? $id : null;
    };
    $hasPerm = static function (Request $request, string $slug): bool {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];
        $slugs = $cmsUser['permission_slugs'] ?? [];

        return is_array($slugs) && in_array($slug, $slugs, true);
    };

    $jsonResponse = static function (Response $response, array $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $types = new ContentTypeRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $mediaRepo = new MediaRepository($pdo);
    $entryValidator = new ContentEntryFormValidator();
    $taxonomyRepo = new TaxonomyRepository($pdo);
    $taxonomyTermRepo = new TaxonomyTermRepository($pdo);
    $entryTaxonomyRepo = new ContentEntryTaxonomyRepository($pdo);
    $entryTaxonomyValidator = new EntryTaxonomyValidator();
    $workflow = new WorkflowService();
    $entryRevRepo = new ContentEntryRevisionRepository($pdo);
    $activity = new ActivityLogger($pdo);
    $settingsRepo = new SettingsRepository($pdo);
    $aiDraft = new AiContentDraftService();
    $aiChat = new AiBlogChatService();
    $aiRateLimiter = AiRateLimiter::create($pdo);

    /**
     * @return array{ok: true, entry_id: int, type_id: int}|array{ok: false, error: string}
     */
    $runAiDraftCreation = static function (
        Request $request,
        int $typeId,
        string $brief,
        string $tone
    ) use (
        $cmsUserId,
        $pdo,
        $types,
        $fields,
        $entries,
        $values,
        $mediaRepo,
        $entryValidator,
        $taxonomyRepo,
        $taxonomyTermRepo,
        $entryTaxonomyRepo,
        $entryTaxonomyValidator,
        $workflow,
        $entryRevRepo,
        $activity,
        $aiDraft,
        $aiRateLimiter
    ): array {
        $uid = $cmsUserId($request);
        if ($uid !== null) {
            try {
                $aiRateLimiter->assertDraftAllowed($uid);
            } catch (AiRateLimitExceededException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $t = $types->findById($typeId);
        if ($t === null) {
            return ['ok' => false, 'error' => 'Content type not found.'];
        }

        $apiKey = OpenAiApiKeyResolver::resolve();
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'No API key configured.'];
        }

        try {
            $fieldList = $fields->forTypeOrdered($typeId);
            $siteCatalog = (new AiDraftPublishedPagesContext($pdo))->buildSection($t->id);
            $draftBody = $aiDraft->buildDraftBody(
                $apiKey,
                OpenAiApiKeyResolver::activeModel(),
                $t,
                $fieldList,
                $brief,
                $tone,
                $siteCatalog
            );
        } catch (OpenAiException $e) {
            return ['ok' => false, 'error' => 'OpenAI: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not build draft: ' . $e->getMessage()];
        }

        $taxonomies = $taxonomyRepo->forContentTypeOrdered($typeId);
        $result = $entryValidator->validate($draftBody, $t, $fieldList, $entries, $mediaRepo, null);
        $taxResult = $entryTaxonomyValidator->validate($draftBody, $taxonomies, $taxonomyTermRepo);
        $seoParsed = [
            'errors' => [],
            'canonical_url' => null,
            'seo_noindex' => false,
            'og_title' => null,
            'og_description' => null,
            'og_image_id' => null,
            'twitter_title' => null,
            'twitter_description' => null,
            'twitter_image_id' => null,
            'schema_json' => null,
        ];
        if ($t->supportsSeo) {
            $seoParsed = SeoFormParser::parse($draftBody, $mediaRepo);
        }

        $allErrors = array_merge($result['errors'], $taxResult['errors'], $seoParsed['errors']);
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];
        $perms = $cmsUser['permission_slugs'] ?? [];
        if ($allErrors === [] && !$workflow->canTransition(is_array($perms) ? $perms : [], 'draft', $result['values']['status'])) {
            $allErrors['status'] = 'You cannot set this status.';
        }

        if ($allErrors !== []) {
            return ['ok' => false, 'error' => 'Validation: ' . implode(' ', $allErrors)];
        }

        $v = $result['values'];
        $slug = ContentSlugger::ensureUniqueEntry($entries, $typeId, $v['slug']);
        $eid = $entries->insert(
            $typeId,
            $v['title'],
            $slug,
            $v['status'],
            $v['featured_image_id'],
            $v['seo_title'],
            $v['seo_description'],
            $seoParsed['canonical_url'],
            $seoParsed['seo_noindex'],
            $seoParsed['og_title'],
            $seoParsed['og_description'],
            $seoParsed['og_image_id'],
            $seoParsed['twitter_title'],
            $seoParsed['twitter_description'],
            $seoParsed['twitter_image_id'],
            $seoParsed['schema_json'],
            $v['published_at'],
            $cmsUserId($request)
        );
        foreach ($fieldList as $f) {
            $val = $v['custom'][$f->id] ?? null;
            $values->upsert($eid, $f->id, $val);
        }
        EntryTaxonomySync::sync($eid, $taxResult['term_ids'], $entryTaxonomyRepo);
        $row = $entries->fetchRowById($eid);
        if ($row !== null) {
            $entryRevRepo->capture($eid, $row, $values->valuesByFieldIdForEntry($eid), $cmsUserId($request));
        }
        $activity->log($cmsUserId($request), 'content_entry.created', 'content_entry', $eid, [
            'content_type_id' => $typeId,
            'source' => 'ai_blog',
        ]);
        Events::dispatch(new ContentEntrySavedEvent($eid, $typeId, true));

        if ($uid !== null) {
            $aiRateLimiter->recordDraft($uid, $typeId);
        }

        return ['ok' => true, 'entry_id' => $eid, 'type_id' => $typeId];
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $cmsUserId,
        $hasPerm,
        $permView,
        $permCreate,
        $permSettings,
        $jsonResponse,
        $types,
        $settingsRepo,
        $aiChat,
        $aiRateLimiter,
        $runAiDraftCreation,
        $pdo
    ): void {
        $group->get('/tools/ai-blog/api/bootstrap', function (Request $request, Response $response) use (
            $jsonResponse,
            $types,
            $hasPerm,
            $settingsRepo,
            $cmsUserId,
            $aiRateLimiter,
            $pdo
        ): Response {
            $uid = $cmsUserId($request);
            if ($uid !== null) {
                AiBlogChatSession::hydrateFromDatabaseIfEnabled($pdo, $uid);
            }
            $storedKey = trim((string) ($settingsRepo->allKeyValues()['openai_api_key'] ?? '')) !== '';
            $typeRows = [];
            foreach ($types->allOrdered() as $t) {
                $typeRows[] = ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug];
            }
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $usage = $uid !== null ? $aiRateLimiter->totalsForUser($uid) : [
                'chat_24h' => 0,
                'draft_24h' => 0,
                'chat_7d' => 0,
                'draft_7d' => 0,
            ];
            $topUsers = [];
            if ($hasPerm($request, PermissionSlug::MANAGE_SETTINGS)) {
                $topUsers = $aiRateLimiter->topUsers7d(12);
            }

            return $jsonResponse($response, [
                'messages' => AiBlogChatSession::messages(),
                'content_types' => $typeRows,
                'openai_ready' => OpenAiApiKeyResolver::canGenerate(),
                'can_chat' => $hasPerm($request, PermissionSlug::CREATE_CONTENT) && OpenAiApiKeyResolver::canGenerate(),
                'can_create_draft' => $hasPerm($request, PermissionSlug::CREATE_CONTENT) && OpenAiApiKeyResolver::canGenerate(),
                'can_save_settings' => $hasPerm($request, PermissionSlug::MANAGE_SETTINGS),
                'ai_chat_persist' => (Settings::get('ai_chat_persist', '0') ?? '0') === '1',
                'rate_limits' => [
                    'chat_per_hour' => $aiRateLimiter->maxChatsPerHour(),
                    'draft_per_day' => $aiRateLimiter->maxDraftsPerDay(),
                ],
                'usage' => $usage,
                'usage_top_users' => $topUsers,
                'urls' => [
                    'chat' => $parser->urlFor('admin.tools.ai_blog.api.chat'),
                    'clear' => $parser->urlFor('admin.tools.ai_blog.api.clear'),
                    'create_draft' => $parser->urlFor('admin.tools.ai_blog.api.create_draft'),
                    'settings_page' => $parser->urlFor('admin.tools.ai_blog'),
                ],
            ]);
        })->setName('admin.tools.ai_blog.api.bootstrap')->add($permView);

        $group->post('/tools/ai-blog/api/chat', function (Request $request, Response $response) use (
            $jsonResponse,
            $aiChat,
            $cmsUserId,
            $aiRateLimiter,
            $pdo
        ): Response {
            if (!OpenAiApiKeyResolver::canGenerate()) {
                return $jsonResponse($response, ['ok' => false, 'error' => 'OpenAI is not configured or disabled.'], 403);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $msg = isset($body['message']) && is_string($body['message']) ? $body['message'] : '';
            $useStream = !empty($body['stream']) || !empty($body['use_stream']);
            $apiKey = OpenAiApiKeyResolver::resolve();
            if ($apiKey === '') {
                return $jsonResponse($response, ['ok' => false, 'error' => 'No API key.'], 403);
            }
            $uid = $cmsUserId($request);
            if ($uid !== null) {
                try {
                    $aiRateLimiter->assertChatAllowed($uid);
                } catch (AiRateLimitExceededException $e) {
                    return $jsonResponse($response, ['ok' => false, 'error' => $e->getMessage()], 429);
                }
            }

            if ($useStream) {
                $userText = trim(str_replace("\0", '', $msg));
                if ($userText === '') {
                    return $jsonResponse($response, ['ok' => false, 'error' => 'Message is required.'], 422);
                }
                if (mb_strlen($userText) > 8000) {
                    return $jsonResponse($response, ['ok' => false, 'error' => 'Message is too long.'], 422);
                }
                AiBlogChatSession::appendWithPersist($pdo, $uid, 'user', $userText);
                $openAiMessages = AiBlogChatService::openAiMessagesFromSession();
                $onComplete = static function (string $assistantText) use ($pdo, $uid, $aiRateLimiter): array {
                    AiBlogChatSession::appendWithPersist($pdo, $uid, 'assistant', $assistantText);
                    if ($uid !== null) {
                        $aiRateLimiter->recordChat($uid);
                    }

                    return AiBlogChatSession::messages();
                };
                $onError = static function () use ($pdo, $uid): void {
                    AiBlogChatSession::popLastIfRoleWithPersist($pdo, $uid, 'user');
                };
                $processor = new OpenAiChatStreamProcessor(
                    $apiKey,
                    OpenAiApiKeyResolver::activeModel(),
                    $openAiMessages,
                    $onComplete,
                    $onError
                );
                $stream = new ChatSseResponseStream($processor);

                return (new SlimResponse(200))
                    ->withBody($stream)
                    ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
                    ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->withHeader('X-Accel-Buffering', 'no');
            }

            try {
                $aiChat->appendUserMessageAndReply($apiKey, OpenAiApiKeyResolver::activeModel(), $msg, $pdo, $uid);
                if ($uid !== null) {
                    $aiRateLimiter->recordChat($uid);
                }
            } catch (\Throwable $e) {
                return $jsonResponse($response, ['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return $jsonResponse($response, [
                'ok' => true,
                'messages' => AiBlogChatSession::messages(),
            ]);
        })->setName('admin.tools.ai_blog.api.chat')->add($permCreate);

        $group->post('/tools/ai-blog/api/clear', function (Request $request, Response $response) use (
            $jsonResponse,
            $cmsUserId,
            $pdo
        ): Response {
            AiBlogChatSession::clearWithPersist($pdo, $cmsUserId($request));

            return $jsonResponse($response, ['ok' => true, 'messages' => []]);
        })->setName('admin.tools.ai_blog.api.clear')->add($permView);

        $group->post('/tools/ai-blog/api/create-draft', function (Request $request, Response $response) use (
            $jsonResponse,
            $runAiDraftCreation,
            $cmsUserId,
            $pdo
        ): Response {
            if (!OpenAiApiKeyResolver::canGenerate()) {
                return $jsonResponse($response, ['ok' => false, 'error' => 'OpenAI is not configured or disabled.'], 403);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $typeId = isset($body['content_type_id']) && ctype_digit((string) $body['content_type_id'])
                ? (int) $body['content_type_id']
                : 0;
            $tone = isset($body['tone']) && is_string($body['tone']) ? trim($body['tone']) : '';
            $fromChat = !empty($body['from_chat']);
            $brief = isset($body['brief']) && is_string($body['brief']) ? trim($body['brief']) : '';

            if ($fromChat) {
                $brief = AiBlogChatSession::transcriptForDraft();
            }
            if ($brief === '') {
                return $jsonResponse($response, ['ok' => false, 'error' => 'Chat with the assistant first, or provide a brief.'], 422);
            }

            $uid = $cmsUserId($request);

            $result = $runAiDraftCreation($request, $typeId, $brief, $tone);
            if (!$result['ok']) {
                return $jsonResponse($response, ['ok' => false, 'error' => $result['error']], 422);
            }

            AiBlogChatSession::clearWithPersist($pdo, $uid);
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $url = $parser->urlFor('admin.content_types.entries.edit', [
                'id' => (string) $result['type_id'],
                'entryId' => (string) $result['entry_id'],
            ]);

            return $jsonResponse($response, ['ok' => true, 'redirect' => $url]);
        })->setName('admin.tools.ai_blog.api.create_draft')->add($permCreate);

        $group->get('/tools/ai-blog', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $hasPerm,
            $types,
            $settingsRepo,
            $cmsUserId,
            $aiRateLimiter
        ): Response {
            $storedKey = trim((string) ($settingsRepo->allKeyValues()['openai_api_key'] ?? '')) !== '';
            $uid = $cmsUserId($request);
            $usageTotals = $uid !== null ? $aiRateLimiter->totalsForUser($uid) : null;
            $usageTop = $hasPerm($request, PermissionSlug::MANAGE_SETTINGS)
                ? $aiRateLimiter->topUsers7d(12)
                : [];

            return $twig->render($response, 'admin/tools/ai_blog.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'ai_blog',
                'content_types' => $types->allOrdered(),
                'openai_enabled' => OpenAiApiKeyResolver::isEnabledInSettings(),
                'openai_model' => OpenAiApiKeyResolver::storedModel(),
                'openai_key_from_env' => OpenAiApiKeyResolver::hasEnvApiKey(),
                'openai_key_stored' => $storedKey,
                'can_save_openai_settings' => $hasPerm($request, PermissionSlug::MANAGE_SETTINGS),
                'can_generate' => $hasPerm($request, PermissionSlug::CREATE_CONTENT)
                    && OpenAiApiKeyResolver::canGenerate(),
                'ai_rate_chat_per_hour' => (int) (Settings::get('ai_rate_chat_per_hour', '60') ?? '60'),
                'ai_rate_draft_per_day' => (int) (Settings::get('ai_rate_draft_per_day', '40') ?? '40'),
                'ai_chat_persist' => (Settings::get('ai_chat_persist', '0') ?? '0') === '1',
                'ai_chat_retention_days' => (int) (Settings::get('ai_chat_retention_days', '30') ?? '30'),
                'ai_usage_totals' => $usageTotals,
                'ai_usage_top_users' => $usageTop,
            ])));
        })->setName('admin.tools.ai_blog')->add($permView);

        $group->post('/tools/ai-blog/settings', function (Request $request, Response $response) use (
            $settingsRepo,
            $pdo
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $enabled = !empty($body['openai_enabled']) ? '1' : '0';
            $model = trim((string) ($body['openai_model'] ?? ''));
            if ($model === '') {
                $model = 'gpt-4o-mini';
            }
            $settingsRepo->upsert('openai_enabled', $enabled, true);
            $settingsRepo->upsert('openai_model', $model, true);

            $keyRaw = isset($body['openai_api_key']) && is_string($body['openai_api_key'])
                ? trim($body['openai_api_key'])
                : '';
            if ($keyRaw !== '') {
                $settingsRepo->upsert('openai_api_key', $keyRaw, true);
            }
            if (!empty($body['openai_api_key_clear'])) {
                $settingsRepo->upsert('openai_api_key', '', true);
            }

            $rch = isset($body['ai_rate_chat_per_hour']) && is_string($body['ai_rate_chat_per_hour'])
                ? (int) $body['ai_rate_chat_per_hour']
                : 60;
            $rch = max(0, min(500, $rch));
            $settingsRepo->upsert('ai_rate_chat_per_hour', (string) $rch, true);

            $rdd = isset($body['ai_rate_draft_per_day']) && is_string($body['ai_rate_draft_per_day'])
                ? (int) $body['ai_rate_draft_per_day']
                : 40;
            $rdd = max(0, min(200, $rdd));
            $settingsRepo->upsert('ai_rate_draft_per_day', (string) $rdd, true);

            $settingsRepo->upsert('ai_chat_persist', !empty($body['ai_chat_persist']) ? '1' : '0', true);

            $ret = isset($body['ai_chat_retention_days']) && is_string($body['ai_chat_retention_days'])
                ? (int) $body['ai_chat_retention_days']
                : 30;
            $ret = max(0, min(365, $ret));
            $settingsRepo->upsert('ai_chat_retention_days', (string) $ret, true);

            Settings::reload($pdo);
            Flash::set('success', 'AI settings saved.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.ai_blog'))
                ->withStatus(302);
        })->setName('admin.tools.ai_blog.settings')->add($permSettings);

        $group->post('/tools/ai-blog/generate', function (Request $request, Response $response) use (
            $runAiDraftCreation
        ): Response {
            if (!OpenAiApiKeyResolver::canGenerate()) {
                Flash::set('error', 'Turn on OpenAI and add an API key (or set OPENAI_API_KEY in the environment).');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.ai_blog'))
                    ->withStatus(302);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $typeId = isset($body['content_type_id']) && ctype_digit((string) $body['content_type_id'])
                ? (int) $body['content_type_id']
                : 0;
            $brief = isset($body['brief']) && is_string($body['brief']) ? $body['brief'] : '';
            $tone = isset($body['tone']) && is_string($body['tone']) ? trim($body['tone']) : '';

            $result = $runAiDraftCreation($request, $typeId, $brief, $tone);
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            if (!$result['ok']) {
                Flash::set('error', $result['error']);

                return $response
                    ->withHeader('Location', $parser->urlFor('admin.tools.ai_blog'))
                    ->withStatus(302);
            }

            Flash::set('success', 'Draft entry created. Review and publish from the editor.');

            return $response
                ->withHeader(
                    'Location',
                    $parser->urlFor('admin.content_types.entries.edit', [
                        'id' => (string) $result['type_id'],
                        'entryId' => (string) $result['entry_id'],
                    ])
                )
                ->withStatus(302);
        })->setName('admin.tools.ai_blog.generate')->add($permCreate);
    })->add($middleware);
};
